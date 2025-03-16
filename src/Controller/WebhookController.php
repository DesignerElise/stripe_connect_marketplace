<?php

namespace Drupal\stripe_connect_marketplace\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\stripe_connect_marketplace\Service\PayoutService;
use Drupal\stripe_connect_marketplace\StripeApiService;
use Drupal\stripe_connect_marketplace\Utility\SafeLogging;

/**
 * Controller for handling Stripe webhooks.
 */
class WebhookController extends ControllerBase {

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
      $container->get('stripe_connect_marketplace.payout_service'),
      $container->get('stripe_connect_marketplace.api')
    );
  }

  /**
   * Constructs a new WebhookController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\stripe_connect_marketplace\Service\PayoutService $payout_service
   *   The payout service.
   * @param \Drupal\stripe_connect_marketplace\StripeApiService $stripe_api
   *   The Stripe API service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    PayoutService $payout_service,
    StripeApiService $stripe_api
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->entityTypeManager = $entity_type_manager;
    $this->payoutService = $payout_service;
    $this->stripeApi = $stripe_api;
  }

  /**
   * Processes incoming Stripe webhooks.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function processWebhook(Request $request) {
    try {
      // Get the webhook payload and signature header
      $payload = $request->getContent();
      $sig_header = $request->headers->get('Stripe-Signature');
      
      if (empty($payload) || empty($sig_header)) {
        SafeLogging::log($this->logger, 'Missing webhook payload or signature header');
        return new Response('Bad request', 400);
      }
      
      // Get webhook secret from config
      $config = $this->configFactory->get('stripe_connect_marketplace.settings');
      $webhook_secret = $config->get('stripe_connect.webhook_secret');
      
      if (empty($webhook_secret)) {
        SafeLogging::log($this->logger, 'Webhook secret not configured');
        return new Response('Server error', 500);
      }
      
      // Verify webhook signature using Stripe library
      try {
        $event = $this->stripeApi->constructWebhookEvent(
          $payload, $sig_header, $webhook_secret
        );
      }
      catch (\UnexpectedValueException $e) {
        SafeLogging::log($this->logger, 'Invalid webhook payload: @message', ['@message' => $e->getMessage()]);
        return new Response('Bad request', 400);
      }
      catch (\Stripe\Exception\SignatureVerificationException $e) {
        SafeLogging::log($this->logger, 'Invalid webhook signature: @message', ['@message' => $e->getMessage()]);
        return new Response('Bad request', 400);
      }
      
      // Log the event type
      SafeLogging::log($this->logger, 'Processing Stripe webhook: @type', ['@type' => $event->type]);
      
      // Determine the account ID if this is a Connect event
      $account_id = null;
      if (isset($event->account)) {
        $account_id = $event->account;
      }
      
      // Process the event based on its type
      switch ($event->type) {
        case 'payment_intent.succeeded':
          return $this->handlePaymentIntentSucceeded($event->data->object, $account_id);
          
        case 'payment_intent.payment_failed':
          return $this->handlePaymentIntentFailed($event->data->object, $account_id);
          
        case 'charge.refunded':
          return $this->handleChargeRefunded($event->data->object, $account_id);
          
        case 'payout.created':
        case 'payout.paid':
        case 'payout.failed':
          return $this->handlePayoutEvent($event->data->object, $event->type, $account_id);
          
        case 'account.updated':
          return $this->handleAccountUpdated($event->data->object);
          
        default:
          // Log but don't take action for other event types
          SafeLogging::log($this->logger, 'Unhandled webhook event: @type', ['@type' => $event->type]);
          return new Response('Event received', 200);
      }
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Webhook processing error: @message', ['@message' => $e->getMessage()]);
      return new Response('Server error', 500);
    }
  }

  /**
   * Handles payment_intent.succeeded events.
   *
   * @param \Stripe\PaymentIntent $payment_intent
   *   The payment intent object.
   * @param string $account_id
   *   The connected account ID, if any.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  protected function handlePaymentIntentSucceeded(\Stripe\PaymentIntent $payment_intent, $account_id = null) {
    try {
      // Check if this payment is related to an order
      if (!isset($payment_intent->metadata->order_id)) {
        SafeLogging::log($this->logger, 'No order ID in payment intent metadata');
        return new Response('No action taken', 200);
      }
      
      $order_id = $payment_intent->metadata->order_id;
      
      // Load the order
      $order = Order::load($order_id);
      if (!$order) {
        SafeLogging::log($this->logger, 'Order not found: @order_id', ['@order_id' => $order_id]);
        return new Response('Order not found', 200);
      }
      
      // Check if the order is already completed
      if ($order->getState()->getId() === 'completed') {
        SafeLogging::log($this->logger, 'Order already completed: @order_id', ['@order_id' => $order_id]);
        return new Response('Order already processed', 200);
      }
      
      // Update order state to completed
      $transition = $order->getState()->getWorkflow()->getTransition('place');
      if ($transition) {
        $order->getState()->applyTransition($transition);
        $order->save();
        
        SafeLogging::log($this->logger, 'Order completed via webhook: @order_id', ['@order_id' => $order_id]);
      }
      else {
        SafeLogging::log($this->logger, 'Cannot transition order @order_id to completed state', ['@order_id' => $order_id]);
      }
      
      return new Response('Order processed', 200);
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error processing payment_intent.succeeded: @message', ['@message' => $e->getMessage()]);
      return new Response('Processing error', 500);
    }
  }

  /**
   * Handles payment_intent.payment_failed events.
   *
   * @param \Stripe\PaymentIntent $payment_intent
   *   The payment intent object.
   * @param string $account_id
   *   The connected account ID, if any.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  protected function handlePaymentIntentFailed(\Stripe\PaymentIntent $payment_intent, $account_id = null) {
    try {
      // Check if this payment is related to an order
      if (!isset($payment_intent->metadata->order_id)) {
        SafeLogging::log($this->logger, 'No order ID in payment intent metadata');
        return new Response('No action taken', 200);
      }
      
      $order_id = $payment_intent->metadata->order_id;
      
      // Load the order
      $order = Order::load($order_id);
      if (!$order) {
        SafeLogging::log($this->logger, 'Order not found: @order_id', ['@order_id' => $order_id]);
        return new Response('Order not found', 200);
      }
      
      // Log payment failure
      SafeLogging::log($this->logger, 'Payment failed for order @order_id: @error', [
        '@order_id' => $order_id,
        '@error' => $payment_intent->last_payment_error ? $payment_intent->last_payment_error->message : 'Unknown error',
      ]);
      
      return new Response('Payment failure recorded', 200);
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error processing payment_intent.payment_failed: @message', ['@message' => $e->getMessage()]);
      return new Response('Processing error', 500);
    }
  }

  /**
   * Handles charge.refunded events.
   *
   * @param \Stripe\Charge $charge
   *   The charge object.
   * @param string $account_id
   *   The connected account ID, if any.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  protected function handleChargeRefunded(\Stripe\Charge $charge, $account_id = null) {
    try {
      // Check if this charge is related to an order
      if (!isset($charge->metadata->order_id)) {
        SafeLogging::log($this->logger, 'No order ID in charge metadata');
        return new Response('No action taken', 200);
      }
      
      $order_id = $charge->metadata->order_id;
      
      // Load the order
      $order = Order::load($order_id);
      if (!$order) {
        SafeLogging::log($this->logger, 'Order not found: @order_id', ['@order_id' => $order_id]);
        return new Response('Order not found', 200);
      }
      
      // Log refund
      SafeLogging::log($this->logger, 'Charge refunded for order @order_id: @amount', [
        '@order_id' => $order_id,
        '@amount' => $charge->amount_refunded / 100,
      ]);
      
      return new Response('Refund recorded', 200);
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error processing charge.refunded: @message', ['@message' => $e->getMessage()]);
      return new Response('Processing error', 500);
    }
  }

  /**
   * Handles payout events.
   *
   * @param \Stripe\Payout $payout
   *   The payout object.
   * @param string $event_type
   *   The event type.
   * @param string $account_id
   *   The connected account ID, if any.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  protected function handlePayoutEvent(\Stripe\Payout $payout, $event_type, $account_id = null) {
    try {
      // Get the account ID either from the payout destination or from the event
      $connection_id = $payout->destination ?: $account_id;
      
      if (empty($connection_id)) {
        SafeLogging::log($this->logger, 'No account ID identified for payout event');
        return new Response('No action taken', 200);
      }
      
      // Find the vendor user
      $vendor_id = $this->getVendorIdFromStripeAccount($connection_id);
      if (!$vendor_id) {
        SafeLogging::log($this->logger, 'Vendor not found for Stripe account: @account_id', ['@account_id' => $connection_id]);
        return new Response('Vendor not found', 200);
      }
      
      // Track the payout using the PayoutService
      $this->payoutService->trackPayout($payout, $connection_id, $vendor_id, $event_type);
      
      // Log the event with detailed information
      SafeLogging::log($this->logger, '@event for vendor @vendor_id: @amount @currency (status: @status)', [
        '@event' => $event_type,
        '@vendor_id' => $vendor_id,
        '@amount' => $payout->amount / 100,
        '@currency' => strtoupper($payout->currency),
        '@status' => $payout->status,
      ]);
      
      // Additional handling based on event type
      switch ($event_type) {
        case 'payout.created':
          // A new payout has been created
          $this->notifyPayoutCreated($vendor_id, $payout);
          break;
        
        case 'payout.paid':
          // A payout has successfully been deposited into the vendor's bank account
          $this->notifyPayoutPaid($vendor_id, $payout);
          break;
        
        case 'payout.failed':
          // A payout failed to be deposited into the vendor's bank account
          $this->notifyPayoutFailed($vendor_id, $payout);
          break;
      }
      
      return new Response('Payout event processed', 200);
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error processing payout event: @message', ['@message' => $e->getMessage()]);
      return new Response('Processing error', 500);
    }
  }

  /**
   * Notifies a vendor that a payout has been created.
   *
   * @param int $vendor_id
   *   The vendor user ID.
   * @param \Stripe\Payout $payout
   *   The payout object.
   */
  protected function notifyPayoutCreated($vendor_id, \Stripe\Payout $payout) {
    // Load the vendor
    $vendor = $this->entityTypeManager->getStorage('user')->load($vendor_id);
    if (!$vendor) {
      return;
    }
    
    // Add a message to the vendor's dashboard message queue
    $message = $this->t('A new payout of @amount @currency has been initiated and should arrive in your bank account within 1-2 business days.', [
      '@amount' => number_format($payout->amount / 100, 2),
      '@currency' => strtoupper($payout->currency),
    ]);
    
    $this->messenger()->addMessage($message, 'vendor_' . $vendor_id);
    
    // Placeholder for email notification to the vendor
  }

  /**
   * Notifies a vendor that a payout has been paid.
   *
   * @param int $vendor_id
   *   The vendor user ID.
   * @param \Stripe\Payout $payout
   *   The payout object.
   */
  protected function notifyPayoutPaid($vendor_id, \Stripe\Payout $payout) {
    // Load the vendor
    $vendor = $this->entityTypeManager->getStorage('user')->load($vendor_id);
    if (!$vendor) {
      return;
    }
    
    // Add a message to the vendor's dashboard message queue
    $message = $this->t('Your payout of @amount @currency has been deposited in your bank account.', [
      '@amount' => number_format($payout->amount / 100, 2),
      '@currency' => strtoupper($payout->currency),
    ]);
    
    $this->messenger()->addMessage($message, 'vendor_' . $vendor_id);
    
    // Placeholder for email notification to the vendor
  }

  /**
   * Notifies a vendor that a payout has failed.
   *
   * @param int $vendor_id
   *   The vendor user ID.
   * @param \Stripe\Payout $payout
   *   The payout object.
   */
  protected function notifyPayoutFailed($vendor_id, \Stripe\Payout $payout) {
    // Load the vendor
    $vendor = $this->entityTypeManager->getStorage('user')->load($vendor_id);
    if (!$vendor) {
      return;
    }
    
    // Add a message to the vendor's dashboard message queue
    $message = $this->t('Your payout of @amount @currency has failed. Please check your Stripe dashboard for more information.', [
      '@amount' => number_format($payout->amount / 100, 2),
      '@currency' => strtoupper($payout->currency),
    ]);
    
    $this->messenger()->addMessage($message, 'vendor_' . $vendor_id);
    
    // Create an alert for the platform admin
    SafeLogging::log($this->logger, 'Payout failed for vendor @vendor_id (@name): @amount @currency', [
      '@vendor_id' => $vendor_id,
      '@name' => $vendor->getDisplayName(),
      '@amount' => number_format($payout->amount / 100, 2),
      '@currency' => strtoupper($payout->currency),
    ]);
    
    // Placeholder email notification to the vendor and admin
  }

  /**
   * Handles account.updated events.
   *
   * @param \Stripe\Account $account
   *   The account object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  protected function handleAccountUpdated(\Stripe\Account $account) {
    try {
      // Find the vendor user
      $vendor_id = $this->getVendorIdFromStripeAccount($account->id);
      if (!$vendor_id) {
        SafeLogging::log($this->logger, 'Vendor not found for Stripe account: @account_id', ['@account_id' => $account->id]);
        return new Response('Vendor not found', 200);
      }
      
      // Load the vendor user
      $vendor = $this->entityTypeManager->getStorage('user')->load($vendor_id);
      
      // Update vendor status based on account details
      if ($vendor->hasField('field_vendor_status')) {
        $current_status = $vendor->get('field_vendor_status')->value;
        $new_status = $current_status;
        
        // Update status based on charges_enabled and payouts_enabled
        if ($account->charges_enabled && $account->payouts_enabled && $account->details_submitted) {
          $new_status = 'active';
        }
        elseif (!$account->details_submitted) {
          $new_status = 'pending';
        }
        
        // Only update if status changed
        if ($new_status !== $current_status) {
          $vendor->set('field_vendor_status', $new_status);
          $vendor->save();
          
          SafeLogging::log($this->logger, 'Vendor @vendor_id status updated from @old to @new', [
            '@vendor_id' => $vendor_id,
            '@old' => $current_status,
            '@new' => $new_status,
          ]);
        }
      }
      
      return new Response('Account update processed', 200);
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error processing account.updated: @message', ['@message' => $e->getMessage()]);
      return new Response('Processing error', 500);
    }
  }

  /**
   * Gets the vendor user ID from a Stripe account ID.
   *
   * @param string $account_id
   *   The Stripe account ID.
   *
   * @return int|null
   *   The vendor user ID, or NULL if not found.
   */
  protected function getVendorIdFromStripeAccount($account_id) {
    try {
      $users = $this->entityTypeManager->getStorage('user')->loadByProperties([
        'field_stripe_account_id' => $account_id,
        'status' => 1,
      ]);
      
      if (!empty($users)) {
        $user = reset($users);
        return $user->id();
      }
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error looking up vendor: @message', ['@message' => $e->getMessage()]);
    }
    
    return NULL;
  }
}
