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
use Drupal\stripe_connect_marketplace\Service\PayoutService;
use Drupal\stripe_connect_marketplace\StripeApiService;
use Drupal\stripe_connect_marketplace\Utility\SafeLogging;


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
   * The payout service.
   *
   * @var \Drupal\stripe_connect_marketplace\Service\PayoutService
   */
  protected $payoutService;

  /**
   * The Stripe API service.
   *
   * @var \Drupal\stripe_connect_marketplace\StripeApiService
   */
  protected $stripeApi;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('stripe_connect_marketplace.payment_service'),
      $container->get('stripe_connect_marketplace.payout_service'),
      $container->get('stripe_connect_marketplace.api')
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
   * @param \Drupal\stripe_connect_marketplace\Service\PayoutService $payout_service
   *   The payout service.
   * @param \Drupal\stripe_connect_marketplace\StripeApiService $stripe_api
   *   The Stripe API service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    PaymentService $payment_service,
    PayoutService $payout_service,
    StripeApiService $stripe_api
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->paymentService = $payment_service;
    $this->payoutService = $payout_service;
    $this->stripeApi = $stripe_api;
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
        $account_id = $user->get('field_stripe_account_id')->value;
        
        // Check if onboarding is complete
        try {
          $account = $this->stripeApi->retrieve('Account', $account_id);
          
          // If onboarding is complete, redirect to dashboard
          if ($account->details_submitted && $account->charges_enabled && $account->payouts_enabled) {
            $this->messenger()->addWarning($this->t('Your Stripe account is already fully set up.'));
            return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.vendor_dashboard')->toString());
          }
          
          // Otherwise, continue with onboarding by creating a new account link
          $refresh_url = Url::fromRoute('stripe_connect_marketplace.onboard_vendor')
            ->setAbsolute()
            ->toString();
            
          $return_url = Url::fromRoute('stripe_connect_marketplace.onboard_complete')
            ->setAbsolute()
            ->toString();
          
          // Create an account link for continuing onboarding
          $account_link = $this->stripeApi->create('AccountLink', [
            'account' => $account_id,
            'refresh_url' => $refresh_url,
            'return_url' => $return_url,
            'type' => 'account_onboarding',
          ]);
          
          // Redirect to Stripe hosted onboarding page
          return new TrustedRedirectResponse($account_link->url);
        }
        catch (\Exception $e) {
          SafeLogging::log($this->logger, 'Error checking Stripe account status: @message', ['@message' => $e->getMessage()]);
        }
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
        
        SafeLogging::log($this->logger, 'Created Stripe Connect account for user @uid: @account_id', [
          '@uid' => $user->id(),
          '@account_id' => $account->id,
        ]);
      }
      else {
        SafeLogging::log($this->logger, 'User entity does not have field_stripe_account_id field');
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
      SafeLogging::log($this->logger, 'Error creating Stripe Connect account: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An error occurred while setting up your vendor account: @error', [
        '@error' => $e->getMessage(),
      ]));
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
      
      // Get config
      $config = $this->configFactory->get('stripe_connect_marketplace.settings');
      
      // Retrieve the account to check its status 
      $account = $this->stripeApi->retrieve('Account', $account_id);
      
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
      
      // Display account details with stripe_connect settings
      return [
        '#theme' => 'stripe_connect_onboarding_complete',
        '#account' => [
          'id' => $account->id,
          'charges_enabled' => $account->charges_enabled,
          'payouts_enabled' => $account->payouts_enabled,
          'details_submitted' => $account->details_submitted,
        ],
        '#user' => $user,
        '#stripe_connect' => [
          'application_fee_percent' => $config->get('stripe_connect.application_fee_percent'),
        ],
      ];
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error completing onboarding: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An error occurred while completing your vendor setup. Please try again later.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }
  }

  /**
   * Displays the vendor dashboard page.
   *
   * @return array|RedirectResponse
   *   A render array or redirect response.
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
      
      // Check if the vendor status is marked as 'deleted'
      if ($user->hasField('field_vendor_status') && $user->get('field_vendor_status')->value == 'deleted') {
        $this->messenger()->addError($this->t('Your Stripe account appears to have been deleted or is no longer accessible. Please contact the marketplace administrator.'));
        return [
          '#markup' => $this->t('
            <div class="account-deleted-message">
              <h2>@title</h2>
              <p>@message</p>
              <p>@help</p>
            </div>', 
            [
              '@title' => $this->t('Stripe Account No Longer Available'),
              '@message' => $this->t('Your Stripe Connect account is no longer available. This could happen if you deleted your account directly through Stripe.'),
              '@help' => $this->t('To resume vendor operations, please contact the site administrator to set up a new account.'),
            ]
          ),
        ];
      }
      
      // Get config
      $config = $this->configFactory->get('stripe_connect_marketplace.settings');
      
      // Prepare options for connected account operations
      $options = ['stripe_account' => $account_id];
      
      try {
        // Retrieve the account using the StripeApi service
        $account = $this->stripeApi->retrieve('Account', $account_id);
        
        // Get recent payouts
        $payouts = $this->payoutService->getVendorPayouts($account_id, 10);
        
        // Get balance
        $balance = $this->stripeApi->retrieve('Balance', null, [], $options);
        
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
        
        // Get appropriate link based on account status
        if ($account->details_submitted && $account->charges_enabled) {
          // Create login link for complete accounts
          try {
            $dashboard_link = $this->stripeApi->create('LoginLink', [
              'account' => $account_id,
            ]);
            $dashboard_url = $dashboard_link->url;
          }
          catch (\Exception $e) {
            SafeLogging::log($this->logger, 'Error creating dashboard link: @message', ['@message' => $e->getMessage()]);
            $dashboard_url = null;
          }
        }
        else {
          // Create onboarding link for incomplete accounts
          try {
            $refresh_url = Url::fromRoute('stripe_connect_marketplace.vendor_dashboard')
              ->setAbsolute()
              ->toString();
              
            $return_url = Url::fromRoute('stripe_connect_marketplace.onboard_complete')
              ->setAbsolute()
              ->toString();
            
            $onboarding_link = $this->stripeApi->create('AccountLink', [
              'account' => $account_id,
              'refresh_url' => $refresh_url,
              'return_url' => $return_url,
              'type' => 'account_onboarding',
            ]);
            
            $dashboard_url = $onboarding_link->url;
          }
          catch (\Exception $e) {
            SafeLogging::log($this->logger, 'Error creating onboarding link: @message', ['@message' => $e->getMessage()]);
            $dashboard_url = null;
          }
        }
        
        // Generate vendor links
        $vendor_links = $this->getVendorLinks($user);
        
        // Generate action buttons
        $action_buttons = $this->getVendorActionButtons($user);
        
        return [
          '#theme' => 'stripe_connect_vendor_dashboard',
          '#account' => [
            'id' => $account->id,
            'charges_enabled' => $account->charges_enabled,
            'payouts_enabled' => $account->payouts_enabled,
            'details_submitted' => $account->details_submitted,
            'dashboard_url' => $dashboard_url,
          ],
          '#balance' => [
            'available' => $available_balance,
            'pending' => $pending_balance,
          ],
          '#payouts' => $payouts->data,
          '#user' => $user,
          '#action_buttons' => $action_buttons,
          '#vendor_links' => $vendor_links,
          '#stripe_connect' => [
            'application_fee_percent' => $config->get('stripe_connect.application_fee_percent'),
          ],
          '#attached' => [
            'library' => [
              'stripe_connect_marketplace/vendor_action_buttons',
              'stripe_connect_marketplace/vendor_sidebar',
            ],
          ],
        ];
      }
      catch (\Exception $e) {
        SafeLogging::log($this->logger, 'Error displaying vendor dashboard: @message', ['@message' => $e->getMessage()]);
        return [
          '#markup' => $this->t('An error occurred while loading your vendor dashboard. Please try again later.'),
        ];
      }
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error displaying vendor dashboard: @message', ['@message' => $e->getMessage()]);
      return [
        '#markup' => $this->t('An error occurred while loading your vendor dashboard. Please try again later.'),
      ];
    }
  }

  /**
   * Gets store IDs for a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user entity.
   *
   * @return array
   *   Array of store IDs owned by the user.
   */
  protected function getUserStoreIds($user) {
    $query = $this->entityTypeManager->getStorage('commerce_store')->getQuery()
      ->condition('uid', $user->id())
      ->accessCheck(FALSE);
    
    return $query->execute();
  }

  /**
   * Generates vendor links for the dashboard.
   *
   * @param \Drupal\user\UserInterface $user
   *   The vendor user.
   *
   * @return array
   *   Array of URLs for vendor navigation.
   */
  protected function getVendorLinks($user) {
    $links = [];
    
    // Check permissions for store access
    if ($user->hasPermission('view own commerce_store')) {
      $links['stores_url'] = Url::fromRoute('entity.commerce_store.collection')->toString();
    }
    
    // Check permissions for product access
    if ($user->hasPermission('view own commerce_product')) {
      $links['products_url'] = Url::fromRoute('entity.commerce_product.collection')->toString();
    }
    
    // Check permissions for order access
    if ($user->hasPermission('view own commerce_order')) {
      $links['orders_url'] = Url::fromRoute('entity.commerce_order.collection')->toString();
    }
    
    return $links;
  }

  /**
   * Generates action buttons for the vendor dashboard.
   *
   * @param \Drupal\user\UserInterface $user
   *   The vendor user.
   *
   * @return array
   *   Array of button configurations.
   */
  protected function getVendorActionButtons($user) {
    $buttons = [];
    
    // Check if we can add a store
    if ($user->hasPermission('create commerce_store')) {
      $buttons[] = [
        'title' => $this->t('Add Store'),
        'url' => Url::fromRoute('entity.commerce_store.add_page')->toString(),
        'icon' => 'store',
        'button_class' => 'add-store-button',
        'weight' => 10,
      ];
    }
    
    // Get user's stores to check if they have any
    $user_store_ids = $this->getUserStoreIds($user);
    
    if (!empty($user_store_ids)) {
      // Add Product Button if user has at least one store
      if ($user->hasPermission('create commerce_product')) {
        $buttons[] = [
          'title' => $this->t('Add Product'),
          'url' => Url::fromRoute('entity.commerce_product.add_page')->toString(),
          'icon' => 'product',
          'button_class' => 'add-product-button',
          'weight' => 20,
        ];
      }
      
      // Manage Store Button (only show if they have exactly one store)
      if (count($user_store_ids) === 1 && $user->hasPermission('update own commerce_store')) {
        $store_id = reset($user_store_ids);
        $buttons[] = [
          'title' => $this->t('Manage Store'),
          'url' => Url::fromRoute('entity.commerce_store.edit_form', ['commerce_store' => $store_id])->toString(),
          'icon' => 'settings',
          'button_class' => 'manage-store-button',
          'weight' => 30,
        ];
      }
    }
    
    // Sort buttons by weight
    usort($buttons, function($a, $b) {
      return $a['weight'] <=> $b['weight'];
    });
    
    return $buttons;
  }

   /**
   * Displays the vendor terms and conditions page.
   *
   * @return array
   *   A render array containing the vendor terms and conditions.
   */
  public function vendorTerms() {
    return [
      '#theme' => 'stripe_connect_vendor_terms',
      '#attached' => [
        'library' => [
          'stripe_connect_marketplace/vendor_terms',
        ],
      ],
      '#terms' => [
        'marketplace_name' => $this->configFactory->get('system.site')->get('name'),
        'stripe_connect_description' => $this->t('By becoming a vendor, you agree to use our Stripe Connect platform for processing payments.'),
        'key_terms' => [
          $this->t('You will provide accurate business information.'),
          $this->t('You authorize us to collect application fees.'),
          $this->t('You comply with Stripe\'s terms of service.'),
          $this->t('You maintain compliance with all applicable laws and regulations.'),
        ],
        'payout_description' => $this->t('Payouts will be processed according to the schedule set in your Stripe Connect account.'),
        'liability_disclaimer' => $this->t('This marketplace is not responsible for individual transaction disputes.'),
      ],
    ];
  }

  /**
   * Views details of a specific vendor.
   *
   * @param int $user
   *   The user ID of the vendor.
   *
   * @return array|RedirectResponse
   *   A render array with vendor details or redirect response.
   */
  public function viewVendor($user) {
    try {
      // Check access permission
      if (!$this->currentUser->hasPermission('access stripe connect admin')) {
        $this->messenger()->addError($this->t('You do not have permission to access this page.'));
        return new RedirectResponse(Url::fromRoute('<front>')->toString());
      }
      
      // Load the user
      $vendor = $this->entityTypeManager->getStorage('user')->load($user);
      
      if (!$vendor) {
        $this->messenger()->addError($this->t('Vendor not found.'));
        return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.admin_dashboard')->toString());
      }
      
      // Check if vendor has a Stripe account
      if (!$vendor->hasField('field_stripe_account_id') || $vendor->get('field_stripe_account_id')->isEmpty()) {
        $this->messenger()->addWarning($this->t('This vendor does not have a Stripe account connected.'));
        return [
          '#markup' => $this->t('No Stripe account found for this vendor.'),
        ];
      }
      
      // Get the Stripe account ID
      $account_id = $vendor->get('field_stripe_account_id')->value;
      
      // Get config for stripe connect settings
      $config = $this->configFactory->get('stripe_connect_marketplace.settings');
      
      // Prepare options for connected account operations
      $options = ['stripe_account' => $account_id];
      
      // Retrieve the account
      $account = $this->stripeApi->retrieve('Account', $account_id);
      
      // Get recent payouts
      $payouts = $this->payoutService->getVendorPayouts($account_id, 10);
      
      // Get balance
      $balance = $this->stripeApi->retrieve('Balance', null, [], $options);
      
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
      
      return [
        '#theme' => 'stripe_connect_vendor_details',
        '#vendor' => [
          'uid' => $vendor->id(),
          'name' => $vendor->getDisplayName(),
          'email' => $vendor->getEmail(),
        ],
        '#account' => [
          'id' => $account->id,
          'type' => $account->type,
          'charges_enabled' => $account->charges_enabled,
          'payouts_enabled' => $account->payouts_enabled,
          'details_submitted' => $account->details_submitted,
          'created' => $account->created,
        ],
        '#balance' => [
          'available' => $available_balance,
          'pending' => $pending_balance,
        ],
        '#payouts' => $payouts->data,
        '#stripe_connect' => [
          'application_fee_percent' => $config->get('stripe_connect.application_fee_percent'),
        ],
      ];
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error viewing vendor details: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An error occurred while retrieving vendor details.'));
      return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.admin_dashboard')->toString());
    }
  }

  /**
   * Views payouts for a specific vendor.
   *
   * @param int $user
   *   The user ID of the vendor.
   *
   * @return array|RedirectResponse
   *   A render array with vendor payouts or redirect response.
   */
  public function viewVendorPayouts($user) {
    try {
      // Check access permission
      if (!$this->currentUser->hasPermission('access stripe connect admin')) {
        $this->messenger()->addError($this->t('You do not have permission to access this page.'));
        return new RedirectResponse(Url::fromRoute('<front>')->toString());
      }
      
      // Load the user
      $vendor = $this->entityTypeManager->getStorage('user')->load($user);
      
      if (!$vendor) {
        $this->messenger()->addError($this->t('Vendor not found.'));
        return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.admin_dashboard')->toString());
      }
      
      // Check if vendor has a Stripe account
      if (!$vendor->hasField('field_stripe_account_id') || $vendor->get('field_stripe_account_id')->isEmpty()) {
        $this->messenger()->addWarning($this->t('This vendor does not have a Stripe account connected.'));
        return [
          '#markup' => $this->t('No Stripe account found for this vendor.'),
        ];
      }
      
      // Get the Stripe account ID
      $account_id = $vendor->get('field_stripe_account_id')->value;
      
      // Get config
      $config = $this->configFactory->get('stripe_connect_marketplace.settings');
      
      // Get all payouts for this vendor
      $payouts = $this->payoutService->getVendorPayouts($account_id, 50);
      
      return [
        '#theme' => 'stripe_connect_vendor_payouts',
        '#vendor' => [
          'uid' => $vendor->id(),
          'name' => $vendor->getDisplayName(),
          'email' => $vendor->getEmail(),
        ],
        '#payouts' => $payouts->data,
        '#stripe_connect' => [
          'application_fee_percent' => $config->get('stripe_connect.application_fee_percent'),
        ],
      ];
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error viewing vendor payouts: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An error occurred while retrieving vendor payouts.'));
      return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.admin_dashboard')->toString());
    }
  }

  /**
   * Displays the admin dashboard for Stripe Connect vendors.
   *
   * @return array|RedirectResponse
   *   A render array or redirect response.
   */
  public function adminDashboard() {
    try {
      // Check access permission
      if (!$this->currentUser->hasPermission('administer stripe connect')) {
        $this->messenger()->addError($this->t('You do not have permission to access this page.'));
        return new RedirectResponse(Url::fromRoute('<front>')->toString());
      }
      
      // Get config
      $config = $this->configFactory->get('stripe_connect_marketplace.settings');
      $environment = $config->get('stripe_connect.environment');
      
      // Query users with Stripe accounts
      $query = $this->entityTypeManager->getStorage('user')->getQuery();
      $query->condition('field_stripe_account_id', '', '<>');
      $query->condition('status', 1);
      $query->accessCheck(FALSE);
      $uids = $query->execute();
      
      // Load users
      $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);
      
      // Collect vendor data
      $vendors = [];
      foreach ($users as $user) {
        $account_id = $user->get('field_stripe_account_id')->value;
        
        // If vendor status is 'deleted', don't try to fetch from Stripe
        $vendor_deleted = ($user->hasField('field_vendor_status') && 
                          $user->get('field_vendor_status')->value === 'deleted');
        
        if ($vendor_deleted) {
          $vendors[] = [
            'uid' => $user->id(),
            'name' => $user->getDisplayName(),
            'email' => $user->getEmail(),
            'account_id' => $account_id,
            'charges_enabled' => FALSE,
            'payouts_enabled' => FALSE,
            'details_submitted' => FALSE,
            'created' => 0,
            'status' => 'deleted',
          ];
          continue;
        }
        
        try {
          // Retrieve the account
          $account = $this->stripeApi->retrieve('Account', $account_id);
          
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
        catch (\Drupal\stripe_connect_marketplace\Exception\StripeAccountDeletedException $e) {
          // Mark account as deleted
          if ($user->hasField('field_vendor_status')) {
            $user->set('field_vendor_status', 'deleted');
            $user->save();
            
            SafeLogging::log($this->logger, 'Admin dashboard: Detected deleted Stripe account @account_id for user @uid', [
              '@account_id' => $account_id,
              '@uid' => $user->id(),
            ]);
          }
          
          $vendors[] = [
            'uid' => $user->id(),
            'name' => $user->getDisplayName(),
            'email' => $user->getEmail(),
            'account_id' => $account_id,
            'status' => 'deleted',
            'error' => $this->t('Account has been deleted in Stripe'),
          ];
        }
        catch (\Exception $e) {
          SafeLogging::log($this->logger, 'Error retrieving Stripe account @id for user @uid: @message', [
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
      $balance = $this->stripeApi->retrieve('Balance', null);
      
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
      $payouts_data = [];
      $client = $this->stripeApi->getClient();
      
      if ($client) {
        try {
          $payouts = $client->payouts->all(['limit' => 10]);
          $payouts_data = $payouts->data;
        }
        catch (\Exception $e) {
          SafeLogging::log($this->logger, 'Error retrieving payouts: @message', ['@message' => $e->getMessage()]);
          // Create empty payouts data if there's an error
          $payouts_data = [];
        }
      } else {
        // Client is null, likely in test mode with no API keys configured
        // Create mock payouts data for display
        $payouts_data = $this->createMockPayoutsData(10);
        SafeLogging::log($this->logger, 'Using mock payouts data because Stripe client is not available', [], 'warning');
      }
      
      // Get application fee percentage for the template
      $app_fee_percent = $config->get('stripe_connect.application_fee_percent');
      
      
      // Get API key status
      $api_key_status = \Drupal::state()->get('stripe_connect_marketplace.api_key_status', [
        'status' => 'unknown',
        'last_checked' => 0,
      ]);
      
      // Get deleted accounts tracking
      $deleted_accounts = \Drupal::state()->get('stripe_connect_marketplace.deleted_accounts', []);
      
      return [
        '#theme' => 'stripe_connect_admin_dashboard',
        '#vendors' => $vendors,
        '#balance' => [
          'available' => $available_balance,
          'pending' => $pending_balance,
        ],
        '#payouts' => $payouts_data,
        '#environment' => $environment,
        '#api_key_status' => $api_key_status,
        '#deleted_accounts' => $deleted_accounts,
        '#config' => [
          'stripe_connect' => [
            'application_fee_percent' => $app_fee_percent,
          ],
        ],
      ];
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error displaying admin dashboard: @message', ['@message' => $e->getMessage()]);
      return [
        '#markup' => $this->t('An error occurred while loading the admin dashboard. Please try again later.'),
      ];
    }
  }
  /**
   * Creates mock payouts data for testing and display when Stripe is not configured.
   *
   * @param int $count
   *   Number of mock payouts to create.
   *
   * @return array
   *   Array of mock payout objects.
   */
  protected function createMockPayoutsData($count = 5) {
    $payouts = [];
    
    for ($i = 0; $i < $count; $i++) {
      $payout = new \stdClass();
      $payout->id = 'po_mock_' . md5('platform' . $i);
      $payout->object = 'payout';
      $payout->amount = rand(10000, 100000); // Random amount between $100-$1000
      $payout->currency = 'usd';
      $payout->arrival_date = time() - (86400 * $i); // Staggered dates
      $payout->created = time() - (86400 * $i) - 3600;
      $payout->status = rand(0, 5) > 0 ? 'paid' : 'pending'; // Mostly paid, some pending
      $payout->type = 'bank_account';
      $payout->destination = 'ba_mock_account';
      
      $payouts[] = $payout;
    }
    
    return $payouts;
  }

  /**
   * Redirects the vendor to their Stripe Dashboard.
   *
   * This method should be added to the ConnectController class.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the Stripe dashboard or back to vendor dashboard if not available.
   */
  public function stripeDashboardRedirect() {
    try {
      // Check if user is logged in
      if ($this->currentUser->isAnonymous()) {
        $this->messenger()->addError($this->t('You must be logged in to access your Stripe dashboard.'));
        return new RedirectResponse(Url::fromRoute('user.login')->toString());
      }
      
      // Load the full user entity
      $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      
      // Check if user has a Stripe account
      if (!$user->hasField('field_stripe_account_id') || $user->get('field_stripe_account_id')->isEmpty()) {
        $this->messenger()->addWarning($this->t('You do not have a Stripe account. Please set up your vendor account first.'));
        return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.vendor_dashboard')->toString());
      }
      
      // Get the Stripe account ID
      $account_id = $user->get('field_stripe_account_id')->value;
      
      // Check if the vendor status is marked as 'deleted'
      if ($user->hasField('field_vendor_status') && $user->get('field_vendor_status')->value == 'deleted') {
        $this->messenger()->addError($this->t('Your Stripe account appears to have been deleted or is no longer accessible. Please contact the marketplace administrator.'));
        return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.vendor_dashboard')->toString());
      }
      
      // Prepare options for connected account operations
      $options = ['stripe_account' => $account_id];
      
      try {
        // Retrieve the account using the StripeApi service
        $account = $this->stripeApi->retrieve('Account', $account_id);
        
        // Get appropriate link based on account status
        if ($account->details_submitted && $account->charges_enabled) {
          // Create login link for complete accounts
          try {
            $dashboard_link = $this->stripeApi->create('LoginLink', [
              'account' => $account_id,
            ]);
            
            // Redirect directly to Stripe dashboard
            return new TrustedRedirectResponse($dashboard_link->url);
          }
          catch (\Exception $e) {
            SafeLogging::log($this->logger, 'Error creating dashboard link: @message', ['@message' => $e->getMessage()]);
            $this->messenger()->addError($this->t('Unable to access your Stripe dashboard. Please try again later.'));
          }
        }
        else {
          // Create onboarding link for incomplete accounts
          try {
            $refresh_url = Url::fromRoute('stripe_connect_marketplace.vendor_dashboard')
              ->setAbsolute()
              ->toString();
              
            $return_url = Url::fromRoute('stripe_connect_marketplace.onboard_complete')
              ->setAbsolute()
              ->toString();
            
            $onboarding_link = $this->stripeApi->create('AccountLink', [
              'account' => $account_id,
              'refresh_url' => $refresh_url,
              'return_url' => $return_url,
              'type' => 'account_onboarding',
            ]);
            
            // Redirect to complete onboarding
            $this->messenger()->addStatus($this->t('Your account setup needs to be completed before accessing your Stripe dashboard.'));
            return new TrustedRedirectResponse($onboarding_link->url);
          }
          catch (\Exception $e) {
            SafeLogging::log($this->logger, 'Error creating onboarding link: @message', ['@message' => $e->getMessage()]);
            $this->messenger()->addError($this->t('Unable to access your Stripe dashboard. Please try again later.'));
          }
        }
      }
      catch (\Exception $e) {
        SafeLogging::log($this->logger, 'Error accessing Stripe account: @message', ['@message' => $e->getMessage()]);
        $this->messenger()->addError($this->t('Unable to access your Stripe dashboard. Please try again later.'));
      }
      
      // Fallback to vendor dashboard
      return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.vendor_dashboard')->toString());
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error in stripeDashboardRedirect: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An error occurred. Please try again later.'));
      return new RedirectResponse(Url::fromRoute('stripe_connect_marketplace.vendor_dashboard')->toString());
    }
  }
}
