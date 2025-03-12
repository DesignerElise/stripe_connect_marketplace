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
        $this->logger->warning('Missing webhook payload or signature header');
        return new Response('Bad request', 400);
      }
      
      // Get webhook secret from config
      $config = $this->configFactory->get('stripe_connect_marketplace.settings');
      $webhook_secret = $config->get('stripe_connect.webhook_secret');
      
      if (empty($webhook_secret)) {
        $this->logger->error('Webhook secret not configured');
        return new Response('Server error', 500);
      }
      
      // Verify webhook signature using Stripe library
      try {
        $event = $this->stripeApi->constructWebhookEvent(
          $payload, $sig_header, $webhook_secret
        );
      }
      catch (\UnexpectedValueException $e) {
        $this->logger->warning('Invalid webhook payload: @message', ['@message' => $e->getMessage()]);
        return new Response('Bad request', 400);
      }
      catch (\Stripe\Exception\SignatureVerificationException $e) {
        $this->logger->warning('Invalid webhook signature: @message', ['@message' => $e->getMessage()]);
        return new Response('Bad request', 400);
      }
      
      // Log the event type
      $this->logger->info('Processing Stripe webhook: @type', ['@type' => $event->type]);
      
      // Process the event based on its type
      switch ($event->type) {
        case 'payment_intent.succeeded':
          return $this->handlePaymentIntentSucceeded($event->data->object);
          
        case 'payment_intent.payment_failed':
          return $this->handlePaymentIntentFailed($event->data->object);
          
        case 'charge.refunded':
          return $this->handleChargeRefunded($event->data->object);
          
        case 'payout.created':
        case 'payout.paid':
        case 'payout.failed':
          return $this->handlePayoutEvent($event->data->object, $event->type);
          
        case 'account.updated':
          return $this->handleAccountUpdated($event->data->object);
          
        default:
          // Log but don't take action for other event types
          $this->logger->info('Unhandled webhook event: @type', ['@type' => $event->type]);
          return new Response('Event received', 200);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Webhook processing error: @message', ['@message' => $e->getMessage()]);
      return new Response('Server error', 500);
    }
  }

  /**
   * Handles payment_intent.succeeded events.
   *
   * @param \Stripe\PaymentIntent $payment_intent
   *   The payment intent object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  protected function handlePaymentIntentSucceeded(\Stripe\PaymentIntent $payment_intent) {
    try {
      // Check if this payment is related to an order
      if (!isset($payment_intent->metadata->order_id)) {
        $this->logger->info('No order ID in payment intent metadata');
        return new Response('No action taken', 200);
      }
      
      $order_id = $payment_intent->metadata->order_id;
      
      // Load the order
      $order = Order::load($order_id);
      if (!$order) {
        $this->logger->warning('Order not found: @order_id', ['@order_id' => $order_id]);
        return new Response('Order not found', 200);
      }
      
      // Check if the order is already completed
      if ($order->getState()->getId() === 'completed') {
        $this->logger->info('Order already completed: @order_id', ['@order_id' => $order_id]);
        return new Response('Order already processed', 200);
      }
      
      // Update order state to completed
      $transition = $order->getState()->getWorkflow()->getTransition('place');
      if ($transition) {
        $order->getState()->applyTransition($transition);
        $order->save();
        
        $this->logger->info('Order completed via webhook: @order_id', ['@order_id' => $order_id]);
      }
      else {
        $this->logger->warning('Cannot transition order @order_id to completed state', ['@order_id' => $order_id]);
      }
      
      return new Response('Order processed', 200);
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing payment_intent.succeeded: @message', ['@message' => $e->getMessage()]);
      return new Response('Processing error', 500);
    }
  }

  // Other methods like handlePaymentIntentFailed, handleChargeRefunded, 
  // handlePayoutEvent, and handleAccountUpdated would be added here similar 
  // to the implementation of handlePaymentIntentSucceeded

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
      $this->logger->error('Error looking up vendor: @message', ['@message' => $e->getMessage()]);
    }
    
    return NULL;
  }
}
