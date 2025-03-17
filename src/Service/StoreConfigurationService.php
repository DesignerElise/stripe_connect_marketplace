<?php

namespace Drupal\stripe_connect_marketplace\Service;

use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for managing store-specific configurations.
 */
class StoreConfigurationService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new StoreConfigurationService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
  }

  /**
   * Gets payment gateways available for a store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   *
   * @return array
   *   Array of payment gateway entities.
   */
  public function getStorePaymentGateways(StoreInterface $store) {
    // Get all enabled payment gateways
    $gateway_query = $this->entityTypeManager->getStorage('commerce_payment_gateway')->getQuery()
      ->condition('status', TRUE)
      ->accessCheck(TRUE);
    $gateway_ids = $gateway_query->execute();
    $gateways = $this->entityTypeManager->getStorage('commerce_payment_gateway')->loadMultiple($gateway_ids);
    
    // Filter gateways based on store settings if applicable
    if ($store->hasField('field_payment_gateways') && !$store->get('field_payment_gateways')->isEmpty()) {
      $store_gateway_ids = array_column($store->get('field_payment_gateways')->getValue(), 'target_id');
      $filtered_gateways = [];
      
      foreach ($gateways as $gateway) {
        if (in_array($gateway->id(), $store_gateway_ids)) {
          $filtered_gateways[$gateway->id()] = $gateway;
        }
      }
      
      return $filtered_gateways;
    }
    
    return $gateways;
  }

  /**
   * Gets tax types available for a store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   *
   * @return array
   *   Array of tax type entities.
   */
  public function getStoreTaxTypes(StoreInterface $store) {
    // Get all tax types
    $tax_type_query = $this->entityTypeManager->getStorage('commerce_tax_type')->getQuery()
      ->accessCheck(TRUE);
    $tax_type_ids = $tax_type_query->execute();
    $tax_types = $this->entityTypeManager->getStorage('commerce_tax_type')->loadMultiple($tax_type_ids);
    
    // Filter tax types based on store settings if applicable
    if ($store->hasField('field_tax_types') && !$store->get('field_tax_types')->isEmpty()) {
      $store_tax_type_ids = array_column($store->get('field_tax_types')->getValue(), 'target_id');
      $filtered_tax_types = [];
      
      foreach ($tax_types as $tax_type) {
        if (in_array($tax_type->id(), $store_tax_type_ids)) {
          $filtered_tax_types[$tax_type->id()] = $tax_type;
        }
      }
      
      return $filtered_tax_types;
    }
    
    return $tax_types;
  }

  /**
   * Gets shipping methods available for a store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   *
   * @return array
   *   Array of shipping method entities.
   */
  public function getStoreShippingMethods(StoreInterface $store) {
    // Get all shipping methods
    $shipping_method_query = $this->entityTypeManager->getStorage('commerce_shipping_method')->getQuery()
      ->condition('stores', $store->id())
      ->accessCheck(TRUE);
    $shipping_method_ids = $shipping_method_query->execute();
    
    if (empty($shipping_method_ids)) {
      return [];
    }
    
    return $this->entityTypeManager->getStorage('commerce_shipping_method')->loadMultiple($shipping_method_ids);
  }

  /**
   * Gets inventory settings for a store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   *
   * @return array
   *   Array of inventory settings.
   */
  public function getStoreInventorySettings(StoreInterface $store) {
    // Get global inventory settings
    $inventory_config = $this->configFactory->get('commerce_inventory.settings');
    
    // Override with store-specific settings if available
    $store_settings = [];
    if ($store->hasField('field_inventory_settings') && !$store->get('field_inventory_settings')->isEmpty()) {
      $store_settings = unserialize($store->get('field_inventory_settings')->value);
    }
    
    // Merge global and store-specific settings
    return $store_settings + [
      'enabled' => $inventory_config->get('enabled') ?: FALSE,
      'default_availability_strategy' => $inventory_config->get('default_availability_strategy') ?: 'always',
    ];
  }

  /**
   * Gets promotions for a store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   *
   * @return array
   *   Array of promotion entities.
   */
  public function getStorePromotions(StoreInterface $store) {
    // Get promotions that apply to this store
    $promotion_query = $this->entityTypeManager->getStorage('commerce_promotion')->getQuery()
      ->condition('stores', $store->id())
      ->accessCheck(TRUE);
    $promotion_ids = $promotion_query->execute();
    
    if (empty($promotion_ids)) {
      return [];
    }
    
    return $this->entityTypeManager->getStorage('commerce_promotion')->loadMultiple($promotion_ids);
  }

  /**
   * Updates store configuration for a specific setting type.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   * @param string $setting_type
   *   The setting type (e.g., 'payment_gateways', 'tax').
   * @param array $values
   *   The setting values to save.
   *
   * @return bool
   *   TRUE if update successful, FALSE otherwise.
   */
  public function updateStoreConfiguration(StoreInterface $store, $setting_type, array $values) {
    try {
      switch ($setting_type) {
        case 'payment_gateways':
          if ($store->hasField('field_payment_gateways')) {
            $store->set('field_payment_gateways', $values);
            $store->save();
            return TRUE;
          }
          break;
          
        case 'tax':
          if ($store->hasField('field_tax_types')) {
            $store->set('field_tax_types', $values);
            $store->save();
            return TRUE;
          }
          break;
          
        case 'inventory':
          if ($store->hasField('field_inventory_settings')) {
            $store->set('field_inventory_settings', serialize($values));
            $store->save();
            return TRUE;
          }
          break;
      }
      
      return FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating store configuration: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }
}
