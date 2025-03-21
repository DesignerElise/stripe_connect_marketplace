<?php

namespace Drupal\stripe_connect_marketplace\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controls access for vendors to commerce product entities.
 */
class VendorProductAccessControlHandler extends EntityAccessControlHandler {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, $entity_type_id) {
    $instance = parent::createInstance($container, $entity_type_id);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\commerce_product\Entity\ProductInterface $entity */
    $account = $this->prepareUser($account);
    
    // First check if user has admin permission
    if ($account->hasPermission('administer commerce_product')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // If the user has the "view any" permission, allow access.
    if ($operation === 'view' && $account->hasPermission('view commerce_product')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // If the user has the update/delete any permission, allow access.
    if ($operation === 'update' && $account->hasPermission('update commerce_product')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    if ($operation === 'delete' && $account->hasPermission('delete commerce_product')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // For vendors, check if product belongs to one of their stores
    if ($account->hasRole('vendor')) {
      // Handle "view own" permission
      if ($operation === 'view' && $account->hasPermission('view own commerce_product')) {
        return $this->checkProductStoreOwnership($entity, $account)
          ->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($entity);
      }
      
      // Handle "update own" permission
      if ($operation === 'update' && $account->hasPermission('update own commerce_product')) {
        return $this->checkProductStoreOwnership($entity, $account)
          ->cachePerPermissions()
          ->cachePerUser()
          ->addCacheableDependency($entity);
      }
      
      // Handle "delete own" permission
      if ($operation === 'delete' && $account->hasPermission('delete own commerce_product')) {
        return $this->checkProductStoreOwnership($entity, $account)
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
    // Allow if user can administer products
    if ($account->hasPermission('administer commerce_product')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // Allow if user has the create products permission
    if ($account->hasPermission('create commerce_product')) {
      return AccessResult::allowed()->cachePerPermissions();
    }
    
    // No opinion, let other access control handlers decide.
    return AccessResult::neutral();
  }

  /**
   * Checks if the user owns a store that this product belongs to.
   *
   * @param \Drupal\Core\Entity\EntityInterface $product
   *   The product entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  protected function checkProductStoreOwnership(EntityInterface $product, AccountInterface $account) {
    // Get user's stores
    $user_store_query = $this->entityTypeManager->getStorage('commerce_store')->getQuery()
      ->condition('uid', $account->id())
      ->accessCheck(FALSE);
    $user_store_ids = $user_store_query->execute();
    
    if (empty($user_store_ids)) {
      return AccessResult::forbidden();
    }
    
    // Get product's stores
    $product_store_ids = [];
    foreach ($product->getStores() as $store) {
      $product_store_ids[] = $store->id();
    }
    
    // If there's an intersection, the user owns at least one of the stores
    if (!empty(array_intersect($product_store_ids, $user_store_ids))) {
      return AccessResult::allowed();
    }
    
    return AccessResult::forbidden();
  }
}
