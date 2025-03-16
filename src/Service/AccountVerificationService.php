<?php

namespace Drupal\stripe_connect_marketplace\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\stripe_connect_marketplace\StripeApiService;

/**
 * Service for verifying vendor Stripe accounts and detecting deleted accounts.
 */
class AccountVerificationService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

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
   * Constructs a new AccountVerificationService.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state,
    StripeApiService $stripe_api
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
    $this->state = $state;
    $this->stripeApi = $stripe_api;
  }

  /**
   * Verifies a batch of vendor accounts.
   *
   * @param int $limit
   *   Maximum number of accounts to check in one batch.
   *
   * @return array
   *   Statistics about account verifications.
   */
  public function verifyVendorAccounts($limit = 10) {
    $stats = [
      'checked' => 0,
      'updated' => 0,
      'deleted' => 0,
      'errors' => 0,
    ];

    // Get the last user ID we checked
    $last_uid = $this->state->get('stripe_connect_marketplace.last_verified_uid', 0);

    // Query users with Stripe accounts
    $query = $this->entityTypeManager->getStorage('user')->getQuery();
    $query->condition('field_stripe_account_id', '', '<>');
    $query->condition('status', 1);
    if ($last_uid > 0) {
      $query->condition('uid', $last_uid, '>');
    }
    $query->sort('uid', 'ASC');
    $query->range(0, $limit);
    $query->accessCheck(FALSE);
    $uids = $query->execute();

    if (empty($uids)) {
      // Start over from the beginning next time
      $this->state->set('stripe_connect_marketplace.last_verified_uid', 0);
      return $stats;
    }

    // Load the users
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);

    foreach ($users as $user) {
      $stats['checked']++;

      // Update last uid for next run
      $this->state->set('stripe_connect_marketplace.last_verified_uid', $user->id());

      // Skip if the user doesn't have a Stripe account ID
      if (!$user->hasField('field_stripe_account_id') || $user->get('field_stripe_account_id')->isEmpty()) {
        continue;
      }

      $account_id = $user->get('field_stripe_account_id')->value;

      try {
        // Try to retrieve the account
        $account = $this->stripeApi->retrieve('Account', $account_id);

        // Update status based on account details
        if ($user->hasField('field_vendor_status')) {
          $current_status = $user->get('field_vendor_status')->value;
          $new_status = $current_status;

          if ($account->charges_enabled && $account->payouts_enabled && $account->details_submitted) {
            $new_status = 'active';
          }
          elseif (!$account->details_submitted) {
            $new_status = 'pending';
          }

          // Only update if status changed
          if ($new_status !== $current_status) {
            $user->set('field_vendor_status', $new_status);
            $user->save();

            $this->logger->info('Updated vendor @uid status from @old to @new', [
              '@uid' => $user->id(),
              '@old' => $current_status,
              '@new' => $new_status,
            ]);

            $stats['updated']++;
          }
        }
      }
      catch (\Exception $e) {
        // Check if this is an account not found error
        if (strpos($e->getMessage(), 'No such account') !== FALSE ||
            strpos($e->getMessage(), 'resource_missing') !== FALSE) {

          // Account has been deleted in Stripe
          if ($user->hasField('field_vendor_status')) {
            $user->set('field_vendor_status', 'deleted');
            $user->save();

            $this->logger->warning('Vendor @uid Stripe account @account_id has been deleted. Updated status.', [
              '@uid' => $user->id(),
              '@account_id' => $account_id,
            ]);

            // Track the deleted account
            $deleted_accounts = $this->state->get('stripe_connect_marketplace.deleted_accounts', []);
            $deleted_accounts[$account_id] = [
              'uid' => $user->id(),
              'detected_at' => time(),
            ];
            $this->state->set('stripe_connect_marketplace.deleted_accounts', $deleted_accounts);

            $stats['deleted']++;
          }
        }
        else {
          // This is some other error
          $this->logger->error('Error verifying account @id for vendor @uid: @message', [
            '@id' => $account_id,
            '@uid' => $user->id(),
            '@message' => $e->getMessage(),
          ]);
          $stats['errors']++;
        }
      }
    }

    return $stats;
  }

  /**
   * Mark a user's Stripe account as deleted.
   *
   * @param string $account_id
   *   The Stripe account ID.
   *
   * @return bool
   *   TRUE if account was marked as deleted, FALSE otherwise.
   */
  public function markAccountDeleted($account_id) {
    // Find the user with this account ID
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties([
      'field_stripe_account_id' => $account_id,
      'status' => 1,
    ]);

    if (empty($users)) {
      return FALSE;
    }

    $user = reset($users);

    // Update the vendor status
    if ($user->hasField('field_vendor_status')) {
      $user->set('field_vendor_status', 'deleted');
      $user->save();

      // Track the deleted account
      $deleted_accounts = $this->state->get('stripe_connect_marketplace.deleted_accounts', []);
      $deleted_accounts[$account_id] = [
        'uid' => $user->id(),
        'detected_at' => time(),
      ];
      $this->state->set('stripe_connect_marketplace.deleted_accounts', $deleted_accounts);

      $this->logger->warning('Marked vendor @uid Stripe account @account_id as deleted.', [
        '@uid' => $user->id(),
        '@account_id' => $account_id,
      ]);

      return TRUE;
    }

    return FALSE;
  }
}
