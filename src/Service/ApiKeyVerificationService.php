<?php

namespace Drupal\stripe_connect_marketplace\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\stripe_connect_marketplace\StripeApiService;

/**
 * Service to verify Stripe API keys and notify admins about issues.
 */
class ApiKeyVerificationService {
  use StringTranslationTrait;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Stripe API service.
   *
   * @var \Drupal\stripe_connect_marketplace\StripeApiService
   */
  protected $stripeApi;

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
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ApiKeyVerificationService.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    StripeApiService $stripe_api,
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory,
    MailManagerInterface $mail_manager,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->configFactory = $config_factory;
    $this->stripeApi = $stripe_api;
    $this->state = $state;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->mailManager = $mail_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Verifies Stripe API keys are valid.
   *
   * @return bool
   *   TRUE if the keys are valid, FALSE otherwise.
   */
  public function verifyApiKeys() {
    try {
      // Attempt to get a simple balance call to verify access
      $client = $this->stripeApi->getClient();
      if (!$client) {
        $this->recordKeyFailure('Stripe client initialization failed');
        return FALSE;
      }
      
      // Try to get the balance (a simple API call to verify the keys)
      $balance = $this->stripeApi->retrieve('Balance', null);
      
      // If we reach here, the API keys are valid
      $this->recordKeySuccess();
      return TRUE;
    }
    catch (\Exception $e) {
      $this->recordKeyFailure($e->getMessage());
      return FALSE;
    }
  }

  /**
   * Records successful API key verification.
   */
  protected function recordKeySuccess() {
    $current = $this->state->get('stripe_connect_marketplace.api_key_status', [
      'status' => 'unknown',
      'last_checked' => 0,
      'failures' => 0,
    ]);
    
    // Update the status
    $this->state->set('stripe_connect_marketplace.api_key_status', [
      'status' => 'valid',
      'last_checked' => time(),
      'failures' => 0,
      'last_success' => time(),
    ]);
    
    // If we're recovering from failure, log it
    if ($current['status'] === 'invalid') {
      $this->logger->info('Stripe API keys are now valid again after previous failures.');
    }
  }

  /**
   * Records a failed API key verification.
   *
   * @param string $error_message
   *   The error message.
   */
  protected function recordKeyFailure($error_message) {
    $current = $this->state->get('stripe_connect_marketplace.api_key_status', [
      'status' => 'unknown',
      'last_checked' => 0,
      'failures' => 0,
    ]);
    
    // Increment the failure count
    $failures = $current['failures'] + 1;
    
    // Update the status
    $this->state->set('stripe_connect_marketplace.api_key_status', [
      'status' => 'invalid',
      'last_checked' => time(),
      'failures' => $failures,
      'error' => $error_message,
    ]);
    
    // Log the issue
    $this->logger->error('Stripe API key verification failed (@count consecutive failures): @error', [
      '@count' => $failures,
      '@error' => $error_message,
    ]);
    
    // Notify admins if this is the first failure or after every 10 failures
    if ($failures === 1 || $failures % 10 === 0) {
      $this->notifyAdmins($error_message, $failures);
    }
  }

  /**
   * Notifies admin users about API key issues.
   *
   * @param string $error_message
   *   The error message.
   * @param int $failures
   *   Number of consecutive failures.
   */
  protected function notifyAdmins($error_message, $failures) {
    // Find admin users
    $admin_role = 'administrator';
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['status' => 1, 'roles' => $admin_role]);
    
    if (empty($users)) {
      return;
    }
    
    // Get site name
    $site_name = $this->configFactory->get('system.site')->get('name');
    
    // Prepare email
    $params = [
      'subject' => $this->t('Stripe API Key Issue - @site', ['@site' => $site_name]),
      'message' => $this->t(
        "Stripe API key verification has failed @count times.\n\nError: @error\n\nPlease check your Stripe Connect configuration at: @url",
        [
          '@count' => $failures,
          '@error' => $error_message,
          '@url' => base_path() . 'admin/commerce/config/stripe-connect',
        ]
      ),
    ];
    
    // Send to each admin
    foreach ($users as $account) {
      if ($account->getEmail()) {
        $this->mailManager->mail(
          'stripe_connect_marketplace',
          'api_key_failure',
          $account->getEmail(),
          $account->getPreferredLangcode(),
          $params,
          NULL,
          TRUE
        );
      }
    }
    
    $this->logger->notice('Sent Stripe API key failure notification to @count administrators', [
      '@count' => count($users),
    ]);
  }
}
