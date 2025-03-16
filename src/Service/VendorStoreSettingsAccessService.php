<?php

namespace Drupal\stripe_connect_marketplace\Service;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\stripe_connect_marketplace\Utility\SafeLogging;

/**
 * Provides access control for vendor store settings.
 */
class VendorStoreSettingsAccessService {

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
   * Constructs a new VendorStoreSettingsAccessService.
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
   * Determines if a vendor has access to a specific store setting.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   * @param string $setting_type
   *   The type of store setting (e.g., 'payment_gateways', 'tax', 'shipping').
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function hasStoreSettingAccess(StoreInterface $store, $setting_type) {
    // Always allow for store admins
    if ($this->currentUser->hasPermission('administer commerce_store')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // Verify the user is a vendor and owns this store
    if ($this->currentUser->hasRole('vendor') && 
        $store->getOwnerId() == $this->currentUser->id()) {
      
      // Check for specific store setting permissions
      switch ($setting_type) {
        case 'payment_gateways':
          return AccessResult::allowedIf($this->currentUser->hasPermission('manage own store payment gateways'))
            ->cachePerPermissions()
            ->addCacheableDependency($store);
        
        case 'tax':
          return AccessResult::allowedIf($this->currentUser->hasPermission('manage own store tax settings'))
            ->cachePerPermissions()
            ->addCacheableDependency($store);
            
        case 'shipping':
          return AccessResult::allowedIf($this->currentUser->hasPermission('manage own store shipping methods'))
            ->cachePerPermissions()
            ->addCacheableDependency($store);
            
        case 'inventory':
          return AccessResult::allowedIf($this->currentUser->hasPermission('manage own store inventory settings'))
            ->cachePerPermissions()
            ->addCacheableDependency($store);
            
        default:
          // For any other settings, check for a generic permission
          return AccessResult::allowedIf($this->currentUser->hasPermission('manage own store settings'))
            ->cachePerPermissions()
            ->addCacheableDependency($store);
      }
    }
    
    // Default deny access
    return AccessResult::forbidden()->cachePerPermissions();
  }

  /**
   * Gets an array of Commerce settings the current vendor can access.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   *
   * @return array
   *   Array of accessible settings keyed by setting_type.
   */
  public function getAccessibleSettings(StoreInterface $store) {
    $settings = [
      'payment_gateways' => [
        'title' => t('Payment Gateways'), 
        'access' => FALSE,
        'route' => 'entity.commerce_payment_gateway.collection',
      ],
      'tax' => [
        'title' => t('Tax Settings'),
        'access' => FALSE,
        'route' => 'entity.commerce_tax_type.collection',
      ],
      'shipping' => [
        'title' => t('Shipping Methods'),
        'access' => FALSE,
        'route' => 'entity.commerce_shipping_method.collection',
      ],
      'inventory' => [
        'title' => t('Inventory Settings'),
        'access' => FALSE,
        'route' => 'commerce_inventory.settings',
      ],
    ];
    
    // Check access for each setting
    foreach ($settings as $type => &$setting) {
      $setting['access'] = $this->hasStoreSettingAccess($store, $type)->isAllowed();
    }
    
    return $settings;
  }
}
