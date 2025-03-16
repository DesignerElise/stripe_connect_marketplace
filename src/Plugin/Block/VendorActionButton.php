<?php

namespace Drupal\stripe_connect_marketplace\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Provides a block with vendor action buttons.
 *
 * @Block(
 *   id = "vendor_action_buttons",
 *   admin_label = @Translation("Vendor Action Buttons"),
 *   category = @Translation("Stripe Connect Marketplace")
 * )
 */
class VendorActionButtons extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

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
   * Constructs a new VendorActionButtons.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [
      '#theme' => 'vendor_action_buttons',
      '#buttons' => [],
      '#attached' => [
        'library' => ['stripe_connect_marketplace/vendor_action_buttons'],
      ],
    ];

    // Check if we're on the vendor dashboard
    $route_name = $this->routeMatch->getRouteName();
    $is_vendor_dashboard = ($route_name === 'stripe_connect_marketplace.vendor_dashboard');

    // Add Store Button
    if ($this->currentUser->hasPermission('create commerce_store')) {
      $build['#buttons'][] = [
        'title' => $this->t('Add Store'),
        'url' => Url::fromRoute('entity.commerce_store.add_page'),
        'icon' => 'store',
        'button_class' => 'add-store-button',
        'weight' => 10,
      ];
    }

    // Get user's stores to check if they have any
    $has_stores = FALSE;
    $user_store_ids = $this->getUserStoreIds();
    
    if (!empty($user_store_ids)) {
      $has_stores = TRUE;
      
      // Add Product Button if user has at least one store
      if ($this->currentUser->hasPermission('create commerce_product')) {
        $build['#buttons'][] = [
          'title' => $this->t('Add Product'),
          'url' => Url::fromRoute('entity.commerce_product.add_page'),
          'icon' => 'product',
          'button_class' => 'add-product-button',
          'weight' => 20,
        ];
      }
      
      // Manage Store Button (only show if they have exactly one store)
      if (count($user_store_ids) === 1 && $this->currentUser->hasPermission('update own commerce_store')) {
        $store_id = reset($user_store_ids);
        $build['#buttons'][] = [
          'title' => $this->t('Manage Store'),
          'url' => Url::fromRoute('entity.commerce_store.edit_form', ['commerce_store' => $store_id]),
          'icon' => 'settings',
          'button_class' => 'manage-store-button',
          'weight' => 30,
        ];
      }
      
      // View Products Button
      if ($this->currentUser->hasPermission('view own commerce_product')) {
        $build['#buttons'][] = [
          'title' => $this->t('View Products'),
          'url' => Url::fromRoute('entity.commerce_product.collection'),
          'icon' => 'inventory',
          'button_class' => 'view-products-button',
          'weight' => 40,
        ];
      }
      
      // View Orders Button
      if ($this->currentUser->hasPermission('view own commerce_order')) {
        $build['#buttons'][] = [
          'title' => $this->t('View Orders'),
          'url' => Url::fromRoute('entity.commerce_order.collection'),
          'icon' => 'orders',
          'button_class' => 'view-orders-button',
          'weight' => 50,
        ];
      }
    }
    
    // Only show on vendor dashboard or if there's at least one button
    if (!$is_vendor_dashboard && empty($build['#buttons'])) {
      return [];
    }
    
    // Sort buttons by weight
    usort($build['#buttons'], function($a, $b) {
      return $a['weight'] <=> $b['weight'];
    });
    
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    // Only show to vendors
    if ($account->hasRole('vendor')) {
      return AccessResult::allowed();
    }

    return AccessResult::forbidden();
  }

  /**
   * Gets store IDs for current user.
   *
   * @return array
   *   Array of store IDs owned by the current user.
   */
  protected function getUserStoreIds() {
    $query = $this->entityTypeManager->getStorage('commerce_store')->getQuery()
      ->condition('uid', $this->currentUser->id())
      ->accessCheck(FALSE);
    
    return $query->execute();
  }
}
