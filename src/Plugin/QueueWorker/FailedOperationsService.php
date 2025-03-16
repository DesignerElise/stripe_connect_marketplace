<?php

namespace Drupal\stripe_connect_marketplace\Service;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\stripe_connect_marketplace\Utility\SafeLogging;

/**
 * Service for handling failed Stripe operations that need retry.
 */
class FailedOperationsService {
  use StringTranslationTrait;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The queue name for failed operations.
   */
  const QUEUE_NAME = 'stripe_connect_failed_operations';

  /**
   * Constructs a new FailedOperationsService.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(
    QueueFactory $queue_factory,
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->queueFactory = $queue_factory;
    $this->state = $state;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
  }

  /**
   * Queue a failed operation for retry.
   *
   * @param string $operation
   *   The operation type (e.g., 'payment', 'payout', etc.)
   * @param array $data
   *   The data needed to retry the operation.
   * @param string $error
   *   The error that occurred.
   * @param int $max_retries
   *   Maximum retry attempts, defaults to 3.
   */
  public function queueFailedOperation($operation, array $data, $error, $max_retries = 3) {
    // Create a unique identifier for this operation
    $id = md5($operation . serialize($data) . microtime(TRUE));
    
    // Add metadata about the failure
    $item = [
      'id' => $id,
      'operation' => $operation,
      'data' => $data,
      'error' => $error,
      'created' => time(),
      'attempts' => 0,
      'max_retries' => $max_retries,
      'next_retry' => time() + 900, // 15 minutes from now
    ];
    
    // Get the queue
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    
    // Add item to the queue
    $queue->createItem($item);
    
    // Track the queued operations for reporting
    $queued_ops = $this->state->get('stripe_connect_marketplace.queued_operations', []);
    $queued_ops[$id] = $item;
    $this->state->set('stripe_connect_marketplace.queued_operations', $queued_ops);
    
    SafeLogging::log($this->logger,'Queued failed @operation for retry. Error: @error', [
      '@operation' => $operation,
      '@error' => $error,
    ]);
  }

  /**
   * Processes the oldest items in the failed operations queue.
   *
   * @param int $limit
   *   Maximum number of items to process.
   * 
   * @return array
   *   Statistics about processed operations.
   */
  public function processQueue($limit = 5) {
    $stats = [
      'processed' => 0,
      'succeeded' => 0,
      'failed' => 0,
      'requeued' => 0,
      'dropped' => 0,
    ];
    
    // Get the queue
    $queue = $this->queueFactory->get(self::QUEUE_NAME);
    
    // Get current queue status for reporting
    $stats['remaining'] = $queue->numberOfItems();
    
    // Bail if the queue is empty
    if ($stats['remaining'] == 0) {
      return $stats;
    }
    
    // Get the queued operations record
    $queued_ops = $this->state->get('stripe_connect_marketplace.queued_operations', []);
    
    // Process up to the limit
    for ($i = 0; $i < $limit; $i++) {
      // Get the next item from the queue
      $item = $queue->claimItem();
      
      if (!$item) {
        break;
      }
      
      $stats['processed']++;
      
      // Only process if it's time for retry
      $data = $item->data;
      $current_time = time();
      
      if ($data['next_retry'] > $current_time) {
        // Not time yet, release and skip
        $queue->releaseItem($item);
        continue;
      }
      
      // Try to process the operation
      $result = $this->processOperation($data);
      
      if ($result) {
        // Success, remove from queue and tracking
        $queue->deleteItem($item);
        if (isset($queued_ops[$data['id']])) {
          unset($queued_ops[$data['id']]);
        }
        $stats['succeeded']++;
      }
      else {
        // Failed again
        $data['attempts']++;
        
        if ($data['attempts'] >= $data['max_retries']) {
          // Too many retries, drop it
          $queue->deleteItem($item);
          SafeLogging::log($this->logger,'Dropped @operation after @attempts failed attempts. Last error: @error', [
            '@operation' => $data['operation'],
            '@attempts' => $data['attempts'],
            '@error' => $data['error'],
          ]);
          $stats['dropped']++;
          
          // Keep record of dropped operations but mark as dropped
          if (isset($queued_ops[$data['id']])) {
            $queued_ops[$data['id']]['status'] = 'dropped';
            $queued_ops[$data['id']]['dropped_at'] = time();
          }
        }
        else {
          // Increase the retry delay exponentially (15min, 1h, 4h)
          $delay = pow(4, $data['attempts']) * 900;
          $data['next_retry'] = time() + $delay;
          
          // Update in the queue
          $queue->deleteItem($item);
          $queue->createItem($data);
          $stats['requeued']++;
          
          // Update tracking
          if (isset($queued_ops[$data['id']])) {
            $queued_ops[$data['id']] = $data;
          }
          
          SafeLogging::log($this->logger,'Requeued @operation for retry attempt @attempt of @max. Next retry at @time.', [
            '@operation' => $data['operation'],
            '@attempt' => $data['attempts'] + 1,
            '@max' => $data['max_retries'],
            '@time' => date('Y-m-d H:i:s', $data['next_retry']),
          ]);
        }
      }
    }
    
    // Update the queued operations record
    $this->state->set('stripe_connect_marketplace.queued_operations', $queued_ops);
    
    // Get updated count
    $stats['remaining'] = $queue->numberOfItems();
    
    return $stats;
  }

  /**
   * Process a specific operation.
   *
   * @param array $data
   *   The operation data.
   *
   * @return bool
   *   TRUE if operation succeeded, FALSE if it failed.
   */
  protected function processOperation(array $data) {
    $operation = $data['operation'];
    
    try {
      switch ($operation) {
        case 'payment':
          // Implement retry logic for payments
          return $this->retryPayment($data['data']);
          
        case 'payout':
          // Implement retry logic for payouts
          return $this->retryPayout($data['data']);
          
        case 'account_verification':
          // Implement retry logic for account verification
          return $this->retryAccountVerification($data['data']);
          
        default:
          SafeLogging::log($this->logger,'Unknown operation type: @type', [
            '@type' => $operation,
          ]);
          return FALSE;
      }
    }
    catch (\Exception $e) {
      // Update the error message for future retries
      $data['error'] = $e->getMessage();
      SafeLogging::log($this->logger,'Error during retry of @operation: @message', [
        '@operation' => $operation,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Retry a payment operation.
   *
   * @param array $data
   *   The payment data.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  protected function retryPayment(array $data) {
    // This would need to be implemented based on your specific payment flow
    // For example, you might call the payment service to retry creating a payment
    
    SafeLogging::log($this->logger,'Payment retry implementation required for ID: @id', [
      '@id' => $data['payment_id'] ?? 'unknown',
    ]);
    
    return FALSE;
  }

  /**
   * Retry a payout operation.
   *
   * @param array $data
   *   The payout data.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  protected function retryPayout(array $data) {
    // This would need to be implemented based on your specific payout flow
    
    SafeLogging::log($this->logger,'Payout retry implementation required for account: @account', [
      '@account' => $data['account_id'] ?? 'unknown',
    ]);
    
    return FALSE;
  }

  /**
   * Retry account verification.
   *
   * @param array $data
   *   The account data.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  protected function retryAccountVerification(array $data) {
    // Find the account verification service and retry verifying the account
    
    SafeLogging::log($this->logger,'Account verification retry implementation required for: @account', [
      '@account' => $data['account_id'] ?? 'unknown',
    ]);
    
    return FALSE;
  }
}
