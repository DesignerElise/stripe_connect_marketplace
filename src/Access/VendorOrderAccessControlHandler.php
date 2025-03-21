<?php

namespace Drupal\stripe_connect_marketplace\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity\EntityAccessControlHandler;

/**
 * Controls access for vendors to commerce order entities.
 */
class VendorOrderAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $entity */
    $account = $this->prepareUser($account);

    // Determine if this is a special operation that maps to standard operations
    $additional_operation = '';
    if ($operation == 'unlock') {
      $operation = 'update';
      $additional_operation = 'unlock';
    }
    elseif ($operation == 'resend_receipt') {
      if ($entity->getState()->getId() == 'draft') {
        return AccessResult::forbidden()->addCacheableDependency($entity);
      }
      $operation = 'view';
      $additional_operation = 'resend_receipt';
    }
    
    // First let the parent check access
    /** @var \Drupal\Core\Access\AccessResult $result */
    $result = parent::checkAccess($entity, $operation, $account);
    
    // First check if user has admin permission
    if ($account->hasPermission('administer commerce_order')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // For vendors, check if they own the store the order belongs to
    if ($account->hasRole('vendor') && $result->isNeutral()) {
      $store = $entity->getStore();
      
      // View operation check
      if ($operation === 'view' && $account->hasPermission('view own commerce_order')) {
        if ($store && $store->getOwnerId() == $account->id()) {
          $result = AccessResult::allowed()
            ->cachePerPermissions()
            ->cachePerUser()
            ->addCacheableDependency($entity)
            ->addCacheableDependency($store);
        }
      }
      
      // Update/delete operation check
      if (in_array($operation, ['update', 'delete'])) {
        // Handle locked orders logic (like in Commerce)
        if ($additional_operation == 'unlock') {
          // Check unlock operation.
          $result = AccessResult::allowedIf($entity->isLocked())
            ->andIf(AccessResult::allowedIfHasPermission($account, 'unlock orders'))
            ->andIf($result);
        }
        else {
          // Check update or delete operations.
          $result = AccessResult::allowedIf(!$entity->isLocked())->andIf($result);
        }
        
        // Check vendor-specific permissions
        if ($operation === 'update' && $account->hasPermission('update own commerce_order')) {
          if ($store && $store->getOwnerId() == $account->id()) {
            $result = AccessResult::allowed()
              ->cachePerPermissions()
              ->cachePerUser()
              ->addCacheableDependency($entity)
              ->addCacheableDependency($store);
          }
        }
      }
    }
    
    // Check for the customer's access to their own orders
    if ($result->isNeutral() && $operation == 'view') {
      if ($account->isAuthenticated() && $account->id() == $entity->getCustomerId() && empty($additional_operation)) {
        $result = AccessResult::allowedIfHasPermissions($account, ['view own commerce_order']);
        $result = $result->cachePerUser()->addCacheableDependency($entity);
      }
    }
    
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // Allow if user can administer orders
    if ($account->hasPermission('administer commerce_order')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // Allow if user has the create orders permission
    if ($account->hasPermission('create commerce_order')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // No opinion, let other access control handlers decide.
    return AccessResult::neutral();
  }
}
