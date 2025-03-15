<?php

namespace Drupal\stripe_connect_marketplace\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\stripe_connect_marketplace\Exception\StripeAccountDeletedException;
use Drupal\stripe_connect_marketplace\Service\AccountVerificationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber for handling Stripe account deletion exceptions.
 */
class AccountDeletionSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The account verification service.
   *
   * @var \Drupal\stripe_connect_marketplace\Service\AccountVerificationService
   */
  protected $accountVerification;

  /**
   * Constructs a new AccountDeletionSubscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\stripe_connect_marketplace\Service\AccountVerificationService $account_verification
   *   The account verification service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    MessengerInterface $messenger,
    AccountVerificationService $account_verification
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->messenger = $messenger;
    $this->accountVerification = $account_verification;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::EXCEPTION => ['onException', 0],
    ];
  }

  /**
   * Handles exceptions to detect and respond to deleted Stripe accounts.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The exception event.
   */
  public function onException(ExceptionEvent $event) {
    $exception = $event->getThrowable();
    
    // Only respond to our specific exception type
    if ($exception instanceof StripeAccountDeletedException) {
      $account_id = $exception->getAccountId();
      
      // Mark the account as deleted and update the vendor status
      $result = $this->accountVerification->markAccountDeleted($account_id);
      
      if ($result) {
        // Display a message to the user if appropriate
        $this->messenger->addWarning(t('The Stripe account associated with this vendor is no longer available. Please contact the site administrator.'));
        
        $this->logger->warning('Caught deleted Stripe account exception and updated vendor status: @account_id', [
          '@account_id' => $account_id,
        ]);
      }
    }
  }
}
