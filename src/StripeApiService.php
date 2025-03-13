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
    $this->initClient();
  }

  /**
   * Initializes the Stripe API client.
   */
  protected function initClient() {
    $config = $this->configFactory->get('stripe_connect_marketplace.settings');
    $environment = $config->get('stripe_connect.environment');
    $secret_key = ($environment === 'live')
      ? $config->get('stripe_connect.live_secret_key')
      : $config->get('stripe_connect.test_secret_key');

    if (empty($secret_key)) {
      $this->logger->warning('Stripe API key is not configured.');
      return;
    }

    try {
      \Stripe\Stripe::setApiKey($secret_key);
      $this->client = new \Stripe\StripeClient($secret_key);
    }
    catch (\Exception $e) {
      $this->logger->error('Error initializing Stripe client: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Gets the Stripe client.
   *
   * @return \Stripe\StripeClient
   *   The Stripe client.
   */
  public function getClient() {
    if (!$this->client) {
      $this->initClient();
    }
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
   *   The created Stripe resource.
   *
   * @throws \Exception
   */
  public function create($resource, array $params) {
    try {
      $method = lcfirst($resource) . 's';
      return $this->getClient()->{$method}->create($params);
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
   *   The Stripe resource.
   *
   * @throws \Exception
   */
  public function retrieve($resource, $id, array $params = []) {
    try {
      $method = lcfirst($resource) . 's';
      return $this->getClient()->{$method}->retrieve($id, $params);
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
   *   The updated Stripe resource.
   *
   * @throws \Exception
   */
  public function update($resource, $id, array $params) {
    try {
      $method = lcfirst($resource) . 's';
      return $this->getClient()->{$method}->update($id, $params);
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
   *   The constructed Stripe Event.
   *
   * @throws \UnexpectedValueException
   * @throws \Stripe\Exception\SignatureVerificationException
   */
  public function constructWebhookEvent($payload, $sig_header, $webhook_secret) {
    return \Stripe\Webhook::constructEvent(
      $payload, $sig_header, $webhook_secret
    );
  }
}
