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
  }

  /**
   * Gets the Stripe client.
   *
   * @return object
   *   A stub client object.
   */
  public function getClient() {
    return new \stdClass();
  }

  /**
   * Creates a Stripe API resource.
   *
   * @param string $resource
   *   The resource name.
   * @param array $params
   *   The parameters for the resource.
   *
   * @return object
   *   A stub resource object.
   */
  public function create($resource, array $params) {
    return new \stdClass();
  }

  /**
   * Retrieves a Stripe API resource.
   *
   * @param string $resource
   *   The resource name.
   * @param string $id
   *   The resource ID.
   * @param array $params
   *   Optional parameters.
   *
   * @return object
   *   A stub resource object.
   */
  public function retrieve($resource, $id, array $params = []) {
    return new \stdClass();
  }

  /**
   * Updates a Stripe API resource.
   *
   * @param string $resource
   *   The resource name.
   * @param string $id
   *   The resource ID.
   * @param array $params
   *   The parameters to update.
   *
   * @return object
   *   A stub resource object.
   */
  public function update($resource, $id, array $params) {
    return new \stdClass();
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
   * @return object
   *   A stub event object.
   */
  public function constructWebhookEvent($payload, $sig_header, $webhook_secret) {
    return new \stdClass();
  }
}
