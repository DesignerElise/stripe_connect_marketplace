<?php

namespace Drupal\stripe_connect_marketplace\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Service for handling Stripe Connect payouts.
 */
class PayoutService {

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
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a new PayoutService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    StateInterface $state
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
  }

  /**
   * Initializes the Stripe API with the appropriate key.
   */
  protected function initStripeApi() {
    $config = $this->configFactory->get('stripe_connect_marketplace.settings');
    $environment = $config->get('stripe_connect.environment');
    $secret_key = $environment == 'live' 
      ? $config->get('stripe_connect.live_secret_key') 
      : $config->get('stripe_connect.test_secret_key');
    
    if (empty($secret_key)) {
      throw new \Exception('Stripe API key is not configured.');
    }

    \Stripe\Stripe::setApiKey($secret_key);
  }

  /**
   * Get payouts for a vendor.
   *
   * @param string $account_id
   *   The vendor's Stripe account ID.
   * @param int $limit
   *   Maximum number of payouts to return.
   * @param string $starting_after
   *   Pagination cursor.
   *
   * @return \Stripe\Collection
   *   Collection of Stripe Payout objects.
   *
   * @throws \Exception
   */
  public function getVendorPayouts($account_id, $limit = 10, $starting_after = NULL) {
    try {
      $this->initStripeApi();
      
      $params = [
        'limit' => $limit,
        'expand' => ['data.destination'],
      ];
      
      if ($starting_after) {
        $params['starting_after'] = $starting_after;
      }
      
      // For connected accounts, we need to make the API call with the account specified
      $payouts = \Stripe\Payout::all(
        $params,
        ['stripe_account' => $account_id]
      );
      
      return $payouts;
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error('Stripe API Error: @message', ['@message' => $e->getMessage()]);
      throw new \Exception('Failed to retrieve payouts: ' . $e->getMessage(), $e->getCode(), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Payout retrieval error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Update payout schedule for a vendor.
   *
   * @param string $account_id
   *   The vendor's Stripe account ID.
   * @param string $interval
   *   The payout interval (daily, weekly, monthly).
   * @param array $schedule_options
   *   Additional schedule options.
   *
   * @return \Stripe\Account
   *   The updated Stripe account.
   *
   * @throws \Exception
   */
  public function updatePayoutSchedule($account_id, $interval, array $schedule_options = []) {
    try {
      $this->initStripeApi();
      
      $payout_schedule = ['interval' => $interval] + $schedule_options;
      
      $account = \Stripe\Account::update(
        $account_id,
        ['settings' => ['payouts' => ['schedule' => $payout_schedule]]]
      );
      
      $this->logger->info('Payout schedule updated for account @id: @interval', [
        '@id' => $account_id,
        '@interval' => $interval,
      ]);
      
      return $account;
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error('Stripe API Error: @message', ['@message' => $e->getMessage()]);
      throw new \Exception('Failed to update payout schedule: ' . $e->getMessage(), $e->getCode(), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Payout schedule update error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Create a manual payout for a vendor.
   *
   * @param string $account_id
   *   The vendor's Stripe account ID.
   * @param float $amount
   *   The payout amount.
   * @param string $currency
   *   The currency code.
   * @param string $description
   *   The payout description.
   *
   * @return \Stripe\Payout
   *   The created Stripe payout.
   *
   * @throws \Exception
   */
  public function createManualPayout($account_id, $amount, $currency = 'usd', $description = '') {
    try {
      $this->initStripeApi();
      
      // Convert amount to minor units
      $minor_amount = $this->toMinorUnits($amount, $currency);
      
      $payout = \Stripe\Payout::create([
        'amount' => $minor_amount,
        'currency' => strtolower($currency),
        'description' => $description,
      ], ['stripe_account' => $account_id]);
      
      $this->logger->info('Manual payout created for account @id: @amount @currency', [
        '@id' => $account_id,
        '@amount' => $amount,
        '@currency' => $currency,
      ]);
      
      return $payout;
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error('Stripe API Error: @message', ['@message' => $e->getMessage()]);
      throw new \Exception('Failed to create payout: ' . $e->getMessage(), $e->getCode(), $e);
    }
    catch (\Exception $e) {
      $this->logger->error('Payout creation error: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Track and store payout data for reporting.
   *
   * @param \Stripe\Payout $payout
   *   The Stripe payout object.
   * @param string $account_id
   *   The vendor's Stripe account ID.
   * @param int $vendor_id
   *   The vendor's user ID.
   *
   * @return bool
   *   TRUE if tracking was successful, FALSE otherwise.
   */
  public function trackPayout(\Stripe\Payout $payout, $account_id, $vendor_id) {
    try {
      // Get the tracking state
      $payouts_tracking = $this->state->get('stripe_connect_marketplace.payouts_tracking', []);
      
      // Add new payout data
      $payouts_tracking[$payout->id] = [
        'id' => $payout->id,
        'account_id' => $account_id,
        'vendor_id' => $vendor_id,
        'amount' => $payout->amount / 100, // Convert back to major units
        'currency' => $payout->currency,
        'status' => $payout->status,
        'created' => $payout->created,
        'arrival_date' => $payout->arrival_date,
      ];
      
      // Save updated tracking data
      $this->state->set('stripe_connect_marketplace.payouts_tracking', $payouts_tracking);
      
      $this->logger->info('Payout tracked: @id for vendor @vendor_id', [
        '@id' => $payout->id,
        '@vendor_id' => $vendor_id,
      ]);
      
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Payout tracking error: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Get tracked payouts for reporting.
   *
   * @param int|null $vendor_id
   *   The vendor's user ID, or NULL to get all payouts.
   * @param array $filters
   *   Additional filters like date range, status, etc.
   *
   * @return array
   *   Array of tracked payouts.
   */
  public function getTrackedPayouts($vendor_id = NULL, array $filters = []) {
    // Get all tracked payouts
    $all_payouts = $this->state->get('stripe_connect_marketplace.payouts_tracking', []);
    
    // If no vendor ID specified, return all payouts (possibly filtered)
    if ($vendor_id === NULL) {
      $payouts = $all_payouts;
    }
    else {
      // Filter by vendor ID
      $payouts = array_filter($all_payouts, function ($payout) use ($vendor_id) {
        return $payout['vendor_id'] == $vendor_id;
      });
    }
    
    // Apply additional filters
    if (!empty($filters)) {
      $payouts = array_filter($payouts, function ($payout) use ($filters) {
        foreach ($filters as $key => $value) {
          if (isset($payout[$key])) {
            // Handle date range filters
            if ($key == 'created_after' && $payout['created'] < $value) {
              return FALSE;
            }
            if ($key == 'created_before' && $payout['created'] > $value) {
              return FALSE;
            }
            // Handle exact match filters
            if ($key != 'created_after' && $key != 'created_before' && $payout[$key] != $value) {
              return FALSE;
            }
          }
        }
        return TRUE;
      });
    }
    
    // Sort by created date, newest first
    usort($payouts, function ($a, $b) {
      return $b['created'] - $a['created'];
    });
    
    return $payouts;
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
