<?php

namespace Drupal\stripe_connect_marketplace\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides access control for vendor store-related routes.
 */
class VendorStoreAccess implements AccessInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * Constructs a new VendorStoreAccess object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request_stack
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentRequest = $request_stack->getCurrentRequest();
  }

  /**
   * Custom access check for vendor to access store dashboard.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check access for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkVendorStoreDashboardAccess(Route $route, AccountInterface $account) {
    // If user has admin permission, always allow
    if ($account->hasPermission('administer commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    // For vendors, check if they have access to view own stores
    if ($account->hasRole('vendor') && $account->hasPermission('view own commerce_store')) {
      // Explicitly load the account to check if they have any stores
      $user = $this->entityTypeManager->getStorage('user')->load($account->id());
      
      // Count their stores
      $query = $this->entityTypeManager->getStorage('commerce_store')->getQuery()
        ->condition('uid', $user->id())
        ->accessCheck(FALSE);
      
      $count = $query->count()->execute();
      
      if ($count > 0) {
        return AccessResult::allowed()
          ->cachePerUser()
          ->addCacheableDependency($user);
      }
      
      // They can create a store if they have the permission
      if ($account->hasPermission('create commerce_store')) {
        return AccessResult::allowed()->cachePerPermissions();
      }
    }
    
    return AccessResult::forbidden()->cachePerPermissions();
  }

  /**
   * Custom access check for vendor to access specific store.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route to check access for.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   * @param int $commerce_store
   *   The store ID from the route.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkVendorStoreAccess(Route $route, AccountInterface $account, $commerce_store = NULL) {
    // If user has admin permission, always allow
    if ($account->hasPermission('administer commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // For vendors, check if they own this store
    if ($account->hasRole('vendor') && $account->hasPermission('view own commerce_store')) {
      if (empty($commerce_store)) {
        // If no store ID is specified, use store ID from request if available
        if ($this->currentRequest->get('commerce_store')) {
          $commerce_store = $this->currentRequest->get('commerce_store');
        }
      }
      
      // If we have a store ID, check ownership
      if (!empty($commerce_store)) {
        $store = $this->entityTypeManager->getStorage('commerce_store')->load($commerce_store);
        
        if ($store && $store->getOwnerId() == $account->id()) {
          return AccessResult::allowed()
            ->cachePerUser()
            ->addCacheableDependency($store);
        }
        
        // This isn't their store
        return AccessResult::forbidden()
          ->cachePerUser()
          ->addCacheableDependency($store);
      }
      
      // Fallback to allowed for collection access
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    return AccessResult::forbidden()->cachePerPermissions();
  }
}
