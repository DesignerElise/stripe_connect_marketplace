<?php

namespace Drupal\stripe_connect_marketplace\Form;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Class StripePaymentMethodAddForm.
 *
 * Provides the Stripe Connect payment method add form with support for direct charges.
 */
class StripePaymentMethodAddForm extends PaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  public function buildCreditCardForm(array $element, FormStateInterface $form_state) {
    $element = parent::buildCreditCardForm($element, $form_state);

    // Get the payment gateway configuration.
    $payment_method = $this->entity;
    $payment_gateway_plugin = $payment_method->getPaymentGateway()->getPlugin();
    $config = $payment_gateway_plugin->getConfiguration();

    // Get Stripe publishable key from settings.
    $settings = \Drupal::config('stripe_connect_marketplace.settings');
    $environment = $settings->get('stripe_connect.environment');
    $publishable_key = $environment === 'live'
      ? $settings->get('stripe_connect.live_publishable_key')
      : $settings->get('stripe_connect.test_publishable_key');

    if (empty($publishable_key)) {
      \Drupal::messenger()->addError(t('Stripe publishable key is not configured.'));
      return $element;
    }

    // Determine if this is for a specific order.
    $order = $this->getOrder($form_state);
    $connected_account_id = null;

    // For direct charges, we may need the connected account ID
    if ($order) {
      $connected_account_id = $this->getVendorStripeAccountId($order);
    }

    // Add extra element to store Stripe payment method ID.
    $element['stripe_payment_method_id'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];

    // Add hidden element to store the connected account ID when applicable.
    if ($connected_account_id) {
      $element['stripe_connect_account_id'] = [
        '#type' => 'hidden',
        '#default_value' => $connected_account_id,
      ];
    }

    // Add a container for card errors.
    $element['card_errors'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => 'stripe-card-errors',
        'class' => ['stripe-card-errors'],
        'role' => 'alert',
      ],
    ];

    // Add the Stripe Elements container.
    $element['stripe_elements'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'stripe-card-element',
        'class' => ['form-control'],
      ],
    ];

    // Add direct charges explanation if we're using a connected account.
    if ($connected_account_id) {
      $element['direct_charges_info'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['direct-charges-info']],
        '#value' => t('This payment method will be processed directly by the vendor through Stripe.'),
      ];
    }

    // Attach the Stripe JS libraries and settings.
    $element['#attached']['library'][] = 'stripe_connect_marketplace/stripe_js';
    $element['#attached']['drupalSettings']['stripeConnect'] = [
      'publishableKey' => $publishable_key,
      'clientSecret' => '', // This would be set for payment intents
      'connectedAccountId' => $connected_account_id, // Pass to JS for direct charges
    ];

    // Remove credit card fields that are replaced by Stripe Elements.
    unset($element['number']);
    unset($element['expiration']);
    unset($element['security_code']);

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function validateCreditCardForm(array &$element, FormStateInterface $form_state) {
    // The validation is handled by Stripe.js.
    // We just need to make sure a payment method ID is present.
    if (empty($form_state->getValue(['payment_information', 'stripe_payment_method_id']))) {
      $form_state->setError($element, $this->t('No payment information provided.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitCreditCardForm(array $element, FormStateInterface $form_state) {
    // Get the payment method ID provided by Stripe.js.
    $stripe_payment_method_id = $form_state->getValue(['payment_information', 'stripe_payment_method_id']);
    $connected_account_id = $form_state->getValue(['payment_information', 'stripe_connect_account_id']);

    // Set it as the remote ID of the payment method.
    $payment_method = $this->entity;
    $payment_method->setRemoteId($stripe_payment_method_id);

    // Store the connected account ID in metadata if present
    // This is crucial for direct charges to work correctly
    $payment_method_data = [
      'remote_id' => $stripe_payment_method_id,
    ];

    if ($connected_account_id) {
      // Add metadata to track this payment method's connected account
      $payment_method_data['metadata'] = [
        'stripe_connect_account_id' => $connected_account_id,
        'payment_model' => 'direct_charge',
      ];
      
      // Store in field_data for retrieval during payment processing
      if ($payment_method->hasField('field_stripe_connect_data')) {
        $payment_method->set('field_stripe_connect_data', json_encode([
          'connected_account_id' => $connected_account_id,
        ]));
      }
    }

    // Extract billing information if available.
    if (!empty($form_state->getValue(['payment_information', 'billing_information']))) {
      $billing_info = $form_state->getValue(['payment_information', 'billing_information']);
      
      // In direct charges, we need to ensure billing information is attached
      if ($connected_account_id && !empty($billing_info['address'])) {
        // Format billing address for Stripe
        $address = reset($billing_info['address']);
        if (!empty($address['address'])) {
          $payment_method_data['billing_details'] = [
            'address' => [
              'line1' => $address['address']['address_line1'] ?? '',
              'line2' => $address['address']['address_line2'] ?? '',
              'city' => $address['address']['locality'] ?? '',
              'state' => $address['address']['administrative_area'] ?? '',
              'postal_code' => $address['address']['postal_code'] ?? '',
              'country' => $address['address']['country_code'] ?? '',
            ],
          ];
          
          // Add name if available
          if (!empty($address['address']['given_name']) || !empty($address['address']['family_name'])) {
            $name = trim(($address['address']['given_name'] ?? '') . ' ' . ($address['address']['family_name'] ?? ''));
            $payment_method_data['billing_details']['name'] = $name;
          }
          
          // Add email if available
          if (!empty($billing_info['email'])) {
            $payment_method_data['billing_details']['email'] = $billing_info['email'];
          }
        }
      }
    }

    // Set the payment method type.
    $payment_method->card_type = '';
    $payment_method->card_number = '';
    $payment_method->card_exp_month = '';
    $payment_method->card_exp_year = '';

    // Store additional data in the payment method's data storage
    if (!empty($payment_method_data) && method_exists($payment_method, 'setData')) {
      $payment_method->setData($payment_method_data);
    }

    // We don't need to call parent::submitCreditCardForm() since we're not using
    // the default fields.
  }

  /**
   * Gets the order from the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order, or NULL if not found.
   */
  protected function getOrder(FormStateInterface $form_state) {
    // Try to get the order from the form state or payment method
    $order = $form_state->get('order');
    if (!$order && $this->entity && method_exists($this->entity, 'getOrder')) {
      $order = $this->entity->getOrder();
    }
    
    // Last resort - check for order ID in storage
    if (!$order && $form_state->has('order_id')) {
      $order_id = $form_state->get('order_id');
      $order = \Drupal::entityTypeManager()->getStorage('commerce_order')->load($order_id);
    }
    
    return $order instanceof OrderInterface ? $order : null;
  }

  /**
   * Gets the Stripe account ID for the vendor/store associated with the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string|null
   *   The Stripe account ID or NULL if not found.
   */
  protected function getVendorStripeAccountId(OrderInterface $order) {
    // Get the store associated with the order.
    $store = $order->getStore();
    if (!$store) {
      return NULL;
    }
    
    // Get the store owner.
    $owner = $store->getOwner();
    if (!$owner) {
      return NULL;
    }
    
    // Check if the owner has a Stripe account ID field.
    if ($owner->hasField('field_stripe_account_id') && !$owner->get('field_stripe_account_id')->isEmpty()) {
      return $owner->get('field_stripe_account_id')->value;
    }
    
    return NULL;
  }
}
