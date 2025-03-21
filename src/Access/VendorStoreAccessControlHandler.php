<?php

namespace Drupal\stripe_connect_marketplace\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity\EntityAccessControlHandler;

/**
 * Controls access for vendors to commerce store entities.
 */
class VendorStoreAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\commerce_store\Entity\StoreInterface $entity */
    $account = $this->prepareUser($account);
    
    // First check if user has admin permission
    if ($account->hasPermission('administer commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // If the user has the "view any" permission, allow access.
    if ($operation === 'view' && $account->hasPermission('view commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // If the user has the update/delete any permission, allow access.
    if ($operation === 'update' && $account->hasPermission('update commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    if ($operation === 'delete' && $account->hasPermission('delete commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // For vendors, check if they're the owner of the store
    if ($account->hasRole('vendor')) {
      // Get the store owner ID
      $owner_id = $entity->getOwnerId();
      
      // Check for "view own" permission
      if ($operation === 'view' && $account->hasPermission('view own commerce_store') && $owner_id == $account->id()) {
        return AccessResult::allowed()
          ->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($entity);
      }
      
      // Check for "update own" permission
      if ($operation === 'update' && $account->hasPermission('update own commerce_store') && $owner_id == $account->id()) {
        return AccessResult::allowed()
          ->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($entity);
      }
      
      // Check for "delete own" permission
      if ($operation === 'delete' && $account->hasPermission('delete own commerce_store') && $owner_id == $account->id()) {
        return AccessResult::allowed()
          ->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($entity);
      }
    }
    
    // No opinion, let other access control handlers decide.
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Allow if user can administer stores
    if ($account->hasPermission('administer commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // Allow if user has the create stores permission
    if ($account->hasPermission('create commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // No opinion, let other access control handlers decide.
    return AccessResult::neutral();
  }
}
