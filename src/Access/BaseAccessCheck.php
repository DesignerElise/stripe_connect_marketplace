<?php

namespace Drupal\stripe_connect_marketplace\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides common permission checking methods to reduce duplication.
 */
abstract class BaseVendorAccessChecker {

  /**
   * Checks if an account has admin access to an entity type.
   *
   * @param string $entityType
   *   The entity type to check admin permission for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkAdminAccess($entityType, AccountInterface $account) {
    $permission = "administer {$entityType}";
    if ($account->hasPermission($permission)) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::neutral()->cachePerPermissions();
  }
  
  /**
   * Checks if an account has operation access to an entity type.
   *
   * @param string $entityType
   *   The entity type to check.
   * @param string $operation
   *   The operation to check (view, update, delete).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param bool $ownCheck
   *   Whether to check "own" permission.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkOperationAccess($entityType, $operation, AccountInterface $account, $ownCheck = FALSE) {
    $permission = $operation . ($ownCheck ? ' own ' : ' ') . $entityType;
    if ($account->hasPermission($permission)) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    return AccessResult::neutral()->cachePerPermissions();
  }
}
