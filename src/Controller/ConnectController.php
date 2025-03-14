<?php

namespace Drupal\stripe_connect_marketplace\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\stripe_connect_marketplace\Service\PaymentService;

/**
 * Controller for handling Stripe Connect onboarding flow.
 */
class ConnectController extends ControllerBase {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The payment service.
   *
   * @var \Drupal\stripe_connect_marketplace\Service\PaymentService
   */
  protected $paymentService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('stripe_connect_marketplace.payment_service')
    );
  }

  /**
   * Constructs a new ConnectController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\stripe_connect_marketplace\Service\PaymentService $payment_service
   *   The payment service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    PaymentService $payment_service
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->paymentService = $payment_service;
  }

  /**
   * Initiates the Stripe Connect onboarding process for a vendor.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the Stripe Connect onboarding URL.
   */
  public function onboardVendor() {
    try {
      // Check if user is logged in
      if ($this->currentUser->isAnonymous()) {
        $this->messenger()->addError($this->t('You must be logged in to become a vendor.'));
        return new RedirectResponse(Url::fromRoute('user.login')->toString());
      }
      
      // Load the full user entity
      $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      
      // Check if user already has a Stripe account
      if ($user->hasField('field_stripe_account_id') && !$user->get('field_stripe_account_id')->isEmpty()) {
        $this->messenger()->addWarning($this->t('You already have a Stripe account connected.'));
        return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.vendor_dashboard')->toString());
      }
      
      // Get the user's email
      $email = $user->getEmail();
      
      // Create a Stripe Connect account with enhanced capabilities for direct charges
      $account = $this->paymentService->createConnectAccount($email, 'US', [
        'capabilities' => [
          'card_payments' => ['requested' => true],
          'transfers' => ['requested' => true],
          // Add any other capabilities needed for your marketplace
        ],
        'business_profile' => [
          'mcc' => '5734', // Computer Software Stores (default, can be changed)
          'url' => \Drupal::request()->getSchemeAndHttpHost(),
        ],
      ]);
      
      // Save the Stripe account ID to the user
      if ($user->hasField('field_stripe_account_id')) {
        $user->set('field_stripe_account_id', $account->id);
        $user->save();
        
        $this->logger->info('Created Stripe Connect account for user @uid: @account_id', [
          '@uid' => $user->id(),
          '@account_id' => $account->id,
        ]);
      }
      else {
        $this->logger->warning('User entity does not have field_stripe_account_id field');
        $this->messenger()->addError($this->t('Unable to save Stripe account information.'));
        return new RedirectResponse(Url::fromRoute('<front>')->toString());
      }
      
      // Generate the onboarding URL
      $refresh_url = Url::fromRoute('stripe_connect_marketplace.onboard_vendor')
        ->setAbsolute()
        ->toString();
        
      $return_url = Url::fromRoute('stripe_connect_marketplace.onboard_complete')
        ->setAbsolute()
        ->toString();
      
      // Create an account link for onboarding
      $account_link = $this->paymentService->createAccountLink($account->id, $refresh_url, $return_url);
      
      // Redirect to the Stripe hosted onboarding page
      return new TrustedRedirectResponse($account_link->url);
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating Stripe Connect account: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An error occurred while setting up your vendor account. Please try again later.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }
  }

  /**
   * Handles the return from Stripe Connect onboarding.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array|RedirectResponse
   *   A render array or redirect response.
   */
  public function onboardingComplete(Request $request) {
    try {
      // Check if user is logged in
      if ($this->currentUser->isAnonymous()) {
        $this->messenger()->addError($this->t('You must be logged in to complete onboarding.'));
        return new RedirectResponse(Url::fromRoute('user.login')->toString());
      }
      
      // Load the full user entity
      $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      
      // Check if user has a Stripe account ID
      if (!$user->hasField('field_stripe_account_id') || $user->get('field_stripe_account_id')->isEmpty()) {
        $this->messenger()->addError($this->t('No Stripe account found. Please start the onboarding process again.'));
        return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.onboard_vendor')->toString());
      }
      
      // Get the Stripe account ID
      $account_id = $user->get('field_stripe_account_id')->value;
      
      // Initialize Stripe API
      $config = $this->configFactory->get('stripe_connect_marketplace.settings');
      $environment = $config->get('stripe_connect.environment');
      $secret_key = $environment == 'live' 
        ? $config->get('stripe_connect.live_secret_key') 
        : $config->get('stripe_connect.test_secret_key');
      
      if (empty($secret_key)) {
        throw new \Exception('Stripe API key is not configured.');
      }
      
      \Stripe\Stripe::setApiKey($secret_key);
      
      // Retrieve the account to check its status
      $account = \Stripe\Account::retrieve($account_id);
      
      // Check if onboarding is complete
      if ($account->details_submitted) {
        // Set a field or role indicating the vendor is active
        if ($user->hasField('field_vendor_status')) {
          $user->set('field_vendor_status', 'active');
          $user->save();
        }
        
        // You might also want to assign a vendor role if you're using role-based permissions
        $vendor_role = 'vendor';
        if (!$user->hasRole($vendor_role)) {
          $user->addRole($vendor_role);
          $user->save();
        }
        
        $this->messenger()->addStatus($this->t('Congratulations! Your vendor account is now set up and ready to receive payments directly through Stripe.'));
      }
      else {
        $this->messenger()->addWarning($this->t('Your Stripe account has been created, but onboarding is not yet complete. Some information may still be needed.'));
      }
      
      // Get marketplace fee for display in the template
      $app_fee_percent = $config->get('stripe_connect.application_fee_percent');
      
      // Display account details
      return [
        '#theme' => 'stripe_connect_onboarding_complete',
        '#account' => [
          'id' => $account->id,
          'charges_enabled' => $account->charges_enabled,
          'payouts_enabled' => $account->payouts_enabled,
          'details_submitted' => $account->details_submitted,
        ],
        '#user' => $user,
        '#config' => [
          'app_fee_percent' => $app_fee_percent,
        ],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Error completing onboarding: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An error occurred while completing your vendor setup. Please try again later.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }
  }

  /**
   * Displays the vendor dashboard page.
   *
   * @return array
   *   A render array.
   */
  public function vendorDashboard() {
    try {
      // Check if user is logged in
      if ($this->currentUser->isAnonymous()) {
        $this->messenger()->addError($this->t('You must be logged in to view your vendor dashboard.'));
        return new RedirectResponse(Url::fromRoute('user.login')->toString());
      }
      
      // Load the full user entity
      $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      
      // Check if user has a Stripe account
      if (!$user->hasField('field_stripe_account_id') || $user->get('field_stripe_account_id')->isEmpty()) {
        return [
          '#markup' => $this->t('You are not yet registered as a vendor. <a href="@url">Click here</a> to set up your vendor account.', [
            '@url' => Url::fromRoute('stripe_connect_marketplace.onboard_vendor')->toString(),
          ]),
        ];
      }
      
      // Get the Stripe account ID
      $account_id = $user->get('field_stripe_account_id')->value;
      
      // Initialize Stripe API
      $config = $this->configFactory->get('stripe_connect_marketplace.settings');
      $environment = $config->get('stripe_connect.environment');
      $secret_key = $environment == 'live' 
        ? $config->get('stripe_connect.live_secret_key') 
        : $config->get('stripe_connect.test_secret_key');
      
      if (empty($secret_key)) {
        throw new \Exception('Stripe API key is not configured.');
      }
      
      \Stripe\Stripe::setApiKey($secret_key);
      
      // Retrieve the account
      $account = \Stripe\Account::retrieve($account_id);
      
      // Get recent payouts
      $payouts = \Stripe\Payout::all(
        ['limit' => 10],
        ['stripe_account' => $account_id]
      );
      
      // Get balance
      $balance = \Stripe\Balance::retrieve(
        [],
        ['stripe_account' => $account_id]
      );
      
      // Format balance data
      $available_balance = [];
      foreach ($balance->available as $amount) {
        $available_balance[] = [
          'amount' => $amount->amount / 100,
          'currency' => strtoupper($amount->currency),
        ];
      }
      
      $pending_balance = [];
      foreach ($balance->pending as $amount) {
        $pending_balance[] = [
          'amount' => $amount->amount / 100,
          'currency' => strtoupper($amount->currency),
        ];
      }
      
      // Get link to Stripe dashboard
      $dashboard_link = \Stripe\Account::createLoginLink($account_id);
      
      // Get application fee percentage for the template
      $app_fee_percent = $config->get('stripe_connect.application_fee_percent');
      
      return [
        '#theme' => 'stripe_connect_vendor_dashboard',
        '#account' => [
          'id' => $account->id,
          'charges_enabled' => $account->charges_enabled,
          'payouts_enabled' => $account->payouts_enabled,
          'details_submitted' => $account->details_submitted,
          'dashboard_url' => $dashboard_link->url,
        ],
        '#balance' => [
          'available' => $available_balance,
          'pending' => $pending_balance,
        ],
        '#payouts' => $payouts->data,
        '#user' => $user,
        '#config' => [
          'stripe_connect' => [
            'application_fee_percent' => $app_fee_percent,
          ],
        ],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Error displaying vendor dashboard: @message', ['@message' => $e->getMessage()]);
      return [
        '#markup' => $this->t('An error occurred while loading your vendor dashboard. Please try again later.'),
      ];
    }
  }

  /**
   * Displays the admin dashboard for Stripe Connect vendors.
   *
   * @return array
   *   A render array.
   */
  public function adminDashboard() {
    try {
      // Check access permission
      if (!$this->currentUser->hasPermission('administer stripe connect')) {
        $this->messenger()->addError($this->t('You do not have permission to access this page.'));
        return new RedirectResponse(Url::fromRoute('<front>')->toString());
      }
      
      // Initialize Stripe API
      $config = $this->configFactory->get('stripe_connect_marketplace.settings');
      $environment = $config->get('stripe_connect.environment');
      $secret_key = $environment == 'live' 
        ? $config->get('stripe_connect.live_secret_key') 
        : $config->get('stripe_connect.test_secret_key');
      
      if (empty($secret_key)) {
        throw new \Exception('Stripe API key is not configured.');
      }
      
      \Stripe\Stripe::setApiKey($secret_key);
      
      // Query users with Stripe accounts
      $query = $this->entityTypeManager->getStorage('user')->getQuery();
      $query->condition('field_stripe_account_id', '', '<>');
      $query->condition('status', 1);
      $uids = $query->execute();
      
      // Load users
      $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
      
      // Collect vendor data
      $vendors = [];
      foreach ($users as $user) {
        $account_id = $user->get('field_stripe_account_id')->value;
        
        try {
          // Retrieve the account
          $account = \Stripe\Account::retrieve($account_id);
          
          $vendors[] = [
            'uid' => $user->id(),
            'name' => $user->getDisplayName(),
            'email' => $user->getEmail(),
            'account_id' => $account_id,
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
            'details_submitted' => $account->details_submitted,
            'created' => $account->created,
          ];
        }
        catch (\Exception $e) {
          $this->logger->warning('Error retrieving Stripe account @id for user @uid: @message', [
            '@id' => $account_id,
            '@uid' => $user->id(),
            '@message' => $e->getMessage(),
          ]);
          
          $vendors[] = [
            'uid' => $user->id(),
            'name' => $user->getDisplayName(),
            'email' => $user->getEmail(),
            'account_id' => $account_id,
            'error' => $e->getMessage(),
          ];
        }
      }
      
      // Get platform account balance
      $balance = \Stripe\Balance::retrieve();
      
      // Format balance data
      $available_balance = [];
      foreach ($balance->available as $amount) {
        $available_balance[] = [
          'amount' => $amount->amount / 100,
          'currency' => strtoupper($amount->currency),
        ];
      }
      
      $pending_balance = [];
      foreach ($balance->pending as $amount) {
        $pending_balance[] = [
          'amount' => $amount->amount / 100,
          'currency' => strtoupper($amount->currency),
        ];
      }
      
      // Get recent platform payouts
      $payouts = \Stripe\Payout::all(['limit' => 10]);
      
      // Get application fee percentage for the template
      $app_fee_percent = $config->get('stripe_connect.application_fee_percent');
      
      return [
        '#theme' => 'stripe_connect_admin_dashboard',
        '#vendors' => $vendors,
        '#balance' => [
          'available' => $available_balance,
          'pending' => $pending_balance,
        ],
        '#payouts' => $payouts->data,
        '#environment' => $environment,
        '#config' => [
          'stripe_connect' => [
            'application_fee_percent' => $app_fee_percent,
          ],
        ],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Error displaying admin dashboard: @message', ['@message' => $e->getMessage()]);
      return [
        '#markup' => $this->t('An error occurred while loading the admin dashboard. Please try again later.'),
      ];
    }
  }
}
