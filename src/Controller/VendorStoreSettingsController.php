<?php

namespace Drupal\stripe_connect_marketplace\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\stripe_connect_marketplace\Service\VendorStoreSettingsAccessService;
use Drupal\stripe_connect_marketplace\Utility\SafeLogging;

/**
 * Controller for handling vendor store settings.
 */
class VendorStoreSettingsController extends ControllerBase {

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
   * The vendor store settings access service.
   *
   * @var \Drupal\stripe_connect_marketplace\Service\VendorStoreSettingsAccessService
   */
  protected $vendorStoreSettingsAccess;

  /**
   * Constructs a new VendorStoreSettingsController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\stripe_connect_marketplace\Service\VendorStoreSettingsAccessService $vendor_store_settings_access
   *   The vendor store settings access service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    VendorStoreSettingsAccessService $vendor_store_settings_access,
    StoreConfigurationService $store_configuration
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->vendorStoreSettingsAccess = $vendor_store_settings_access;
    $this->storeConfiguration = $store_configuration;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('stripe_connect_marketplace.vendor_store_settings_access'),
      $container->get('stripe_connect_marketplace.store_configuration')
    );
  }

  /**
   * Displays the store settings dashboard for vendors.
   *
   * @param int $store_id
   *   The store ID.
   *
   * @return array
   *   A render array representing the store settings dashboard.
   */
  public function settingsDashboard($store_id) {
    // Load the store
    $store = $this->entityTypeManager->getStorage('commerce_store')->load($store_id);
    
    // Check access
    if (!$store || $store->getOwnerId() != $this->currentUser->id()) {
      return $this->redirect('entity.commerce_store.collection');
    }
    
    // Get accessible settings
    $settings = $this->vendorStoreSettingsAccess->getAccessibleSettings($store);
    
    // Build cards for each setting
    $settings_cards = [];
    
    foreach ($settings as $type => $setting) {
      if ($setting['access']) {
        $settings_cards[] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['settings-card', $type . '-card']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'h3',
            '#value' => $setting['title'],
          ],
          'description' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#value' => $this->getSettingDescription($type),
          ],
          'link' => [
            '#type' => 'link',
            '#title' => $this->t('Manage @setting', ['@setting' => $setting['title']]),
            '#url' => $this->getSettingUrl($setting['route'], $store_id, $type),
            '#attributes' => ['class' => ['button', 'button--primary']],
          ],
        ];
      }
    }
    
    // Build the dashboard
    $build = [
      '#theme' => 'vendor_store_settings_dashboard',
      '#store' => $store,
      '#settings' => $settings_cards,
      '#attached' => [
        'library' => ['stripe_connect_marketplace/vendor_store_settings'],
      ],
    ];
    
    return $build;
  }

  /**
   * Gets the description for a setting type.
   *
   * @param string $type
   *   The setting type.
   *
   * @return string
   *   The description.
   */
  protected function getSettingDescription($type) {
    switch ($type) {
      case 'payment_gateways':
        return $this->t('Configure which payment methods your customers can use to pay for orders.');
        
      case 'tax':
        return $this->t('Set up tax rates and configure tax settings for your store.');
        
      case 'shipping':
        return $this->t('Manage shipping methods and rates for your products.');
        
      case 'inventory':
        return $this->t('Configure inventory settings and stock management for your products.');
        
      default:
        return $this->t('Configure store settings.');
    }
  }

  /**
   * Gets the URL for a specific setting.
   *
   * @param string $route
   *   The route name.
   * @param int $store_id
   *   The store ID.
   * @param string $type
   *   The setting type.
   *
   * @return \Drupal\Core\Url
   *   The URL.
   */
  protected function getSettingUrl($route, $store_id, $type) {
    // For vendor-specific settings pages that implement the store context
    if (method_exists($this, 'get' . ucfirst($type) . 'Url')) {
      $method = 'get' . ucfirst($type) . 'Url';
      return $this->$method($store_id);
    }
    
    // Add vendor context to standard commerce routes
    return Url::fromRoute($route, [
      'store_id' => $store_id,
      'vendor_context' => 'true',
    ]);
  }

  /**
   * Gets the payment gateways URL for a store.
   *
   * @param int $store_id
   *   The store ID.
   *
   * @return \Drupal\Core\Url
   *   The URL.
   */
  protected function getPaymentGatewaysUrl($store_id) {
    return Url::fromRoute('stripe_connect_marketplace.vendor_store_payment_gateways', [
      'store_id' => $store_id,
    ]);
  }

  /**
   * Payment gateways page for a specific vendor store.
   *
   * @param int $store_id
   *   The store ID.
   *
   * @return array
   *   A render array for the payment gateways page.
   */
  public function paymentGateways($store_id) {
    // Load the store
    $store = $this->entityTypeManager->getStorage('commerce_store')->load($store_id);
    
    // Check access
    if (!$store || $store->getOwnerId() != $this->currentUser->id() ||
        !$this->vendorStoreSettingsAccess->hasStoreSettingAccess($store, 'payment_gateways')->isAllowed()) {
      return $this->redirect('entity.commerce_store.collection');
    }
    
    // Load payment gateways available to this store
    $gateway_query = $this->entityTypeManager->getStorage('commerce_payment_gateway')->getQuery()
      ->condition('status', TRUE)
      ->accessCheck(TRUE);
    $gateway_ids = $gateway_query->execute();
    $gateways = $this->entityTypeManager->getStorage('commerce_payment_gateway')->loadMultiple($gateway_ids);
    
    // Filter gateways based on store settings (if any)
    if ($store->hasField('field_payment_gateways')) {
      $store_gateway_ids = $store->get('field_payment_gateways')->getValue();
      if (!empty($store_gateway_ids)) {
        $store_gateway_ids = array_column($store_gateway_ids, 'target_id');
        $gateways = array_filter($gateways, function ($gateway) use ($store_gateway_ids) {
          return in_array($gateway->id(), $store_gateway_ids);
        });
      }
    }
    
    // Build the table
    $rows = [];
    foreach ($gateways as $gateway) {
      $rows[] = [
        'name' => $gateway->label(),
        'plugin' => $gateway->getPluginLabel(),
        'status' => $gateway->status() ? $this->t('Enabled') : $this->t('Disabled'),
        'mode' => $gateway->getPlugin()->getMode(),
      ];
    }
    
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vendor-payment-gateways']],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h1',
        '#value' => $this->t('Payment Gateways for @store', ['@store' => $store->label()]),
      ],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The following payment gateways are available for your store. Contact the marketplace administrator to enable additional gateways.'),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('Type'),
          $this->t('Status'),
          $this->t('Mode'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No payment gateways available.'),
      ],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to Store Settings'),
        '#url' => Url::fromRoute('stripe_connect_marketplace.vendor_store_settings', ['store_id' => $store_id]),
        '#attributes' => ['class' => ['button']],
      ],
    ];
    
    return $build;
  }
}

<?php

/**
 * Handles tax settings page for a specific vendor store.
 *
 * @param int $store_id
 *   The store ID.
 *
 * @return array
 *   A render array for the tax settings page.
 */
public function taxSettings($store_id) {
  // Load the store
  $store = $this->entityTypeManager->getStorage('commerce_store')->load($store_id);
  
  // Check access
  if (!$store || $store->getOwnerId() != $this->currentUser->id() ||
      !$this->vendorStoreSettingsAccess->hasStoreSettingAccess($store, 'tax')->isAllowed()) {
    return $this->redirect('entity.commerce_store.collection');
  }
  
  // Get tax types available for this store
  $tax_types = $this->storeConfiguration->getStoreTaxTypes($store);
  
  // Build the table
  $rows = [];
  foreach ($tax_types as $tax_type) {
    $rows[] = [
      'name' => $tax_type->label(),
      'plugin' => $tax_type->getPluginLabel(),
      'status' => $tax_type->status() ? $this->t('Enabled') : $this->t('Disabled'),
      'territories' => $this->formatTerritories($tax_type),
    ];
  }
  
  $build = [
    '#type' => 'container',
    '#attributes' => ['class' => ['vendor-tax-settings']],
    'title' => [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Tax Settings for @store', ['@store' => $store->label()]),
    ],
    'description' => [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('The following tax configurations apply to your store. Contact the marketplace administrator to configure additional tax types.'),
    ],
    'table' => [
      '#type' => 'table',
      '#header' => [
        $this->t('Name'),
        $this->t('Type'),
        $this->t('Status'),
        $this->t('Territories'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No tax configurations available.'),
    ],
    'back' => [
      '#type' => 'link',
      '#title' => $this->t('Back to Store Settings'),
      '#url' => Url::fromRoute('stripe_connect_marketplace.vendor_store_settings', ['store_id' => $store_id]),
      '#attributes' => ['class' => ['button']],
    ],
  ];
  
  return $build;
}

/**
 * Formats territories for display.
 *
 * @param \Drupal\commerce_tax\Entity\TaxType $tax_type
 *   The tax type.
 *
 * @return string
 *   A formatted string of territories.
 */
protected function formatTerritories($tax_type) {
  $plugin = $tax_type->getPlugin();
  $territories = [];
  
  // Check if the plugin has a method to get territories
  if (method_exists($plugin, 'getTerritories')) {
    $territories = $plugin->getTerritories();
  }
  
  if (empty($territories)) {
    return $this->t('All territories');
  }
  
  return implode(', ', $territories);
}

/**
 * Handles shipping methods page for a specific vendor store.
 *
 * @param int $store_id
 *   The store ID.
 *
 * @return array
 *   A render array for the shipping methods page.
 */
public function shippingMethods($store_id) {
  // Load the store
  $store = $this->entityTypeManager->getStorage('commerce_store')->load($store_id);
  
  // Check access
  if (!$store || $store->getOwnerId() != $this->currentUser->id() ||
      !$this->vendorStoreSettingsAccess->hasStoreSettingAccess($store, 'shipping')->isAllowed()) {
    return $this->redirect('entity.commerce_store.collection');
  }
  
  // Load shipping methods for this store
  $shipping_methods = $this->storeConfiguration->getStoreShippingMethods($store);
  
  // Build the table
  $rows = [];
  foreach ($shipping_methods as $method) {
    $rows[] = [
      'name' => $method->label(),
      'plugin' => $method->getPlugin()->getLabel(),
      'status' => $method->isEnabled() ? $this->t('Enabled') : $this->t('Disabled'),
      'stores' => count($method->getStores()),
    ];
  }
  
  $build = [
    '#type' => 'container',
    '#attributes' => ['class' => ['vendor-shipping-methods']],
    'title' => [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Shipping Methods for @store', ['@store' => $store->label()]),
    ],
    'description' => [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('The following shipping methods are available for your store. Contact the marketplace administrator to configure additional shipping methods.'),
    ],
    'table' => [
      '#type' => 'table',
      '#header' => [
        $this->t('Name'),
        $this->t('Type'),
        $this->t('Status'),
        $this->t('Store Count'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No shipping methods available.'),
    ],
    'back' => [
      '#type' => 'link',
      '#title' => $this->t('Back to Store Settings'),
      '#url' => Url::fromRoute('stripe_connect_marketplace.vendor_store_settings', ['store_id' => $store_id]),
      '#attributes' => ['class' => ['button']],
    ],
  ];
  
  return $build;
}

/**
 * Handles inventory settings page for a specific vendor store.
 *
 * @param int $store_id
 *   The store ID.
 *
 * @return array
 *   A render array for the inventory settings page.
 */
public function inventorySettings($store_id) {
  // Load the store
  $store = $this->entityTypeManager->getStorage('commerce_store')->load($store_id);
  
  // Check access
  if (!$store || $store->getOwnerId() != $this->currentUser->id() ||
      !$this->vendorStoreSettingsAccess->hasStoreSettingAccess($store, 'inventory')->isAllowed()) {
    return $this->redirect('entity.commerce_store.collection');
  }
  
  // Load inventory settings
  $inventory_settings = $this->storeConfiguration->getStoreInventorySettings($store);
  
  // Get available inventory field types
  $availability_strategies = [];
  if (\Drupal::moduleHandler()->moduleExists('commerce_inventory')) {
    // If commerce_inventory module exists, get strategies from config
    $config = \Drupal::config('commerce_inventory.settings');
    $availability_strategies = $config->get('availability_strategies') ?: [
      'always' => $this->t('Always available'),
      'simple_stock' => $this->t('Simple stock tracking'),
    ];
  } else {
    // Default fallback options
    $availability_strategies = [
      'always' => $this->t('Always available'),
      'simple_stock' => $this->t('Simple stock tracking'),
    ];
  }
  
  // Build the form (read-only for vendors)
  $build = [
    '#type' => 'container',
    '#attributes' => ['class' => ['vendor-inventory-settings']],
    'title' => [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('Inventory Settings for @store', ['@store' => $store->label()]),
    ],
    'description' => [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('These are the inventory settings for your store. Contact the marketplace administrator to modify these settings.'),
    ],
    'settings' => [
      '#type' => 'container',
      'enabled' => [
        '#type' => 'item',
        '#title' => $this->t('Inventory Tracking'),
        '#markup' => $inventory_settings['enabled'] ? 
          $this->t('Enabled') : 
          $this->t('Disabled'),
      ],
      'default_strategy' => [
        '#type' => 'item',
        '#title' => $this->t('Default Availability Strategy'),
        '#markup' => isset($availability_strategies[$inventory_settings['default_availability_strategy']]) ?
          $availability_strategies[$inventory_settings['default_availability_strategy']] :
          $inventory_settings['default_availability_strategy'],
      ],
    ],
    'back' => [
      '#type' => 'link',
      '#title' => $this->t('Back to Store Settings'),
      '#url' => Url::fromRoute('stripe_connect_marketplace.vendor_store_settings', ['store_id' => $store_id]),
      '#attributes' => ['class' => ['button']],
    ],
  ];
  
  return $build;
}
