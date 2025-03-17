<?php

namespace Drupal\stripe_connect_marketplace\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\stripe_connect_marketplace\Utility\SafeLogging;

/**
 * Event subscriber for filtering commerce settings for vendors.
 */
class CommerceSettingsSubscriber implements EventSubscriberInterface {

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
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;
  
  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;
  
  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Commerce routes that should be filtered for vendors.
   *
   * @var array
   */
  protected $vendorRestrictedRoutes = [
    'entity.commerce_payment_gateway.collection',
    'entity.commerce_payment_gateway.add_form',
    'entity.commerce_payment_gateway.edit_form',
    'entity.commerce_tax_type.collection',
    'entity.commerce_tax_type.add_form',
    'entity.commerce_tax_type.edit_form',
    'entity.commerce_shipping_method.collection',
    'entity.commerce_shipping_method.add_form',
    'entity.commerce_shipping_method.edit_form',
    'commerce_inventory.settings',
    'entity.commerce_product_variation_type.edit_form',
    'entity.commerce_promotion.collection',
    'entity.commerce_promotion.add_form',
    'entity.commerce_promotion.edit_form',
    // Add other Commerce admin routes that vendors shouldn't access directly
    'commerce.configuration',
    'commerce.store_settings',
    'entity.commerce_store_type.collection',
  ];

  /**
   * Constructs a new CommerceSettingsEventSubscriber.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    RouteMatchInterface $route_match,
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->routeMatch = $route_match;
    $this->messenger = $messenger;
    $this->logger = $logger_factory->get('stripe_connect_marketplace');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = ['onRequest', 50];
    return $events;
  }

  /**
   * Event handler for the kernel request event.
   *
   * Redirects vendors to their store-specific settings pages when they attempt
   * to access global Commerce settings.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event) {
    // Skip if not the master request
    if (!$event->isMainRequest()) {
      return;
    }
    
    // Only process for vendors
    if (!$this->currentUser->hasRole('vendor') || $this->currentUser->hasPermission('administer commerce_store')) {
      return;
    }
    
    // Check if we're on a restricted route
    $route_name = $this->routeMatch->getRouteName();
    if (!in_array($route_name, $this->vendorRestrictedRoutes)) {
      return;
    }
    
    // Check for vendor context parameter
    if ($event->getRequest()->query->has('vendor_context') && 
        $event->getRequest()->query->get('vendor_context') === 'true') {
      // Log the access with vendor context
      SafeLogging::log($this->logger, 'Vendor @uid accessed @route with vendor context', [
        '@uid' => $this->currentUser->id(),
        '@route' => $route_name,
      ], 'info');
      
      // Let the page load, but we'll alter it later in hook_preprocess
      return;
    }
    
    // If we're here, we need to redirect to the appropriate vendor store settings page
    $store_id = $this->getVendorStoreId();
    
    // Determine which settings page to redirect to based on current route
    $redirect_route = 'stripe_connect_marketplace.vendor_dashboard'; // Default fallback
    
    if ($store_id) {
      // Determine specific redirect based on the current route
      $specific_route = $this->getVendorRedirectRoute($route_name);
      if ($specific_route) {
        $redirect_route = $specific_route;
        
        // Log the redirect with specific destination
        SafeLogging::log($this->logger, 'Redirecting vendor @uid from @from_route to @to_route for store @store_id', [
          '@uid' => $this->currentUser->id(),
          '@from_route' => $route_name,
          '@to_route' => $redirect_route,
          '@store_id' => $store_id,
        ], 'notice');
        
        // Display a message to the vendor about the redirect
        $this->messenger->addStatus(t('You have been redirected to your store-specific settings.'));
        
        // Redirect to the store-specific page
        $url = Url::fromRoute($redirect_route, ['store_id' => $store_id])->toString();
        $event->setResponse(new RedirectResponse($url));
        return;
      }
    }
    
    // No store or no specific redirect - fallback to vendor dashboard
    SafeLogging::log($this->logger, 'Redirecting vendor @uid from @route to vendor dashboard (no store found)', [
      '@uid' => $this->currentUser->id(),
      '@route' => $route_name,
    ], 'notice');
    
    // Display a message explaining the redirect
    $this->messenger->addWarning(t('You do not have access to that Commerce administration page. You have been redirected to your vendor dashboard.'));
    
    // Redirect to the vendor dashboard
    $url = Url::fromRoute($redirect_route)->toString();
    $event->setResponse(new RedirectResponse($url));
  }

  /**
   * Gets the vendor's store ID.
   *
   * @return int|null
   *   The store ID, or NULL if none found.
   */
  protected function getVendorStoreId() {
    try {
      // Query for stores owned by the current user
      $query = $this->entityTypeManager->getStorage('commerce_store')->getQuery()
        ->condition('uid', $this->currentUser->id())
        ->accessCheck(TRUE)
        ->sort('created', 'DESC')
        ->range(0, 1);
      
      $result = $query->execute();
      
      if (!empty($result)) {
        return reset($result);
      }
    }
    catch (\Exception $e) {
      SafeLogging::log($this->logger, 'Error getting vendor store ID: @message', [
        '@message' => $e->getMessage(),
      ], 'error');
    }
    
    return NULL;
  }

  /**
   * Maps a Commerce route to the appropriate vendor settings route.
   *
   * @param string $route_name
   *   The Commerce route name.
   *
   * @return string|null
   *   The vendor settings route name, or NULL if no specific mapping exists.
   */
  protected function getVendorRedirectRoute($route_name) {
    $route_map = [
      'entity.commerce_payment_gateway.collection' => 'stripe_connect_marketplace.vendor_store_payment_gateways',
      'entity.commerce_payment_gateway.add_form' => 'stripe_connect_marketplace.vendor_store_payment_gateways',
      'entity.commerce_payment_gateway.edit_form' => 'stripe_connect_marketplace.vendor_store_payment_gateways',
      
      'entity.commerce_tax_type.collection' => 'stripe_connect_marketplace.vendor_store_tax_settings',
      'entity.commerce_tax_type.add_form' => 'stripe_connect_marketplace.vendor_store_tax_settings',
      'entity.commerce_tax_type.edit_form' => 'stripe_connect_marketplace.vendor_store_tax_settings',
      
      'entity.commerce_shipping_method.collection' => 'stripe_connect_marketplace.vendor_store_shipping_methods',
      'entity.commerce_shipping_method.add_form' => 'stripe_connect_marketplace.vendor_store_shipping_methods',
      'entity.commerce_shipping_method.edit_form' => 'stripe_connect_marketplace.vendor_store_shipping_methods',
      
      'commerce_inventory.settings' => 'stripe_connect_marketplace.vendor_store_inventory',
      'entity.commerce_product_variation_type.edit_form' => 'stripe_connect_marketplace.vendor_store_inventory',
      
      'entity.commerce_promotion.collection' => 'stripe_connect_marketplace.vendor_store_settings',
      'entity.commerce_promotion.add_form' => 'stripe_connect_marketplace.vendor_store_settings',
      'entity.commerce_promotion.edit_form' => 'stripe_connect_marketplace.vendor_store_settings',
      
      // Generic Commerce routes redirect to the store settings dashboard
      'commerce.configuration' => 'stripe_connect_marketplace.vendor_store_settings',
      'commerce.store_settings' => 'stripe_connect_marketplace.vendor_store_settings',
      'entity.commerce_store_type.collection' => 'stripe_connect_marketplace.vendor_store_settings',
    ];
    
    // Return the mapped route if available, otherwise vendor store settings
    if (isset($route_map[$route_name])) {
      return $route_map[$route_name];
    }
    
    // For other Commerce admin routes, default to store settings
    if (strpos($route_name, 'commerce.') === 0 || 
        strpos($route_name, 'entity.commerce_') === 0) {
      return 'stripe_connect_marketplace.vendor_store_settings';
    }
    
    // No specific mapping found
    return NULL;
  }
}
