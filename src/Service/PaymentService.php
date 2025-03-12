<?php

namespace Drupal\stripe_connect_marketplace\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\stripe_connect_marketplace\StripeApiService;

/**
 * Service for handling Stripe Connect payments.
 */
class PaymentService {

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
   * The Stripe API service.
   *
   * @var \Drupal\stripe_connect_marketplace\StripeApiService
   */
  protected $stripeApi;

  /**
   * Constructs a new PaymentService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\stripe_connect_marketplace\StripeApiService $stripe_api
   *   The Stripe API service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    StripeApiInterface $stripe_api
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->entityTypeManager = $entity_type_manager;
    $this->stripeApi = $stripe_api;
  }

  /**
   * Create a new Stripe Connect account for a vendor.
   *
   * @param string $email
   *   The vendor's email.
   * @param string $country
   *   The vendor's country code.
   * @param array $account_data
   *   Additional account data.
   *
   * @return \Stripe\Account
   *   The created Stripe account.
   *
   * @throws \Exception
   */
  public function createConnectAccount($email, $country = 'US', array $account_data = []) {
    try {
      $account_data = [
        'type' => 'express',
        'email' => $email,
        'country' => $country,
        'capabilities' => [
          'card_payments' => ['requested' => true],
          'transfers' => ['requested' => true],
        ],
      ] + $account_data;
      
      $account = $this->stripeApi->create('Account', $account_data);
      
      $this->logger->info('Stripe Connect account created: @id for @email', [
        '@id' => $account->id,
        '@email' => $email,
      ]);
      
      return $account;
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error('Stripe API Error: @message', ['@message' => $e->getMessage()]);
      throw new \Exception('Failed to create Stripe Connect account: ' . $e->getMessage(), $e->getCode(), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Account creation error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Generate an account link for onboarding a Connect account.
   *
   * @param string $account_id
   *   The Stripe account ID.
   * @param string $refresh_url
   *   The URL to redirect to if the link is expired.
   * @param string $return_url
   *   The URL to redirect to when the flow is complete.
   *
   * @return \Stripe\AccountLink
   *   The account link.
   *
   * @throws \Exception
   */
  public function createAccountLink($account_id, $refresh_url, $return_url) {
    try {
      $account_link = $this->stripeApi->create('AccountLink', [
        'account' => $account_id,
        'refresh_url' => $refresh_url,
        'return_url' => $return_url,
        'type' => 'account_onboarding',
      ]);
      
      return $account_link;
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error('Stripe API Error: @message', ['@message' => $e->getMessage()]);
      throw new \Exception('Failed to create account link: ' . $e->getMessage(), $e->getCode(), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Account link error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Create a split payment with application fee.
   *
   * @param float $amount
   *   The payment amount.
   * @param string $currency
   *   The currency code.
   * @param string $payment_method_id
   *   The Stripe payment method ID.
   * @param string $vendor_account_id
   *   The vendor's Stripe account ID.
   * @param int $order_id
   *   The commerce order ID.
   * @param float $fee_percentage
   *   The application fee percentage.
   *
   * @return \Stripe\PaymentIntent
   *   The Stripe payment intent.
   *
   * @throws \Exception
   */
  public function createSplitPayment($amount, $currency, $payment_method_id, $vendor_account_id, $order_id, $fee_percentage = NULL) {
    try {
      // Convert amount to minor units
      $minor_amount = $this->toMinorUnits($amount, $currency);
      
      // Get application fee percentage from config if not provided
      if ($fee_percentage === NULL) {
        $config = $this->configFactory->get('stripe_connect_marketplace.settings');
        $fee_percentage = $config->get('stripe_connect.application_fee_percent');
      }
      
      // Calculate application fee
      $application_fee = round($minor_amount * ($fee_percentage / 100));
      
      // Create payment intent
      $payment_intent = $this->stripeApi->create('PaymentIntent', [
        'amount' => $minor_amount,
        'currency' => strtolower($currency),
        'payment_method' => $payment_method_id,
        'confirmation_method' => 'manual',
        'application_fee_amount' => $application_fee,
        'transfer_data' => [
          'destination' => $vendor_account_id,
        ],
        'metadata' => [
          'order_id' => $order_id,
        ],
      ]);
      
      $this->logger->info('Split payment created: @id for order @order_id', [
        '@id' => $payment_intent->id,
        '@order_id' => $order_id,
      ]);
      
      return $payment_intent;
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error('Stripe API Error: @message', ['@message' => $e->getMessage()]);
      throw new \Exception('Stripe payment error: ' . $e->getMessage(), $e->getCode(), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Convert amount to minor units as required by Stripe.
   *
   * @param float $amount
   *   The amount in major units.
   * @param string $currency
   *   The currency code.
   *
   * @return int
   *   The amount in minor units.
   */
  protected function toMinorUnits($amount, $currency) {
    $currency_code = strtoupper($currency);
    // @see https://stripe.com/docs/currencies#zero-decimal
    $zero_decimal_currencies = [
      'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
      'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];
    return in_array($currency_code, $zero_decimal_currencies) ? (int) $amount : (int) ($amount * 100);
  }
}<?php

namespace Drupal\stripe_connect_marketplace\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for handling Stripe Connect payments.
 */
class PaymentService {

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
   * Constructs a new PaymentService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Initializes the Stripe API with the appropriate key.
   */
  protected function initStripeApi() {
    // Let's use the StripeApiFactory service from commerce_stripe
    $stripe_api = \Drupal::service('commerce_stripe.stripe_api');
    return $stripe_api;
  }

  /**
   * Create a split payment with application fee.
   *
   * @param float $amount
   *   The payment amount.
   * @param string $currency
   *   The currency code.
   * @param string $payment_method_id
   *   The Stripe payment method ID.
   * @param string $vendor_account_id
   *   The vendor's Stripe account ID.
   * @param int $order_id
   *   The commerce order ID.
   * @param float $fee_percentage
   *   The application fee percentage.
   *
   * @return \Stripe\PaymentIntent
   *   The Stripe payment intent.
   *
   * @throws \Exception
   */
  public function createSplitPayment($amount, $currency, $payment_method_id, $vendor_account_id, $order_id, $fee_percentage = NULL) {
    try {
      $this->initStripeApi();
      
      // Convert amount to minor units
      $minor_amount = $this->toMinorUnits($amount, $currency);
      
      // Get application fee percentage from config if not provided
      if ($fee_percentage === NULL) {
        $config = $this->configFactory->get('stripe_connect_marketplace.settings');
        $fee_percentage = $config->get('stripe_connect.application_fee_percent');
      }
      
      // Calculate application fee
      $application_fee = round($minor_amount * ($fee_percentage / 100));
      
      // Create payment intent
      $payment_intent = \Stripe\PaymentIntent::create([
        'amount' => $minor_amount,
        'currency' => strtolower($currency),
        'payment_method' => $payment_method_id,
        'confirmation_method' => 'manual',
        'application_fee_amount' => $application_fee,
        'transfer_data' => [
          'destination' => $vendor_account_id,
        ],
        'metadata' => [
          'order_id' => $order_id,
        ],
      ]);
      
      $this->logger->info('Split payment created: @id for order @order_id', [
        '@id' => $payment_intent->id,
        '@order_id' => $order_id,
      ]);
      
      return $payment_intent;
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error('Stripe API Error: @message', ['@message' => $e->getMessage()]);
      throw new \Exception('Stripe payment error: ' . $e->getMessage(), $e->getCode(), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Create a new Stripe Connect account for a vendor.
   *
   * @param string $email
   *   The vendor's email.
   * @param string $country
   *   The vendor's country code.
   * @param array $account_data
   *   Additional account data.
   *
   * @return \Stripe\Account
   *   The created Stripe account.
   *
   * @throws \Exception
   */
  public function createConnectAccount($email, $country = 'US', array $account_data = []) {
    try {
      $stripe_api = $this->initStripeApi();
      
      $account_data = [
        'type' => 'express',
        'email' => $email,
        'country' => $country,
        'capabilities' => [
          'card_payments' => ['requested' => true],
          'transfers' => ['requested' => true],
        ],
      ] + $account_data;
      
      $account = $stripe_api->account->create($account_data);
      
      $this->logger->info('Stripe Connect account created: @id for @email', [
        '@id' => $account->id,
        '@email' => $email,
      ]);
      
      return $account;
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error('Stripe API Error: @message', ['@message' => $e->getMessage()]);
      throw new \Exception('Failed to create Stripe Connect account: ' . $e->getMessage(), $e->getCode(), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Account creation error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Generate an account link for onboarding a Connect account.
   *
   * @param string $account_id
   *   The Stripe account ID.
   * @param string $refresh_url
   *   The URL to redirect to if the link is expired.
   * @param string $return_url
   *   The URL to redirect to when the flow is complete.
   *
   * @return \Stripe\AccountLink
   *   The account link.
   *
   * @throws \Exception
   */
  public function createAccountLink($account_id, $refresh_url, $return_url) {
    try {
      $stripe_api = $this->initStripeApi();
      
      $account_link = $stripe_api->accountLinks->create([
        'account' => $account_id,
        'refresh_url' => $refresh_url,
        'return_url' => $return_url,
        'type' => 'account_onboarding',
      ]);
      
      return $account_link;
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error('Stripe API Error: @message', ['@message' => $e->getMessage()]);
      throw new \Exception('Failed to create account link: ' . $e->getMessage(), $e->getCode(), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Account link error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Convert amount to minor units as required by Stripe.
   *
   * @param float $amount
   *   The amount in major units.
   * @param string $currency
   *   The currency code.
   *
   * @return int
   *   The amount in minor units.
   */
  protected function toMinorUnits($amount, $currency) {
    // Get the number of decimal places for this currency.
    $decimal_places = 2;
    if (in_array(strtoupper($currency), ['JPY'])) {
      $decimal_places = 0;
    }
    
    return (int) round($amount * pow(10, $decimal_places));
  }
}
