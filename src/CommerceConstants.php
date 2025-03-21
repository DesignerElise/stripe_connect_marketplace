<?php

namespace Drupal\stripe_connect_marketplace;

/**
 * Defines constants for the Stripe Connect Marketplace module.
 */
class CommerceConstants {
  /**
   * Route names used in the module.
   */
  const ROUTE_VENDOR_DASHBOARD = 'stripe_connect_marketplace.vendor_dashboard';
  const ROUTE_VENDOR_STORE_SETTINGS = 'stripe_connect_marketplace.vendor_store_settings';
  const ROUTE_VENDOR_STORE_PAYMENT = 'stripe_connect_marketplace.vendor_store_payment_gateways';
  const ROUTE_VENDOR_STORE_TAX_SETTINGS = 'stripe_connect_marketplace.vendor_store_tax_settings';
  const ROUTE_VENDOR_STORE_SHIPPING_METHODS = 'stripe_connect_marketplace.vendor_store_shipping_methods';
  const ROUTE_VENDOR_STORE_INVENTORY = 'stripe_connect_marketplace.vendor_store_inventory';
  const ROUTE_VENDOR_STORE_PROMOTIONS = 'stripe_connect_marketplace.vendor_store_promotions''
  
  // Add other route constants
  
  /**
   * Permission names.
   */
  const PERM_ADMINISTER_STORE = 'administer commerce_store';
  const PERM_VIEW_OWN_STORE = 'view own commerce_store';
  // Add other permission constants below
}
