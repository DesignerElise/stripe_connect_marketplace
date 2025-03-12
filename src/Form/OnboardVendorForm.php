<?php

namespace Drupal\stripe_connect_marketplace\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\stripe_connect_marketplace\Service\PaymentService;

/**
 * Form for onboarding vendors to Stripe Connect.
 */
class OnboardVendorForm extends FormBase {

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
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('stripe_connect_marketplace.payment_service')
    );
  }

  /**
   * Constructs a new OnboardVendorForm object.
   *
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
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    PaymentService $payment_service
  ) {
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->paymentService = $payment_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'stripe_connect_marketplace_onboard_vendor_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Check if user is logged in
    if ($this->currentUser->isAnonymous()) {
      $this->messenger()->addError($this->t('You must be logged in to become a vendor.'));
      return $form;
    }
    
    // Load the full user entity
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    
    // Check if user already has a Stripe account
    if ($user->hasField('field_stripe_account_id') && !$user->get('field_stripe_account_id')->isEmpty()) {
      $this->messenger()->addWarning($this->t('You already have a Stripe account connected.'));
      $form['account_exists'] = [
        '#markup' => $this->t('<p>You already have a Stripe Connect account. <a href="@dashboard_url">Go to your vendor dashboard</a>.</p>', [
          '@dashboard_url' => Url::fromRoute('stripe_connect_marketplace.vendor_dashboard')->toString(),
        ]),
      ];
      return $form;
    }
    
    $form['vendor_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Vendor Information'),
      '#description' => $this->t('Please provide the following information to set up your vendor account.'),
    ];
    
    $form['vendor_info']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email Address'),
      '#default_value' => $user->getEmail(),
      '#required' => TRUE,
      '#description' => $this->t('This email will be used for your Stripe account. You can update it later in Stripe.'),
    ];
    
    $form['vendor_info']['country'] = [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#options' => [
        'US' => $this->t('United States'),
        'CA' => $this->t('Canada'),
        'GB' => $this->t('United Kingdom'),
        'AU' => $this->t('Australia'),
        // Add more countries as needed
      ],
      '#default_value' => 'US',
      '#required' => TRUE,
      '#description' => $this->t('Select the country where your business is located.'),
    ];
    
    $form['vendor_info']['business_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Business Type'),
      '#options' => [
        'individual' => $this->t('Individual'),
        'company' => $this->t('Company'),
      ],
      '#default_value' => 'individual',
      '#required' => TRUE,
      '#description' => $this->t('Select your business structure.'),
    ];
    
    $form['vendor_agreement'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I agree to the <a href="@terms_url" target="_blank">Vendor Terms and Conditions</a> and the <a href="@stripe_url" target="_blank">Stripe Connected Account Agreement</a>.', [
        '@terms_url' => Url::fromRoute('stripe_connect_marketplace.vendor_terms')->toString(),
        '@stripe_url' => 'https://stripe.com/connect-account/legal',
      ]),
      '#required' => TRUE,
    ];
    
    $form['actions'] = [
      '#type' => 'actions',
    ];
    
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Continue to Stripe Onboarding'),
    ];
    
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Check if user is logged in
    if ($this->currentUser->isAnonymous()) {
      $form_state->setError($form, $this->t('You must be logged in to become a vendor.'));
      return;
    }
    
    // Validate email format (although Drupal already does this)
    $email = $form_state->getValue('email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setError($form['vendor_info']['email'], $this->t('Please enter a valid email address.'));
    }
    
    // Check agreement to terms
    if (!$form_state->getValue('vendor_agreement')) {
      $form_state->setError($form['vendor_agreement'], $this->t('You must agree to the terms and conditions.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      // Get form values
      $email = $form_state->getValue('email');
      $country = $form_state->getValue('country');
      $business_type = $form_state->getValue('business_type');
      
      // Load the user
      $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
      
      // Prepare additional account data
      $account_data = [
        'business_type' => $business_type,
      ];
      
      // Create Stripe Connect account
      $account = $this->paymentService->createConnectAccount($email, $country, $account_data);
      
      // Save Stripe account ID to user
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
        return;
      }
      
      // Redirect to the Connect controller to continue onboarding
      $form_state->setRedirect('stripe_connect_marketplace.onboard_vendor');
      
      $this->messenger()->addStatus($this->t('Your Stripe Connect account has been created. You will now be redirected to complete the onboarding process.'));
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating Stripe Connect account: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('An error occurred while setting up your vendor account: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }
}
