<?php

namespace Drupal\stripe_connect_marketplace\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controls access to vendor store settings routes.
 */
class VendorStoreAccess implements AccessInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs a new VendorStoreAccess object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RequestStack $request_stack,
    RouteMatchInterface $route_match
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->requestStack = $request_stack;
    $this->routeMatch = $route_match;
  }

  /**
   * Checks access for vendor store pages.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkVendorStoreAccess(AccountInterface $account) {
    // Admin always has access
    if ($account->hasPermission('administer commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // Check if user is a vendor
    if (!$account->hasRole('vendor')) {
      return AccessResult::forbidden()->cachePerUser();
    }
    
    // Get the store ID from the route
    $store_id = $this->routeMatch->getParameter('store_id');
    if (!$store_id) {
      return AccessResult::forbidden();
    }
    
    // Load the store entity
    try {
      $store = $this->entityTypeManager->getStorage('commerce_store')->load($store_id);
      if (!$store) {
        return AccessResult::forbidden();
      }
      
      // Check if the current user is the owner of the store
      if ($store->getOwnerId() == $account->id()) {
        $route_name = $this->routeMatch->getRouteName();
        
        // Check for specific permissions based on route
        switch ($route_name) {
          case 'stripe_connect_marketplace.vendor_store_payment_gateways':
            return AccessResult::allowedIfHasPermission($account, 'manage own store payment gateways')
              ->cachePerPermissions()
              ->cachePerUser()
              ->addCacheableDependency($store);
              
          case 'stripe_connect_marketplace.vendor_store_tax_settings':
            return AccessResult::allowedIfHasPermission($account, 'manage own store tax settings')
              ->cachePerPermissions()
              ->cachePerUser()
              ->addCacheableDependency($store);
              
          case 'stripe_connect_marketplace.vendor_store_shipping_methods':
            return AccessResult::allowedIfHasPermission($account, 'manage own store shipping methods')
              ->cachePerPermissions()
              ->cachePerUser()
              ->addCacheableDependency($store);
              
          case 'stripe_connect_marketplace.vendor_store_inventory':
            return AccessResult::allowedIfHasPermission($account, 'manage own store inventory settings')
              ->cachePerPermissions()
              ->cachePerUser()
              ->addCacheableDependency($store);
              
          default:
            // General store settings access
            return AccessResult::allowedIfHasPermission($account, 'manage own store settings')
              ->cachePerPermissions()
              ->cachePerUser()
              ->addCacheableDependency($store);
        }
      }
      
      return AccessResult::forbidden()
        ->cachePerUser()
        ->addCacheableDependency($store);
    }
    catch (\Exception $e) {
      return AccessResult::forbidden();
    }
  }

  /**
   * Special access check for vendor store dashboard.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkVendorDashboardAccess(AccountInterface $account) {
    // Admin always has access
    if ($account->hasPermission('administer commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // Check if user is a vendor
    if (!$account->hasRole('vendor')) {
      return AccessResult::forbidden()->cachePerUser();
    }
    
    // Vendor must have permission to view own dashboard
    return AccessResult::allowedIfHasPermission($account, 'view own vendor dashboard')
      ->cachePerPermissions()
      ->cachePerUser();
  }
}
