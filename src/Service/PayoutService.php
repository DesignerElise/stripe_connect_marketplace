<?php

namespace Drupal\stripe_connect_marketplace\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\stripe_connect_marketplace\StripeApiService;

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
   * The Stripe API service.
   *
   * @var \Drupal\stripe_connect_marketplace\StripeApiService
   */
  protected $stripeApi;

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
   * @param \Drupal\stripe_connect_marketplace\StripeApiService $stripe_api
   *   The Stripe API service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    StateInterface $state,
    StripeApiService $stripe_api
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->entityTypeManager = $entity_type_manager;
    $this->state = $state;
    $this->stripeApi = $stripe_api;
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
   * @return \stdClass|object
   *   Collection of Stripe Payout objects or mock data.
   *
   * @throws \Exception
   */
  public function getVendorPayouts($account_id, $limit = 10, $starting_after = NULL) {
    try {
      $params = [
        'limit' => $limit,
        'expand' => ['data.destination'],
      ];
      
      if ($starting_after) {
        $params['starting_after'] = $starting_after;
      }
      
      // For connected accounts, we need to specify the account ID in options
      $options = ['stripe_account' => $account_id];
      
      // Check if we're in test mode - we need to create mock data; if the StripeAPI client is null 
      $client = $this->stripeApi->getClient();
      if (!$client) {
        // We're in test mode, return mock data
        $this->logger->notice('StripeAPI client is in test mode, returning mock payout data');
        
        // Create a mock payouts object
        $mock_payouts = new \stdClass();
        $mock_payouts->data = [];
        
        // Generate some sample payouts
        for ($i = 0; $i < $limit; $i++) {
          $payout = new \stdClass();
          $payout->id = 'po_mock_' . md5($account_id . $i);
          $payout->object = 'payout';
          $payout->amount = rand(10000, 100000); // Random amount between $100-$1000
          $payout->currency = 'usd';
          $payout->arrival_date = time() - (86400 * $i); // Staggered dates
          $payout->created = time() - (86400 * $i) - 3600;
          $payout->status = rand(0, 5) > 0 ? 'paid' : 'pending'; // Mostly paid, some pending
          $payout->type = 'bank_account';
          $payout->destination = 'ba_mock_account';
          
          $mock_payouts->data[] = $payout;
        }
        
        return $mock_payouts;
      }
      
      // Not in test mode, use the real API client
      $payouts = $client->payouts->all($params, $options);
      
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
      $payout_schedule = ['interval' => $interval] + $schedule_options;
      
      // Update the account with the new payout schedule using direct charges approach
      $params = [
        'settings' => ['payouts' => ['schedule' => $payout_schedule]]
      ];
      $options = ['stripe_account' => $account_id];
      
      // Update directly on the connected account
      $account = $this->stripeApi->update('Account', $account_id, $params, $options);
      
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
      // Convert amount to minor units
      $minor_amount = $this->toMinorUnits($amount, $currency);
      
      // For direct charges model, create the payout directly on the connected account
      $params = [
        'amount' => $minor_amount,
        'currency' => strtolower($currency),
        'description' => $description,
      ];
      $options = ['stripe_account' => $account_id];
      
      // Create the payout on the connected account
      $payout = $this->stripeApi->create('Payout', $params, $options);
      
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
   * @param string $event_type
   *   The event type that triggered this tracking.
   *
   * @return bool
   *   TRUE if tracking was successful, FALSE otherwise.
   */
  public function trackPayout(\Stripe\Payout $payout, $account_id, $vendor_id, $event_type = '') {
    try {
      // Get the tracking state
      $payouts_tracking = $this->state->get('stripe_connect_marketplace.payouts_tracking', []);
      
      // Calculate the bank availability date
      $arrival_date = $payout->arrival_date ?? 0;
      $estimated_availability = $arrival_date ? $arrival_date : (time() + (2 * 86400)); // Default to 2 days if not provided
      
      // Add new payout data with direct charges specific information
      $payouts_tracking[$payout->id] = [
        'id' => $payout->id,
        'account_id' => $account_id,
        'vendor_id' => $vendor_id,
        'amount' => $payout->amount / 100, // Convert back to major units
        'currency' => $payout->currency,
        'status' => $payout->status,
        'created' => $payout->created,
        'arrival_date' => $arrival_date,
        'estimated_availability' => $estimated_availability,
        'destination' => $payout->destination,
        'failure_code' => $payout->failure_code ?? '',
        'failure_message' => $payout->failure_message ?? '',
        'last_event' => $event_type,
        'last_event_time' => time(),
        'payment_model' => 'direct_charge', // Mark that this is from direct charge model
      ];
      
      // Save updated tracking data
      $this->state->set('stripe_connect_marketplace.payouts_tracking', $payouts_tracking);
      
      $this->logger->info('Payout tracked: @id for vendor @vendor_id (event: @event)', [
        '@id' => $payout->id,
        '@vendor_id' => $vendor_id,
        '@event' => $event_type,
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
          // Handle special filter cases
          if ($key === 'date_range') {
            if (isset($value['start']) && $payout['created'] < $value['start']) {
              return FALSE;
            }
            if (isset($value['end']) && $payout['created'] > $value['end']) {
              return FALSE;
            }
          }
          elseif ($key === 'min_amount' && isset($payout['amount']) && $payout['amount'] < $value) {
            return FALSE;
          }
          elseif ($key === 'max_amount' && isset($payout['amount']) && $payout['amount'] > $value) {
            return FALSE;
          }
          elseif (isset($payout[$key]) && $payout[$key] != $value) {
            // Standard equality filter
            return FALSE;
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
   * Gets application fee data for a connected account.
   *
   * @param string $account_id
   *   The vendor's Stripe account ID.
   * @param array $params
   *   Query parameters for filtering (date, limit, etc.).
   *
   * @return array
   *   Array of application fee data.
   */
  public function getApplicationFees($account_id, array $params = []) {
    try {
      // Set default parameters
      $default_params = [
        'limit' => 25,
      ];
      $query_params = array_merge($default_params, $params);
      
      // For direct charges, fees are collected from connected accounts
      if ($account_id) {
        $query_params['connected_account'] = $account_id;
      }
      
      // Get application fees data
      $fees = $this->stripeApi->getClient()->applicationFees->all($query_params);
      
      // Format the fees data for use in Drupal
      $formatted_fees = [];
      foreach ($fees->data as $fee) {
        $formatted_fees[] = [
          'id' => $fee->id,
          'account_id' => $fee->account,
          'amount' => $fee->amount / 100,
          'currency' => $fee->currency,
          'created' => $fee->created,
          'charge_id' => $fee->charge,
          'refunded' => $fee->refunded,
          'amount_refunded' => $fee->amount_refunded / 100,
        ];
      }
      
      return $formatted_fees;
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $this->logger->error('Stripe API Error fetching application fees: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Gets payout summary data for a connected account.
   *
   * @param string $account_id
   *   The vendor's Stripe account ID.
   * @param int $start_date
   *   Unix timestamp for the start date.
   * @param int $end_date
   *   Unix timestamp for the end date.
   *
   * @return array
   *   Array with summary data like total_paid, total_pending, etc.
   */
  public function getPayoutSummary($account_id, $start_date = NULL, $end_date = NULL) {
    // Get payouts that match the date range
    $filters = [];
    if ($start_date || $end_date) {
      $filters['date_range'] = [];
      if ($start_date) {
        $filters['date_range']['start'] = $start_date;
      }
      if ($end_date) {
        $filters['date_range']['end'] = $end_date;
      }
    }
    
    $payouts = $this->getTrackedPayouts($account_id ? $this->getVendorIdFromAccount($account_id) : NULL, $filters);
    
    // Calculate summary data
    $summary = [
      'total_count' => count($payouts),
      'total_paid' => 0,
      'total_pending' => 0,
      'total_failed' => 0,
      'currencies' => [],
      'status_counts' => [
        'paid' => 0,
        'pending' => 0,
        'in_transit' => 0,
        'canceled' => 0,
        'failed' => 0,
      ],
    ];
    
    foreach ($payouts as $payout) {
      // Initialize currency if not exists
      $currency = strtoupper($payout['currency']);
      if (!isset($summary['currencies'][$currency])) {
        $summary['currencies'][$currency] = [
          'total_paid' => 0,
          'total_pending' => 0,
          'total_failed' => 0,
        ];
      }
      
      // Track status counts
      if (isset($summary['status_counts'][$payout['status']])) {
        $summary['status_counts'][$payout['status']]++;
      }
      
      // Track amounts by status
      if ($payout['status'] === 'paid') {
        $summary['total_paid'] += $payout['amount'];
        $summary['currencies'][$currency]['total_paid'] += $payout['amount'];
      }
      elseif ($payout['status'] === 'failed') {
        $summary['total_failed'] += $payout['amount'];
        $summary['currencies'][$currency]['total_failed'] += $payout['amount'];
      }
      else {
        // Any other status is considered pending
        $summary['total_pending'] += $payout['amount'];
        $summary['currencies'][$currency]['total_pending'] += $payout['amount'];
      }
    }
    
    return $summary;
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

  /**
   * Gets the vendor ID from a Stripe account ID.
   *
   * @param string $account_id
   *   The Stripe account ID.
   *
   * @return int|null
   *   The vendor user ID, or NULL if not found.
   */
  protected function getVendorIdFromAccount($account_id) {
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
