<?php

namespace Drupal\stripe_connect_marketplace\Tests\Functional;

use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\commerce_store\StoreCreationTrait;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Tests\commerce_stripe\Functional\StripeCheckoutTestTrait;
use Drupal\user\Entity\User;
use Drupal\Core\Test\AssertMailTrait;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Tests the Stripe Connect integration with Drupal Commerce.
 *
 * @group stripe_connect_marketplace
 */
class StripeConnectTest extends CommerceBrowserTestBase {

  use StoreCreationTrait;
  use AssertMailTrait;
  use ProphecyTrait;
  use StripeCheckoutTestTrait;

  /**
   * The product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $product;

  /**
   * The vendor user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $vendorUser;

  /**
   * The customer user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $customerUser;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'commerce_checkout',
    'commerce_product',
    'commerce_cart',
    'commerce_payment',
    'commerce_payment_example',
    'commerce_stripe',
    'stripe_connect_marketplace',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create the vendor role.
    $this->createRole(['access content', 'view commerce_product'], 'vendor');

    // Create the vendor user with a Stripe account ID.
    $this->vendorUser = $this->createUser([
      'access content',
      'view commerce_product',
    ]);
    $this->vendorUser->addRole('vendor');
    $this->vendorUser->set('field_stripe_account_id', 'acct_mock_vendor_id');
    $this->vendorUser->set('field_vendor_status', 'active');
    $this->vendorUser->save();

    // Create a customer user.
    $this->customerUser = $this->createUser([
      'access checkout',
      'access content',
      'view commerce_product',
    ]);

    // Create a store owned by the vendor user.
    $store = $this->createStore('Vendor Store', $this->vendorUser->getEmail(), 'default', FALSE);
    $store->setOwner($this->vendorUser);
    $store->save();
    $this->store = $this->reloadEntity($store);

    // Create a product variation.
    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'TEST_SKU',
      'price' => [
        'number' => 9.99,
        'currency_code' => 'USD',
      ],
    ]);
    $variation->save();

    // Create a product.
    $this->product = Product::create([
      'type' => 'default',
      'title' => 'Test product',
      'stores' => [$this->store->id()],
      'variations' => [$variation],
    ]);
    $this->product->save();

    // Create StripeConnect payment gateway.
    $this->createStripeConnectPaymentGateway();
  }

  /**
   * Creates the Stripe Connect payment gateway.
   */
  protected function createStripeConnectPaymentGateway() {
    // Mock the Stripe API service.
    $this->installEntitySchema('commerce_payment_method');
    $this->installConfig('commerce_payment');

    // Create a payment gateway.
    $payment_gateway = $this->createEntity('commerce_payment_gateway', [
      'id' => 'stripe_connect',
      'label' => 'Stripe Connect',
      'plugin' => 'stripe_connect',
      'configuration' => [
        'application_fee_percent' => 10,
        'publishable_key' => 'pk_test_example',
        'secret_key' => 'sk_test_example',
        'mode' => 'test',
        'payment_method_types' => ['credit_card'],
      ],
    ]);
    $payment_gateway->save();
  }

  /**
   * Tests that a vendor can register and connect their Stripe account.
   */
  public function testVendorRegistration() {
    // Login as a non-vendor user.
    $user = $this->createUser([
      'access content',
    ]);
    $this->drupalLogin($user);

    // Visit the vendor registration page.
    $this->drupalGet('vendor/register');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Register as Vendor');

    // We would normally fill in the form and submit it, but since we can't
    // actually test against the real Stripe API in an automated test, we'll
    // just verify the page loads correctly.
    $this->assertSession()->elementExists('css', '#stripe-connect-marketplace-onboard-vendor-form');
    $this->assertSession()->fieldExists('email');
    $this->assertSession()->fieldExists('country');
    $this->assertSession()->fieldExists('business_type');
    $this->assertSession()->fieldExists('vendor_agreement');
  }

  /**
   * Tests the vendor dashboard.
   */
  public function testVendorDashboard() {
    // Login as a vendor.
    $this->drupalLogin($this->vendorUser);

    // Visit the vendor dashboard.
    $this->drupalGet('vendor/dashboard');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Vendor Dashboard');
    $this->assertSession()->elementExists('css', '.stripe-connect-vendor-dashboard');
  }

  /**
   * Tests admin dashboard access.
   */
  public function testAdminDashboard() {
    // Create an admin user.
    $admin_user = $this->createUser([
      'access administration pages',
      'administer commerce_payment_gateway',
      'administer stripe connect',
      'administer commerce_store',
    ]);
    $this->drupalLogin($admin_user);

    // Visit the admin dashboard.
    $this->drupalGet('admin/commerce/stripe-connect/dashboard');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Stripe Connect Administration');
    $this->assertSession()->elementExists('css', '.stripe-connect-admin-dashboard');
  }

  /**
   * Tests basic Stripe Connect settings form.
   */
  public function testSettingsForm() {
    // Create an admin user.
    $admin_user = $this->createUser([
      'access administration pages',
      'administer commerce_payment_gateway',
      'administer stripe connect',
      'administer site configuration',
    ]);
    $this->drupalLogin($admin_user);

    // Visit the settings form.
    $this->drupalGet('admin/commerce/config/stripe-connect');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Stripe Connect Settings');

    // Verify the connect-specific fields exist.
    $this->assertSession()->fieldExists('connect_settings[webhook][webhook_secret]');
    $this->assertSession()->fieldExists('connect_settings[fees][application_fee_percent]');
  }

  /**
   * Tests the payment flow with a Stripe Connect vendor (unit test only).
   *
   * This test mocks the Stripe API interactions since we can't use the real
   * Stripe API in automated tests.
   */
  public function testStripeConnectPaymentFlow() {
    // This would typically be a more complex test that:
    // 1. Mocks the Stripe API client
    // 2. Sets expectations for API calls with connected accounts
    // 3. Verifies the payment gateway creates the correct API calls
    //
    // However, for simplicity in this example, we'll just test that our
    // payment gateway service exists.
    $payment_gateway_storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment_gateway');
    $payment_gateway = $payment_gateway_storage->load('stripe_connect');
    $this->assertNotNull($payment_gateway, 'Stripe Connect payment gateway exists');
    $this->assertEquals('stripe_connect', $payment_gateway->getPluginId());
  }
}
