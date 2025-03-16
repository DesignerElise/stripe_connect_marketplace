<?php

namespace Drupal\stripe_connect_marketplace\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\stripe_connect_marketplace\StripeApiService;
use Drupal\stripe_connect_marketplace\Service\ApiKeyVerificationService;
use Drupal\stripe_connect_marketplace\Service\AccountVerificationService;

/**
 * Controller for Stripe Connect admin actions.
 */
class AdminActionsController extends ControllerBase {

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
   * The Stripe API service.
   *
   * @var \Drupal\stripe_connect_marketplace\StripeApiService
   */
  protected $stripeApi;

  /**
   * The API key verification service.
   *
   * @var \Drupal\stripe_connect_marketplace\Service\ApiKeyVerificationService
   */
  protected $apiKeyVerification;

  /**
   * The account verification service.
   *
   * @var \Drupal\stripe_connect_marketplace\Service\AccountVerificationService
   */
  protected $accountVerification;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('stripe_connect_marketplace.api'),
      $container->get('stripe_connect_marketplace.api_key_verification'),
      $container->get('stripe_connect_marketplace.account_verification')
    );
  }

  /**
   * Constructs a new AdminActionsController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\stripe_connect_marketplace\StripeApiService $stripe_api
   *   The Stripe API service.
   * @param \Drupal\stripe_connect_marketplace\Service\ApiKeyVerificationService $api_key_verification
   *   The API key verification service.
   * @param \Drupal\stripe_connect_marketplace\Service\AccountVerificationService $account_verification
   *   The account verification service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    StripeApiService $stripe_api,
    ApiKeyVerificationService $api_key_verification,
    AccountVerificationService $account_verification
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->stripeApi = $stripe_api;
    $this->apiKeyVerification = $api_key_verification;
    $this->accountVerification = $account_verification;
  }

  /**
   * Verifies API keys and redirects back to dashboard.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function verifyApiKeys() {
    // Check permission
    if (!$this->currentUser()->hasPermission('administer stripe connect')) {
      $this->messenger()->addError($this->t('You do not have permission to perform this action.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    try {
      // Verify the API keys
      $result = $this->apiKeyVerification->verifyApiKeys();
      
      if ($result) {
        $this->messenger()->addStatus($this->t('Stripe API keys were successfully verified.'));
      }
      else {
        $this->messenger()->addError($this->t('Stripe API key verification failed. Please check your API keys in the configuration.'));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error verifying API keys: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
    
    // Redirect back to the admin dashboard
    return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.admin_dashboard')->toString());
  }

  /**
   * Verifies all vendor accounts using a batch process.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function verifyAllAccounts() {
    // Check permission
    if (!$this->currentUser()->hasPermission('administer stripe connect')) {
      $this->messenger()->addError($this->t('You do not have permission to perform this action.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Set up the batch operation
    $batch = [
      'title' => $this->t('Verifying Stripe Connect accounts'),
      'operations' => [
        ['Drupal\stripe_connect_marketplace\Controller\AdminActionsController::verifyAccountsBatch', []],
      ],
      'finished' => 'Drupal\stripe_connect_marketplace\Controller\AdminActionsController::verifyAccountsFinished',
      'progress_message' => $this->t('Processed @current out of @total vendors.'),
    ];
    
    batch_set($batch);
    
    return batch_process(Url::fromRoute('stripe_connect_marketplace.admin_dashboard')->toString());
  }

  /**
   * Batch operation callback for verifying Stripe accounts.
   *
   * @param array $context
   *   The batch context.
   */
  public static function verifyAccountsBatch(&$context) {
    // Initialize batch if needed
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      
      // Count vendors with Stripe accounts
      $query = \Drupal::entityTypeManager()->getStorage('user')->getQuery();
      $query->condition('field_stripe_account_id', '', '<>');
      $query->condition('status', 1);
      $context['sandbox']['max'] = $query->count()->execute();
      $context['sandbox']['current_id'] = 0;
      
      // Initialize stats
      $context['results']['processed'] = 0;
      $context['results']['updated'] = 0;
      $context['results']['deleted'] = 0;
      $context['results']['errors'] = 0;
    }
    
    // Process a batch of vendors
    $batch_size = 10;
    
    $query = \Drupal::entityTypeManager()->getStorage('user')->getQuery();
    $query->condition('field_stripe_account_id', '', '<>');
    $query->condition('status', 1);
    $query->condition('uid', $context['sandbox']['current_id'], '>');
    $query->sort('uid', 'ASC');
    $query->range(0, $batch_size);
    $uids = $query->execute();
    
    if (empty($uids)) {
      $context['finished'] = 1;
      return;
    }
    
    // Get the services needed
    $account_verification = \Drupal::service('stripe_connect_marketplace.account_verification');
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple($uids);
    $logger = \Drupal::logger('stripe_connect_marketplace');
    
    // Process each user
    foreach ($users as $user) {
      $context['sandbox']['current_id'] = $user->id();
      $context['sandbox']['progress']++;
      $context['results']['processed']++;
      
      if (!$user->hasField('field_stripe_account_id') || $user->get('field_stripe_account_id')->isEmpty()) {
        continue;
      }
      
      $account_id = $user->get('field_stripe_account_id')->value;
      
      try {
        // Try to retrieve the account
        $account = \Drupal::service('stripe_connect_marketplace.api')->retrieve('Account', $account_id);
        
        // Update status based on account details
        if ($user->hasField('field_vendor_status')) {
          $current_status = $user->get('field_vendor_status')->value;
          $new_status = $current_status;
          
          if ($account->charges_enabled && $account->payouts_enabled && $account->details_submitted) {
            $new_status = 'active';
          }
          elseif (!$account->details_submitted) {
            $new_status = 'pending';
          }
          
          // Only update if status changed
          if ($new_status !== $current_status) {
            $user->set('field_vendor_status', $new_status);
            $user->save();
            
            $logger->info('Batch updated vendor @uid status from @old to @new', [
              '@uid' => $user->id(),
              '@old' => $current_status,
              '@new' => $new_status,
            ]);
            
            $context['results']['updated']++;
          }
        }
      }
      catch (\Exception $e) {
        // Check if this is an account not found error
        if (strpos($e->getMessage(), 'No such account') !== FALSE ||
            strpos($e->getMessage(), 'resource_missing') !== FALSE) {
          
          // Mark account as deleted
          if ($user->hasField('field_vendor_status')) {
            $user->set('field_vendor_status', 'deleted');
            $user->save();
            
            $logger->warning('Batch process: Marked vendor @uid as deleted (account @account_id)', [
              '@uid' => $user->id(),
              '@account_id' => $account_id,
            ]);
            
            $context['results']['deleted']++;
          }
        }
        else {
          $logger->error('Batch process error for vendor @uid: @message', [
            '@uid' => $user->id(),
            '@message' => $e->getMessage(),
          ]);
          
          $context['results']['errors']++;
        }
      }
    }
    
    // Check if we're finished
    if ($context['sandbox']['progress'] >= $context['sandbox']['max']) {
      $context['finished'] = 1;
    }
    else {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }
  }

  /**
   * Batch finished callback for account verification.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   The batch results.
   * @param array $operations
   *   The operations that were processed.
   */
  public static function verifyAccountsFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();
    
    if ($success) {
      // Report results
      $messenger->addStatus(t('Verified @count vendor accounts:', [
        '@count' => $results['processed'],
      ]));
      
      $messenger->addStatus(t('- @count accounts updated', [
        '@count' => $results['updated'],
      ]));
      
      if ($results['deleted'] > 0) {
        $messenger->addWarning(t('- @count accounts marked as deleted', [
          '@count' => $results['deleted'],
        ]));
      }
      
      if ($results['errors'] > 0) {
        $messenger->addError(t('- @count errors encountered', [
          '@count' => $results['errors'],
        ]));
      }
    }
    else {
      $messenger->addError(t('An error occurred during vendor account verification.'));
    }
  }

/**
   * Converts a regular user to a vendor.
   *
   * @param int $user
   *   The user ID to convert.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response.
   */
  public function makeVendor($user) {
    // Check permission
    if (!$this->currentUser()->hasPermission('administer stripe connect')) {
      $this->messenger()->addError($this->t('You do not have permission to perform this action.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    try {
      // Load the user
      $account = $this->entityTypeManager->getStorage('user')->load($user);
      
      if (!$account) {
        $this->messenger()->addError($this->t('User not found.'));
        return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.admin_dashboard')->toString());
      }
      
      // Check if user already has the vendor role
      if ($account->hasRole('vendor')) {
        $this->messenger()->addWarning($this->t('User @name is already a vendor.', [
          '@name' => $account->getDisplayName(),
        ]));
        return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.admin_dashboard')->toString());
      }
      
      // Add the vendor role
      $account->addRole('vendor');
      $account->save();
      
      // Log the action
      SafeLogging::log($this->logger, 'User @name (@uid) was made a vendor by admin @admin.', [
        '@name' => $account->getDisplayName(),
        '@uid' => $account->id(),
        '@admin' => $this->currentUser()->getDisplayName(),
      ]);
      
      // Show success message
      $this->messenger()->addStatus($this->t('User @name is now a vendor.', [
        '@name' => $account->getDisplayName(),
      ]));
      
      // Redirect to onboarding if needed
      if (!$account->hasField('field_stripe_account_id') || $account->get('field_stripe_account_id')->isEmpty()) {
        $this->messenger()->addStatus($this->t('The vendor needs to connect a Stripe account.'));
        return new RedirectResponse(Url::fromRoute('entity.user.edit_form', ['user' => $account->id()])->toString());
      }
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error making user a vendor: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An error occurred: @error', ['@error' => $e->getMessage()]));
    }
    
    // Return to admin dashboard
    return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.admin_dashboard')->toString());
  }

}
