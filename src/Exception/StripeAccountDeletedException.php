<?php

namespace Drupal\stripe_connect_marketplace\Exception;

/**
 * Exception thrown when a connected Stripe account is found to be deleted.
 */
class StripeAccountDeletedException extends \Exception {

  /**
   * The Stripe account ID that was deleted.
   *
   * @var string
   */
  protected $accountId;

  /**
   * Constructs a new StripeAccountDeletedException.
   *
   * @param string $message
   *   The Exception message.
   * @param string $account_id
   *   The Stripe account ID that was deleted.
   * @param int $code
   *   The Exception code.
   * @param \Throwable $previous
   *   The previous throwable used for exception chaining.
   */
  public function __construct($message, $account_id, $code = 0, \Throwable $previous = NULL) {
    parent::__construct($message, $code, $previous);
    $this->accountId = $account_id;
  }

  /**
   * Gets the Stripe account ID.
   *
   * @return string
   *   The Stripe account ID that was deleted.
   */
  public function getAccountId() {
    return $this->accountId;
  }

}
