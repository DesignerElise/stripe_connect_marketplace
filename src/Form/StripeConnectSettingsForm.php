<?php

namespace Drupal\stripe_connect_marketplace\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\stripe_connect_marketplace\Utility\SafeLogging;

/**
 * Configure Stripe Connect settings for this site.
 */
class StripeConnectSettingsForm extends ConfigFormBase {

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new StripeConnectSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager = NULL,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'stripe_connect_marketplace_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'stripe_connect_marketplace.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get configuration
    $config = $this->config('stripe_connect_marketplace.settings');
    $stripe_connect = $config->get('stripe_connect') ?: [];
    
    $form['payment_model_info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('
        <div class="payment-model-info">
          <h2>Direct Charges Payment Model</h2>
          <p>This marketplace uses Stripe Connect\'s <strong>Direct Charges</strong> model where:</p>
          <ul>
            <li>Vendors collect payments directly to their Stripe accounts</li>
            <li>Your platform automatically collects an application fee on each transaction</li>
            <li>Vendors receive payouts directly from Stripe based on their payout schedule</li>
          </ul>
        </div>
      '),
    ];
    
    // API Keys fieldset
    $form['api_keys'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Stripe API Keys'),
      '#description' => $this->t('Enter your Stripe API keys. These are used for the platform account that will collect application fees.'),
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
    ];
    
    $form['api_keys']['test_secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Secret Key'),
      '#default_value' => isset($stripe_connect['test_secret_key']) ? $stripe_connect['test_secret_key'] : '',
      '#description' => $this->t('Enter your Stripe test secret key (starts with sk_test_).'),
      '#required' => FALSE,
    ];
    
    $form['api_keys']['test_publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Publishable Key'),
      '#default_value' => isset($stripe_connect['test_publishable_key']) ? $stripe_connect['test_publishable_key'] : '',
      '#description' => $this->t('Enter your Stripe test publishable key (starts with pk_test_).'),
      '#required' => FALSE,
    ];
    
    $form['api_keys']['live_secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Secret Key'),
      '#default_value' => isset($stripe_connect['live_secret_key']) ? $stripe_connect['live_secret_key'] : '',
      '#description' => $this->t('Enter your Stripe live secret key (starts with sk_live_).'),
      '#required' => FALSE,
    ];
    
    $form['api_keys']['live_publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Publishable Key'),
      '#default_value' => isset($stripe_connect['live_publishable_key']) ? $stripe_connect['live_publishable_key'] : '',
      '#description' => $this->t('Enter your Stripe live publishable key (starts with pk_live_).'),
      '#required' => FALSE,
    ];
    
    $form['environment'] = [
      '#type' => 'radios',
      '#title' => $this->t('Environment'),
      '#options' => [
        'test' => $this->t('Test'),
        'live' => $this->t('Live'),
      ],
      '#default_value' => isset($stripe_connect['environment']) ? $stripe_connect['environment'] : 'test',
      '#required' => TRUE,
      '#description' => $this->t('Select the Stripe environment to use. Test mode will not process real payments.'),
    ];
    
    // Application Fee Settings
    $form['application_fee'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Application Fee Settings'),
      '#description' => $this->t('Configure the fee that your platform collects on each transaction.'),
    ];
    
    $form['application_fee']['application_fee_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Application Fee Percentage'),
      '#default_value' => isset($stripe_connect['application_fee_percent']) ? $stripe_connect['application_fee_percent'] : 10,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
      '#required' => TRUE,
      '#description' => $this->t('Enter the percentage of each transaction that your platform will collect as a fee.'),
    ];
    
    // Add fee example calculation
    $current_fee = isset($stripe_connect['application_fee_percent']) ? $stripe_connect['application_fee_percent'] : 10;
    $example_amount = 100;
    $example_fee = ($example_amount * $current_fee / 100);
    
    $form['application_fee']['fee_example'] = [
      '#markup' => $this->t('<div class="fee-example">For example, on a $@amount transaction, the platform fee would be $@fee.</div>', [
        '@amount' => number_format($example_amount, 2),
        '@fee' => number_format($example_fee, 2),
      ]),
    ];
    
    // Webhook Settings
    $form['webhook'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Webhook Settings'),
      '#description' => $this->t('Configure Stripe webhooks to receive real-time notifications.'),
    ];
    
    $form['webhook']['webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook Secret'),
      '#default_value' => isset($stripe_connect['webhook_secret']) ? $stripe_connect['webhook_secret'] : '',
      '#description' => $this->t('Enter your Stripe webhook signing secret.'),
      '#required' => FALSE,
    ];
    
    $form['webhook']['webhook_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook URL'),
      '#value' => \Drupal::request()->getSchemeAndHttpHost() . '/stripe/webhook',
      '#description' => $this->t('Use this URL when configuring webhooks in your Stripe Dashboard.'),
      '#disabled' => TRUE,
    ];
    
    $form['webhook']['webhook_events'] = [
      '#markup' => $this->t('<div class="webhook-events"><strong>Required webhook events:</strong>
        <ul>
          <li>account.updated</li>
          <li>charge.refunded</li>
          <li>payment_intent.succeeded</li>
          <li>payment_intent.payment_failed</li>
          <li>payout.created</li>
          <li>payout.paid</li>
          <li>payout.failed</li>
          <li>application_fee.created</li>
        </ul></div>'),
    ];
    
    // Advanced Settings
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];
    
    $form['advanced']['payout_schedule'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Payout Schedule'),
      '#options' => [
        'automatic' => $this->t('Automatic (Stripe Default)'),
        'manual' => $this->t('Manual (Platform Controlled)'),
      ],
      '#default_value' => isset($stripe_connect['payout_schedule']) ? $stripe_connect['payout_schedule'] : 'automatic',
      '#description' => $this->t('Select the default payout schedule for vendors. Automatic allows Stripe to handle payouts based on the schedule below.'),
    ];
    
    $form['advanced']['payout_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Payout Interval'),
      '#options' => [
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
        'monthly' => $this->t('Monthly'),
      ],
      '#default_value' => isset($stripe_connect['payout_interval']) ? $stripe_connect['payout_interval'] : 'daily',
      '#description' => $this->t('Select the default frequency of payouts for vendors. This can be overridden on a per-vendor basis.'),
      '#states' => [
        'visible' => [
          ':input[name="payout_schedule"]' => ['value' => 'automatic'],
        ],
      ],
    ];
    
    // Add custom JavaScript for dynamic example calculation
    $form['#attached']['library'][] = 'stripe_connect_marketplace/admin_settings';
    
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    
    // Validate test secret key format
    $test_secret_key = $form_state->getValue('test_secret_key');
    if (!empty($test_secret_key) && !preg_match('/^sk_test_/', $test_secret_key)) {
      $form_state->setError($form['api_keys']['test_secret_key'], $this->t('Test secret key must start with sk_test_'));
    }
    
    // Validate test publishable key format
    $test_publishable_key = $form_state->getValue('test_publishable_key');
    if (!empty($test_publishable_key) && !preg_match('/^pk_test_/', $test_publishable_key)) {
      $form_state->setError($form['api_keys']['test_publishable_key'], $this->t('Test publishable key must start with pk_test_'));
    }
    
    // Validate live secret key format
    $live_secret_key = $form_state->getValue('live_secret_key');
    if (!empty($live_secret_key) && !preg_match('/^sk_live_/', $live_secret_key)) {
      $form_state->setError($form['api_keys']['live_secret_key'], $this->t('Live secret key must start with sk_live_'));
    }
    
    // Validate live publishable key format
    $live_publishable_key = $form_state->getValue('live_publishable_key');
    if (!empty($live_publishable_key) && !preg_match('/^pk_live_/', $live_publishable_key)) {
      $form_state->setError($form['api_keys']['live_publishable_key'], $this->t('Live publishable key must start with pk_live_'));
    }
    
    // Validate application fee percentage
    $fee_percent = $form_state->getValue('application_fee_percent');
    if ($fee_percent < 0 || $fee_percent > 100) {
      $form_state->setError($form['application_fee']['application_fee_percent'], $this->t('Application fee percentage must be between 0 and 100.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Get the raw config to preserve any existing values
    $config = $this->config('stripe_connect_marketplace.settings');
    $stripe_connect = $config->get('stripe_connect') ?: [];
    
    // Update with form values
    $stripe_connect['environment'] = $form_state->getValue('environment');
    
    // Save API keys (only if they're not empty)
    $test_secret_key = $form_state->getValue('test_secret_key');
    if (!empty($test_secret_key)) {
      $stripe_connect['test_secret_key'] = $test_secret_key;
    }
    
    $test_publishable_key = $form_state->getValue('test_publishable_key');
    if (!empty($test_publishable_key)) {
      $stripe_connect['test_publishable_key'] = $test_publishable_key;
    }
    
    $live_secret_key = $form_state->getValue('live_secret_key');
    if (!empty($live_secret_key)) {
      $stripe_connect['live_secret_key'] = $live_secret_key;
    }
    
    $live_publishable_key = $form_state->getValue('live_publishable_key');
    if (!empty($live_publishable_key)) {
      $stripe_connect['live_publishable_key'] = $live_publishable_key;
    }
    
    // Save webhook secret
    $webhook_secret = $form_state->getValue('webhook_secret');
    if (!empty($webhook_secret)) {
      $stripe_connect['webhook_secret'] = $webhook_secret;
    }
    
    // Save application fee percentage
    $stripe_connect['application_fee_percent'] = $form_state->getValue('application_fee_percent');
    
    // Save payout settings
    $stripe_connect['payout_schedule'] = $form_state->getValue('payout_schedule');
    $stripe_connect['payout_interval'] = $form_state->getValue('payout_interval');
    
    // Save the updated configuration
    $config->set('stripe_connect', $stripe_connect);
    $config->save();
    
    // Log the saved configuration for debugging
    SafeLogging::log($this->logger,'Saved Stripe Connect configuration with application fee: @fee%', [
      '@fee' => $stripe_connect['application_fee_percent'],
    ]);
    
    parent::submitForm($form, $form_state);
    
    // Add a success message
    $this->messenger()->addStatus($this->t('Stripe Connect settings have been saved. Your marketplace is configured for direct charges with a @fee% application fee.', [
      '@fee' => $stripe_connect['application_fee_percent'],
    ]));
  }
}
