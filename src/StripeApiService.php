<?php

namespace Drupal\stripe_connect_marketplace;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Provides integration with the Stripe API.
 */
class StripeApiService {

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
   * The Stripe API client.
   *
   * @var \Stripe\StripeClient
   */
  protected $client;

  /**
   * Are we in test mode? If true, return mock data instead of API calls.
   *
   * @var bool
   */
  protected $testMode = FALSE;

  /**
   * Constructs a new StripeApiService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    
    // Check if Stripe library exists
    if (!class_exists('\Stripe\Stripe')) {
      $this->logger->error('Stripe PHP library not found. Enable test mode or install with: composer require stripe/stripe-php');
      $this->testMode = TRUE;
    } else {
      // Try to initialize the API client
      $this->initClient();
      
      // If initialization failed, enable test mode
      if (!$this->client) {
        $this->testMode = TRUE;
      }
    }
  }

  /**
   * Initializes the Stripe API client.
   */
  protected function initClient() {
    $config = $this->configFactory->get('stripe_connect_marketplace.settings');
    $stripe_connect = $config->get('stripe_connect') ?: [];
    
    $environment = isset($stripe_connect['environment']) ? $stripe_connect['environment'] : 'test';
    $secret_key = isset($stripe_connect[$environment . '_secret_key']) ? $stripe_connect[$environment . '_secret_key'] : '';

    if (empty($secret_key)) {
      $this->logger->warning('Stripe API key is not configured. Using test mode.');
      return;
    }

    try {
      \Stripe\Stripe::setApiKey($secret_key);
      $this->client = new \Stripe\StripeClient($secret_key);
      $this->logger->info('Stripe client initialized successfully.');
    }
    catch (\Exception $e) {
      $this->logger->error('Error initializing Stripe client: @message', ['@message' => $e->getMessage()]);
      $this->client = NULL;
    }
  }

  /**
   * Gets the Stripe client.
   *
   * @return \Stripe\StripeClient|null
   *   The Stripe client, or NULL if unavailable.
   */
  public function getClient() {
    return $this->client;
  }

  /**
   * Creates a Stripe API resource.
   *
   * @param string $resource
   *   The resource name (e.g., 'PaymentIntent', 'Account').
   * @param array $params
   *   The parameters for the resource.
   *
   * @return object
   *   The created Stripe resource or a mock object in test mode.
   *
   * @throws \Exception
   */
  public function create($resource, array $params) {
    // If we're in test mode, return mock data
    if ($this->testMode) {
      return $this->createMockResource($resource, $params);
    }
    
    // Check if client is initialized
    if (!$this->client) {
      throw new \Exception('Stripe API client is not initialized. Please configure valid API keys.');
    }
    
    try {
      $method = lcfirst($resource) . 's';
      
      // Make sure the method exists on the client
      if (!isset($this->client->{$method})) {
        throw new \Exception("Invalid Stripe resource type: $resource");
      }
      
      return $this->client->{$method}->create($params);
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating @resource: @message', [
        '@resource' => $resource,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Retrieves a Stripe API resource.
   *
   * @param string $resource
   *   The resource name (e.g., 'PaymentIntent', 'Account').
   * @param string $id
   *   The resource ID.
   * @param array $params
   *   Optional parameters.
   *
   * @return object
   *   The Stripe resource or a mock object in test mode.
   *
   * @throws \Exception
   */
  public function retrieve($resource, $id, array $params = []) {
    // If we're in test mode, return mock data
    if ($this->testMode) {
      return $this->retrieveMockResource($resource, $id, $params);
    }
    
    // Check if client is initialized
    if (!$this->client) {
      throw new \Exception('Stripe API client is not initialized. Please configure valid API keys.');
    }
    
    try {
      $method = lcfirst($resource) . 's';
      
      // Make sure the method exists on the client
      if (!isset($this->client->{$method})) {
        throw new \Exception("Invalid Stripe resource type: $resource");
      }
      
      return $this->client->{$method}->retrieve($id, $params);
    }
    catch (\Exception $e) {
      $this->logger->error('Error retrieving @resource @id: @message', [
        '@resource' => $resource,
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Updates a Stripe API resource.
   *
   * @param string $resource
   *   The resource name (e.g., 'PaymentIntent', 'Account').
   * @param string $id
   *   The resource ID.
   * @param array $params
   *   The parameters to update.
   *
   * @return object
   *   The updated Stripe resource or a mock object in test mode.
   *
   * @throws \Exception
   */
  public function update($resource, $id, array $params) {
    // If we're in test mode, return mock data
    if ($this->testMode) {
      return $this->updateMockResource($resource, $id, $params);
    }
    
    // Check if client is initialized
    if (!$this->client) {
      throw new \Exception('Stripe API client is not initialized. Please configure valid API keys.');
    }
    
    try {
      $method = lcfirst($resource) . 's';
      
      // Make sure the method exists on the client
      if (!isset($this->client->{$method})) {
        throw new \Exception("Invalid Stripe resource type: $resource");
      }
      
      return $this->client->{$method}->update($id, $params);
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating @resource @id: @message', [
        '@resource' => $resource,
        '@id' => $id,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Constructs a Stripe Event from a webhook payload.
   *
   * @param string $payload
   *   The webhook payload.
   * @param string $sig_header
   *   The Stripe-Signature header.
   * @param string $webhook_secret
   *   The webhook signing secret.
   *
   * @return \Stripe\Event
   *   The constructed Stripe Event or a mock object in test mode.
   *
   * @throws \UnexpectedValueException
   * @throws \Stripe\Exception\SignatureVerificationException
   */
  public function constructWebhookEvent($payload, $sig_header, $webhook_secret) {
    // If we're in test mode, return mock data
    if ($this->testMode) {
      return $this->createMockEvent($payload);
    }
    
    // Check if Stripe library is available
    if (!class_exists('\Stripe\Webhook')) {
      throw new \Exception('Stripe PHP library is not installed. Please run: composer require stripe/stripe-php');
    }
    
    return \Stripe\Webhook::constructEvent(
      $payload, $sig_header, $webhook_secret
    );
  }

  /**
   * Creates a mock Stripe resource for testing.
   *
   * @param string $resource
   *   The resource name.
   * @param array $params
   *   The parameters.
   *
   * @return \stdClass
   *   A mock Stripe resource.
   */
  protected function createMockResource($resource, array $params) {
    $mock = new \stdClass();
    
    switch ($resource) {
      case 'Account':
        $mock->id = 'acct_' . md5(uniqid('', TRUE));
        $mock->object = 'account';
        $mock->created = time();
        $mock->details_submitted = TRUE;
        $mock->charges_enabled = TRUE;
        $mock->payouts_enabled = TRUE;
        break;
        
      case 'AccountLink':
        $mock->object = 'account_link';
        $mock->created = time();
        $mock->expires_at = time() + 3600;
        $mock->url = 'https://connect.stripe.com/mock/acct_link/' . md5(uniqid('', TRUE));
        break;
        
      case 'PaymentIntent':
        $mock->id = 'pi_' . md5(uniqid('', TRUE));
        $mock->object = 'payment_intent';
        $mock->amount = isset($params['amount']) ? $params['amount'] : 1000;
        $mock->currency = isset($params['currency']) ? $params['currency'] : 'usd';
        $mock->status = 'succeeded';
        $mock->created = time();
        if (isset($params['metadata'])) {
          $mock->metadata = (object) $params['metadata'];
        }
        break;
        
      default:
        $mock->id = 'mock_' . md5(uniqid('', TRUE));
        $mock->object = strtolower($resource);
        $mock->created = time();
    }
    
    $this->logger->info('Created mock @resource: @id', [
      '@resource' => $resource,
      '@id' => $mock->id,
    ]);
    
    return $mock;
  }

  /**
   * Retrieves a mock Stripe resource for testing.
   *
   * @param string $resource
   *   The resource name.
   * @param string $id
   *   The resource ID.
   * @param array $params
   *   Optional parameters.
   *
   * @return \stdClass
   *   A mock Stripe resource.
   */
  protected function retrieveMockResource($resource, $id, array $params = []) {
    $mock = new \stdClass();
    $mock->id = $id;
    $mock->object = strtolower($resource);
    $mock->created = time() - 86400; // Yesterday
    
    switch ($resource) {
      case 'Account':
        $mock->details_submitted = TRUE;
        $mock->charges_enabled = TRUE;
        $mock->payouts_enabled = TRUE;
        break;
        
      case 'Balance':
        $mock->available = [
          (object) [
            'amount' => 10000,
            'currency' => 'usd',
          ],
        ];
        $mock->pending = [
          (object) [
            'amount' => 5000,
            'currency' => 'usd',
          ],
        ];
        break;
    }
    
    $this->logger->info('Retrieved mock @resource: @id', [
      '@resource' => $resource,
      '@id' => $id,
    ]);
    
    return $mock;
  }

  /**
   * Updates a mock Stripe resource for testing.
   *
   * @param string $resource
   *   The resource name.
   * @param string $id
   *   The resource ID.
   * @param array $params
   *   The parameters to update.
   *
   * @return \stdClass
   *   A mock Stripe resource.
   */
  protected function updateMockResource($resource, $id, array $params) {
    $mock = $this->retrieveMockResource($resource, $id);
    
    // Apply update params
    foreach ($params as $key => $value) {
      if (is_array($value) && isset($mock->{$key}) && is_object($mock->{$key})) {
        foreach ($value as $subkey => $subvalue) {
          $mock->{$key}->{$subkey} = $subvalue;
        }
      }
      else {
        $mock->{$key} = $value;
      }
    }
    
    $mock->updated = time();
    
    $this->logger->info('Updated mock @resource: @id', [
      '@resource' => $resource,
      '@id' => $id,
    ]);
    
    return $mock;
  }

  /**
   * Creates a mock Stripe event for testing.
   *
   * @param string $payload
   *   The webhook payload.
   *
   * @return \stdClass
   *   A mock Stripe event.
   */
  protected function createMockEvent($payload) {
    $event = new \stdClass();
    $event->id = 'evt_' . md5(uniqid('', TRUE));
    $event->object = 'event';
    $event->created = time();
    $event->type = 'mock.event';
    $event->data = new \stdClass();
    $event->data->object = new \stdClass();
    
    $this->logger->info('Created mock event: @id', ['@id' => $event->id]);
    
    return $event;
  }
}
