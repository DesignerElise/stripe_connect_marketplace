<?php

namespace Drupal\stripe_connect_marketplace\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($config_factory);
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
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
    $config = $this->config('stripe_connect_marketplace.settings');

    $form['stripe_connect'] = [
      '#type' => 'details',
      '#title' => $this->t('Stripe Connect Settings'),
      '#open' => TRUE,
    ];

    // Environment selection
    $form['stripe_connect']['environment'] = [
      '#type' => 'radios',
      '#title' => $this->t('Environment'),
      '#options' => [
        'test' => $this->t('Test'),
        'live' => $this->t('Live'),
      ],
      '#default_value' => $config->get('stripe_connect.environment') ?: 'test',
      '#description' => $this->t('Select the Stripe environment.'),
    ];

    // API Keys
    $form['stripe_connect']['keys'] = [
      '#type' => 'details',
      '#title' => $this->t('API Keys'),
      '#open' => FALSE,
    ];

    $form['stripe_connect']['keys']['test_secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Secret Key'),
      '#default_value' => $this->maskApiKey($config->get('stripe_connect.test_secret_key')),
      '#description' => $this->t('Enter your Stripe test secret key.'),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $form['stripe_connect']['keys']['test_publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Publishable Key'),
      '#default_value' => $this->maskApiKey($config->get('stripe_connect.test_publishable_key')),
      '#description' => $this->t('Enter your Stripe test publishable key.'),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $form['stripe_connect']['keys']['live_secret_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Secret Key'),
      '#default_value' => $this->maskApiKey($config->get('stripe_connect.live_secret_key')),
      '#description' => $this->t('Enter your Stripe live secret key.'),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $form['stripe_connect']['keys']['live_publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live Publishable Key'),
      '#default_value' => $this->maskApiKey($config->get('stripe_connect.live_publishable_key')),
      '#description' => $this->t('Enter your Stripe live publishable key.'),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    // Webhook settings
    $form['stripe_connect']['webhook'] = [
      '#type' => 'details',
      '#title' => $this->t('Webhook Settings'),
      '#open' => FALSE,
    ];

    $webhook_url = $this->generateWebhookUrl();
    $form['stripe_connect']['webhook']['webhook_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook URL'),
      '#value' => $webhook_url,
      '#disabled' => TRUE,
      '#description' => $this->t('Use this URL when configuring webhooks in your Stripe Dashboard.'),
    ];

    $form['stripe_connect']['webhook']['webhook_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook Signing Secret'),
      '#default_value' => $this->maskApiKey($config->get('stripe_connect.webhook_secret')),
      '#description' => $this->t('Enter the webhook signing secret from your Stripe Dashboard.'),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    // Marketplace settings
    $form['stripe_connect']['marketplace'] = [
      '#type' => 'details',
      '#title' => $this->t('Marketplace Settings'),
      '#open' => TRUE,
    ];

    $form['stripe_connect']['marketplace']['application_fee_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Application Fee Percentage'),
      '#default_value' => $config->get('stripe_connect.application_fee_percent') ?: 10,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
      '#description' => $this->t('The percentage of each transaction that will be collected as an application fee.'),
      '#field_suffix' => '%',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate API keys format
    $environment = $form_state->getValue(['stripe_connect', 'environment']);
    $secret_key = $form_state->getValue(['stripe_connect', 'keys', $environment . '_secret_key']);
    $publishable_key = $form_state->getValue(['stripe_connect', 'keys', $environment . '_publishable_key']);

    // Basic key format validation
    if (!empty($secret_key) && !preg_match('/^sk_/', $secret_key)) {
      $form_state->setError($form['stripe_connect']['keys'][$environment . '_secret_key'], $this->t('Secret key must start with sk_.'));
    }

    if (!empty($publishable_key) && !preg_match('/^pk_/', $publishable_key)) {
      $form_state->setError($form['stripe_connect']['keys'][$environment . '_publishable_key'], $this->t('Publishable key must start with pk_.'));
    }

    // Validate application fee
    $application_fee = $form_state->getValue(['stripe_connect', 'marketplace', 'application_fee_percent']);
    if ($application_fee < 0 || $application_fee > 100) {
      $form_state->setError($form['stripe_connect']['marketplace']['application_fee_percent'], $this->t('Application fee must be between 0 and 100.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('stripe_connect_marketplace.settings');

    // Save environment
    $config->set('stripe_connect.environment', 
      $form_state->getValue(['stripe_connect', 'environment'])
    );

    // Save API keys
    $environment = $form_state->getValue(['stripe_connect', 'environment']);
    $secret_key = $form_state->getValue(['stripe_connect', 'keys', $environment . '_secret_key']);
    $publishable_key = $form_state->getValue(['stripe_connect', 'keys', $environment . '_publishable_key']);

    // Only save the keys if they're not masked
    if (!preg_match('/^\*+$/', $secret_key)) {
      $config->set('stripe_connect.' . $environment . '_secret_key', $secret_key);
    }
    if (!preg_match('/^\*+$/', $publishable_key)) {
      $config->set('stripe_connect.' . $environment . '_publishable_key', $publishable_key);
    }

    // Save webhook secret
    $webhook_secret = $form_state->getValue(['stripe_connect', 'webhook', 'webhook_secret']);
    if (!preg_match('/^\*+$/', $webhook_secret)) {
      $config->set('stripe_connect.webhook_secret', $webhook_secret);
    }

    // Save marketplace settings
    $config->set('stripe_connect.application_fee_percent', 
      $form_state->getValue(['stripe_connect', 'marketplace', 'application_fee_percent'])
    );

    $config->save();

    parent::submitForm($form, $form_state);

    // Add a success message
    $this->messenger()->addStatus($this->t('Stripe Connect settings have been saved.'));
  }

  /**
   * Masks an API key for display.
   *
   * @param string $key
   *   The API key to mask.
   *
   * @return string
   *   The masked API key.
   */
  protected function maskApiKey($key) {
    if (empty($key)) {
      return '';
    }
    
    // Show the first 8 characters and last 4, mask the rest.
    $length = strlen($key);
    if ($length <= 12) {
      return str_repeat('*', $length);
    }
    
    return substr($key, 0, 8) . str_repeat('*', $length - 12) . substr($key, -4);
  }

  /**
   * Generates the webhook URL to use in Stripe Dashboard.
   *
   * @return string
   *   The webhook URL.
   */
  protected function generateWebhookUrl() {
    return \Drupal::request()->getSchemeAndHttpHost() . '/stripe/webhook';
  }
}
