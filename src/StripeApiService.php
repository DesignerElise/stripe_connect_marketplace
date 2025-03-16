<?php

namespace Drupal\stripe_connect_marketplace;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\stripe_connect_marketplace\Utility\SafeLogging;

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
      SafeLogging::log($this->logger, 'Stripe PHP library not found. Enable test mode or install with: composer require stripe/stripe-php');
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
      SafeLogging::log($this->logger, 'Stripe API key is not configured. Using test mode.');
      return;
    }

    try {
      \Stripe\Stripe::setApiKey($secret_key);
      $this->client = new \Stripe\StripeClient($secret_key);
      SafeLogging::log($this->logger, 'Stripe client initialized successfully.');
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error initializing Stripe client: @message', ['@message' => $e->getMessage()]);
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
   * @param array $options
   *   Additional options for the request, e.g., ['stripe_account' => 'acct_123'].
   *
   * @return object
   *   The created Stripe resource or a mock object in test mode.
   *
   * @throws \Exception
   */
  public function create($resource, array $params, array $options = []) {
    // If we're in test mode, return mock data
    if ($this->testMode) {
      return $this->createMockResource($resource, $params);
    }
    
    // Check if client is initialized
    if (!$this->client) {
      throw new \Exception('Stripe API client is not initialized. Please configure valid API keys.');
    }
    
    try {
      // Convert the resource name to the appropriate method name based on the Stripe API structure
      switch ($resource) {
        case 'Account':
          return $this->client->accounts->create($params, $options);
        
        case 'AccountLink':
          return $this->client->accountLinks->create($params, $options);
        
        case 'LoginLink':
          // LoginLink requires the account ID to be in the parameters
          if (!isset($params['account'])) {
            throw new \Exception('Account ID is required for creating a LoginLink');
          }
          
          // Extract the account ID and remove it from params
          $account_id = $params['account'];
          unset($params['account']);
          
          // Create the login link for the specified account
          return $this->client->accounts->createLoginLink($account_id, $params, $options);
        
        case 'PaymentIntent':
          return $this->client->paymentIntents->create($params, $options);
          
        case 'SetupIntent':
          return $this->client->setupIntents->create($params, $options);
          
        case 'Customer':
          return $this->client->customers->create($params, $options);
          
        case 'Source':
          return $this->client->sources->create($params, $options);
          
        case 'PaymentMethod':
          return $this->client->paymentMethods->create($params, $options);
          
        case 'Charge':
          return $this->client->charges->create($params, $options);
          
        case 'Refund':
          return $this->client->refunds->create($params, $options);
          
        case 'Payout':
          return $this->client->payouts->create($params, $options);
          
        default:
          throw new \Exception("Invalid Stripe resource type: $resource");
      }
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error creating @resource: @message', [
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
   * @param array $options
   *   Additional options for the request, e.g., ['stripe_account' => 'acct_123'].
   *
   * @return object
   *   The Stripe resource or a mock object in test mode.
   *
   * @throws \Exception
   */
  public function retrieve($resource, $id, array $params = [], array $options = []) {
    // If we're in test mode, return mock data
    if ($this->testMode) {
      return $this->retrieveMockResource($resource, $id, $params);
    }
    
    // Check if client is initialized
    if (!$this->client) {
      throw new \Exception('Stripe API client is not initialized. Please configure valid API keys.');
    }
    
    try {
      // Convert the resource name to the appropriate method name based on the Stripe API structure
      switch ($resource) {
        case 'Account':
          return $this->client->accounts->retrieve($id, $params, $options);
        
        case 'Balance':
          return $this->client->balance->retrieve($params, $options);
        
        case 'PaymentIntent':
          return $this->client->paymentIntents->retrieve($id, $params, $options);
          
        case 'SetupIntent':
          return $this->client->setupIntents->retrieve($id, $params, $options);
          
        case 'Customer':
          return $this->client->customers->retrieve($id, $params, $options);
          
        case 'PaymentMethod':
          return $this->client->paymentMethods->retrieve($id, $params, $options);
          
        case 'Charge':
          return $this->client->charges->retrieve($id, $params, $options);
          
        case 'Refund':
          return $this->client->refunds->retrieve($id, $params, $options);
          
        case 'Payout':
          return $this->client->payouts->retrieve($id, $params, $options);
          
        default:
          throw new \Exception("Invalid Stripe resource type: $resource");
      }
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error retrieving @resource @id: @message', [
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
   * @param array $options
   *   Additional options for the request, e.g., ['stripe_account' => 'acct_123'].
   *
   * @return object
   *   The updated Stripe resource or a mock object in test mode.
   *
   * @throws \Exception
   */
  public function update($resource, $id, array $params, array $options = []) {
    // If we're in test mode, return mock data
    if ($this->testMode) {
      return $this->updateMockResource($resource, $id, $params);
    }
    
    // Check if client is initialized
    if (!$this->client) {
      throw new \Exception('Stripe API client is not initialized. Please configure valid API keys.');
    }
    
    try {
      // Convert the resource name to the appropriate method name based on the Stripe API structure
      switch ($resource) {
        case 'Account':
          return $this->client->accounts->update($id, $params, $options);
        
        case 'PaymentIntent':
          return $this->client->paymentIntents->update($id, $params, $options);
          
        case 'SetupIntent':
          return $this->client->setupIntents->update($id, $params, $options);
          
        case 'Customer':
          return $this->client->customers->update($id, $params, $options);
          
        case 'PaymentMethod':
          return $this->client->paymentMethods->update($id, $params, $options);
          
        case 'Subscription':
          return $this->client->subscriptions->update($id, $params, $options);
          
        default:
          throw new \Exception("Invalid Stripe resource type: $resource");
      }
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error updating @resource @id: @message', [
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
    $mock->id = 'mock_' . md5(uniqid('', TRUE)); // Ensure id is always set
    
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
      
      case 'LoginLink':
        $mock->object = 'login_link';
        $mock->created = time();
        $mock->url = 'https://dashboard.stripe.com/test/connect/mock/login/' . md5(uniqid('', TRUE));
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
        $mock->object = strtolower($resource);
        $mock->created = time();
    }
    
    SafeLogging::log($this->logger, 'Created mock @resource: @id', [
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
    $mock->id = $id ?: 'mock_' . md5(uniqid('', TRUE)); // Ensure id is always set
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
    
    SafeLogging::log($this->logger, 'Retrieved mock @resource: @id', [
      '@resource' => $resource,
      '@id' => $id ?: 'null',
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
    
    SafeLogging::log($this->logger, 'Updated mock @resource: @id', [
      '@resource' => $resource,
      '@id' => $id ?: 'null',
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
    
    SafeLogging::log($this->logger, 'Created mock event: @id', ['@id' => $event->id]);
    
    return $event;
  }
}
