<?php

namespace Drupal\stripe_connect_marketplace;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_product\Entity\ProductInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Provides access control methods for vendor entities.
 */
class VendorAccessService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new VendorAccessService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * Gets the store IDs owned by a user.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Array of store IDs owned by the user.
   */
  public function getUserStoreIds($uid) {
    $query = $this->entityTypeManager->getStorage('commerce_store')->getQuery()
      ->condition('uid', $uid)
      ->accessCheck(FALSE);
    
    return $query->execute();
  }

  /**
   * Determines if a user has access to a store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store.
   * @param string $operation
   *   The operation being performed.
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function storeAccess(StoreInterface $store, $operation, AccountProxyInterface $account) {
    // Admin can do anything
    if ($account->hasPermission('administer commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // Vendors can only access their own stores
    if ($account->hasRole('vendor')) {
      // For 'view' operation, check the view permission
      if ($operation == 'view' && !$account->hasPermission('view own commerce_store')) {
        return AccessResult::neutral()->cachePerPermissions();
      }
      
      // For 'update' operation, check the update permission
      if ($operation == 'update' && !$account->hasPermission('update own commerce_store')) {
        return AccessResult::neutral()->cachePerPermissions();
      }
      
      // For 'delete' operation, check the delete permission
      if ($operation == 'delete' && !$account->hasPermission('delete own commerce_store')) {
        return AccessResult::neutral()->cachePerPermissions();
      }
      
      // Check store ownership
      if ($store->getOwnerId() == $account->id()) {
        return AccessResult::allowed()
          ->cachePerUser()
          ->addCacheableDependency($store);
      }
      
      return AccessResult::forbidden()
        ->cachePerUser()
        ->addCacheableDependency($store);
    }
    
    return AccessResult::neutral();
  }

  /**
   * Determines if a user has access to a product.
   *
   * @param \Drupal\commerce_product\Entity\ProductInterface $product
   *   The product.
   * @param string $operation
   *   The operation being performed.
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function productAccess(ProductInterface $product, $operation, AccountProxyInterface $account) {
    // Admin can do anything
    if ($account->hasPermission('administer commerce_product')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // Vendors can only access products in their stores
    if ($account->hasRole('vendor')) {
      // For 'view' operation, check the view permission
      if ($operation == 'view' && !$account->hasPermission('view own commerce_product')) {
        return AccessResult::neutral()->cachePerPermissions();
      }
      
      // For 'update' operation, check the update permission
      if ($operation == 'update' && !$account->hasPermission('update own commerce_product')) {
        return AccessResult::neutral()->cachePerPermissions();
      }
      
      // For 'delete' operation, check the delete permission
      if ($operation == 'delete' && !$account->hasPermission('delete own commerce_product')) {
        return AccessResult::neutral()->cachePerPermissions();
      }
      
      // Check if product belongs to any of the user's stores
      $user_store_ids = $this->getUserStoreIds($account->id());
      $product_store_ids = [];
      
      foreach ($product->getStores() as $store) {
        $product_store_ids[] = $store->id();
      }
      
      // If there's an intersection, the user owns at least one of the stores
      if (!empty(array_intersect($product_store_ids, $user_store_ids))) {
        return AccessResult::allowed()
          ->cachePerUser()
          ->addCacheableDependency($product);
      }
      
      return AccessResult::forbidden()
        ->cachePerUser()
        ->addCacheableDependency($product);
    }
    
    return AccessResult::neutral();
  }

  /**
   * Determines if a user has access to an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   * @param string $operation
   *   The operation being performed.
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function orderAccess(OrderInterface $order, $operation, AccountProxyInterface $account) {
    // Admin can do anything
    if ($account->hasPermission('administer commerce_order')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // Vendors can only access orders in their stores
    if ($account->hasRole('vendor')) {
      // For 'view' operation, check the view permission
      if ($operation == 'view' && !$account->hasPermission('view own commerce_order')) {
        return AccessResult::neutral()->cachePerPermissions();
      }
      
      // For 'update' operation, check the update permission
      if ($operation == 'update' && !$account->hasPermission('update own commerce_order')) {
        return AccessResult::neutral()->cachePerPermissions();
      }
      
      // For 'delete' operation, check the delete permission
      if ($operation == 'delete' && !$account->hasPermission('delete own commerce_order')) {
        return AccessResult::neutral()->cachePerPermissions();
      }
      
      // Check if order is for the user's store
      $store = $order->getStore();
      if ($store && $store->getOwnerId() == $account->id()) {
        return AccessResult::allowed()
          ->cachePerUser()
          ->addCacheableDependency($order)
          ->addCacheableDependency($store);
      }
      
      return AccessResult::forbidden()
        ->cachePerUser()
        ->addCacheableDependency($order);
    }
    
    return AccessResult::neutral();
  }

  /**
   * Filters a list of stores to those owned by the user.
   *
   * @param array $stores
   *   Array of store entities.
   * @param int $uid
   *   The user ID.
   *
   * @return array
   *   Filtered array of stores owned by the user.
   */
  public function filterUserStores(array $stores, $uid) {
    return array_filter($stores, function ($store) use ($uid) {
      return $store->getOwnerId() == $uid;
    });
  }
}
