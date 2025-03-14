<?php

namespace Drupal\stripe_connect_marketplace\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\Stripe;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\stripe_connect_marketplace\StripeApiService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_price\Price;

/**
 * Provides the Stripe Connect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "stripe_connect",
 *   label = @Translation("Stripe Connect"),
 *   display_label = @Translation("Credit Card (via Stripe Connect)"),
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_stripe\PluginForm\Stripe\PaymentMethodAddForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "mastercard", "visa",
 *   },
 *   js_library = "commerce_stripe/form",
 * )
 */
class StripeConnect extends Stripe {

  /**
   * The Stripe API service.
   *
   * @var \Drupal\stripe_connect_marketplace\StripeApiService
   */
  protected $stripeApi;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new StripeConnect object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Drupal\stripe_connect_marketplace\StripeApiService $stripe_api
   *   The Stripe API.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
    StripeApiService $stripe_api,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $time, $stripe_api);
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->stripeApi = $stripe_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('stripe_connect_marketplace.api'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'application_fee_percent' => 10,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['application_fee_percent'] = [
      '#type' => 'number',
      '#title' => $this->t('Application fee percentage'),
      '#description' => $this->t('The percentage of the payment amount that will be collected as an application fee.'),
      '#default_value' => $this->configuration['application_fee_percent'],
      '#required' => TRUE,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.01,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    
    if ($form_state->getValue('application_fee_percent')) {
      $this->configuration['application_fee_percent'] = $form_state->getValue('application_fee_percent');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    // Get the order to retrieve vendor information.
    $order = $payment->getOrder();
    
    // Get the vendor/store owner Stripe account ID.
    $vendor_account_id = $this->getVendorStripeAccountId($order);
    
    if (empty($vendor_account_id)) {
      throw new PaymentGatewayException('Vendor Stripe account not found.');
    }

    // Prepare for the API request.
    $stripe_payment_method_id = $payment_method->getRemoteId();
    
    if (empty($stripe_payment_method_id)) {
      throw new PaymentGatewayException('The provided payment method is invalid.');
    }

    $payment_amount = $payment->getAmount();
    $amount_decimal = $payment_amount->getNumber();
    $currency_code = strtolower($payment_amount->getCurrencyCode());
    
    // Calculate application fee.
    $application_fee_percent = $this->configuration['application_fee_percent'];
    $application_fee_amount = round($amount_decimal * $application_fee_percent / 100, 2);
    $application_fee_amount_cents = $this->minorUnits($payment_amount->getCurrencyCode(), $application_fee_amount);

    // Create a payment intent directly on the connected account.
    $intent_array = [
      'amount' => $this->minorUnits($payment_amount->getCurrencyCode(), $amount_decimal),
      'currency' => $currency_code,
      'payment_method' => $stripe_payment_method_id,
      'confirmation_method' => 'manual',
      'confirm' => TRUE,
      'application_fee_amount' => $application_fee_amount_cents,
      'metadata' => [
        'order_id' => $order->id(),
        'payment_id' => $payment->id(),
      ],
      'description' => $this->buildPaymentDescription($payment),
    ];

    // Add capture method if not immediate capture.
    if (!$capture) {
      $intent_array['capture_method'] = 'manual';
    }

    // Options for the connected account.
    $options = ['stripe_account' => $vendor_account_id];

    try {
      $intent = $this->stripeApi->create('PaymentIntent', $intent_array, $options);
      
      // Update various payment properties based on the intent.
      $next_state = $capture ? 'completed' : 'authorization';
      
      // Check if the intent requires additional action.
      if ($intent->status === 'requires_action' && $intent->next_action) {
        $payment->setRemoteId($intent->id);
        $payment->setState('authorization');
        $payment->save();
        
        throw new PaymentGatewayException('This payment requires additional customer authentication. Please contact the customer to complete the payment.');
      }
      
      if (in_array($intent->status, ['succeeded', 'requires_capture'])) {
        $payment->setRemoteId($intent->id);
        $payment->setState($next_state);
        $payment->save();
      }
      else {
        throw new PaymentGatewayException('Invalid payment state: ' . $intent->status);
      }
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error($e->getMessage());
      throw new PaymentGatewayException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * Gets the Stripe account ID for the vendor/store associated with the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string|null
   *   The Stripe account ID or NULL if not found.
   */
  protected function getVendorStripeAccountId(OrderInterface $order) {
    // Get the store associated with the order.
    $store = $order->getStore();
    if (!$store) {
      return NULL;
    }
    
    // Get the store owner.
    $owner = $store->getOwner();
    if (!$owner) {
      return NULL;
    }
    
    // Check if the owner has a Stripe account ID field.
    if ($owner->hasField('field_stripe_account_id') && !$owner->get('field_stripe_account_id')->isEmpty()) {
      return $owner->get('field_stripe_account_id')->value;
    }
    
    return NULL;
  }

  /**
   * Builds a description for the payment.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   *
   * @return string
   *   The description.
   */
  protected function buildPaymentDescription(PaymentInterface $payment) {
    $order = $payment->getOrder();
    if ($order->getEmail()) {
      return $this->t('Order #@number by @email', [
        '@number' => $order->id(),
        '@email' => $order->getEmail(),
      ]);
    }
    else {
      return $this->t('Order #@number', [
        '@number' => $order->id(),
      ]);
    }
  }

  /**
   * Converts a price amount to its minor units.
   *
   * This is a wrapper around the minorUnits method from the Stripe class.
   *
   * @param string $currency_code
   *   The currency code.
   * @param string|float|int $amount
   *   The amount.
   *
   * @return int
   *   The amount in minor units.
   */
  protected function minorUnits($currency_code, $amount) {
    // If the parent class has the minorUnits method, use it.
    if (method_exists(get_parent_class($this), 'minorUnits')) {
      return parent::minorUnits($currency_code, $amount);
    }
    
    // Fallback implementation.
    $currency_code = strtoupper($currency_code);
    // @see https://stripe.com/docs/currencies#zero-decimal
    $zero_decimal_currencies = [
      'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
      'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];
    return in_array($currency_code, $zero_decimal_currencies) ? $amount : $amount * 100;
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    $remote_id = $payment->getRemoteId();

    try {
      // If not specified, capture the entire amount.
      $amount = $amount ?: $payment->getAmount();
      $amount_decimal = $amount->getNumber();
      
      // Get the vendor account ID for this payment.
      $order = $payment->getOrder();
      $vendor_account_id = $this->getVendorStripeAccountId($order);
      
      if (empty($vendor_account_id)) {
        throw new PaymentGatewayException('Vendor Stripe account not found.');
      }
      
      // Options for the connected account.
      $options = ['stripe_account' => $vendor_account_id];

      // Retrieve intent from the connected account.
      $intent = $this->stripeApi->retrieve('PaymentIntent', $remote_id, [], $options);
      
      // Capture the payment on the connected account.
      $capture_params = [
        'amount_to_capture' => $this->minorUnits($amount->getCurrencyCode(), $amount_decimal),
      ];
      
      $intent = $this->client->paymentIntents->capture($remote_id, $capture_params, $options);
      
      if ($intent->status === 'succeeded') {
        $payment->setState('completed');
        $payment->setAmount($amount);
        $payment->save();
      }
      else {
        throw new PaymentGatewayException('Only authorized payments can be captured.');
      }
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error($e->getMessage());
      throw new PaymentGatewayException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->assertPaymentState($payment, ['authorization']);
    $remote_id = $payment->getRemoteId();

    try {
      // Get the vendor account ID for this payment.
      $order = $payment->getOrder();
      $vendor_account_id = $this->getVendorStripeAccountId($order);
      
      if (empty($vendor_account_id)) {
        throw new PaymentGatewayException('Vendor Stripe account not found.');
      }
      
      // Options for the connected account.
      $options = ['stripe_account' => $vendor_account_id];

      // Cancel the payment intent on the connected account.
      $intent = $this->stripeApi->update('PaymentIntent', $remote_id, ['cancellation_reason' => 'requested_by_customer'], $options);
      
      $payment->setState('authorization_voided');
      $payment->save();
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error($e->getMessage());
      throw new PaymentGatewayException($e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    $remote_id = $payment->getRemoteId();

    try {
      // If not specified, refund the entire amount.
      $amount = $amount ?: $payment->getAmount();
      $amount_decimal = $amount->getNumber();
      
      // Get the vendor account ID for this payment.
      $order = $payment->getOrder();
      $vendor_account_id = $this->getVendorStripeAccountId($order);
      
      if (empty($vendor_account_id)) {
        throw new PaymentGatewayException('Vendor Stripe account not found.');
      }
      
      // Options for the connected account.
      $options = ['stripe_account' => $vendor_account_id];

      // Create the refund on the connected account.
      $refund_params = [
        'payment_intent' => $remote_id,
        'amount' => $this->minorUnits($amount->getCurrencyCode(), $amount_decimal),
      ];
      
      $refund = $this->stripeApi->create('Refund', $refund_params, $options);
      
      if ($refund->status === 'succeeded') {
        $old_refunded_amount = $payment->getRefundedAmount();
        $new_refunded_amount = $old_refunded_amount->add($amount);
        $payment->setRefundedAmount($new_refunded_amount);
        
        // Set state based on how much has been refunded.
        if ($new_refunded_amount->lessThan($payment->getAmount())) {
          $payment->setState('partially_refunded');
        }
        else {
          $payment->setState('refunded');
        }
        
        $payment->save();
      }
      else {
        throw new PaymentGatewayException('Refund failed. Status: ' . $refund->status);
      }
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error($e->getMessage());
      throw new PaymentGatewayException($e->getMessage(), $e->getCode(), $e);
    }
  }
}
