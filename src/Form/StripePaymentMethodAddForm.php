<?php

namespace Drupal\stripe_connect_marketplace\Form;

use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

/**
 * Class StripePaymentMethodAddForm.
 *
 * Provides the Stripe payment method add form.
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

    // Add extra element to store Stripe payment method ID.
    $element['stripe_payment_method_id'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];

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

    // Attach the Stripe JS libraries and settings.
    $element['#attached']['library'][] = 'stripe_connect_marketplace/stripe_js';
    $element['#attached']['drupalSettings']['stripeConnect'] = [
      'publishableKey' => $publishable_key,
      'clientSecret' => '', // This would be set for payment intents
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

    // Set it as the remote ID of the payment method.
    $payment_method = $this->entity;
    $payment_method->setRemoteId($stripe_payment_method_id);

    // Extract billing information if available.
    if (!empty($form_state->getValue(['payment_information', 'billing_information']))) {
      $billing_info = $form_state->getValue(['payment_information', 'billing_information']);
      // This would get mapped to the payment method entity.
    }

    // Set the payment method type.
    $payment_method->card_type = '';
    $payment_method->card_number = '';
    $payment_method->card_exp_month = '';
    $payment_method->card_exp_year = '';

    // We don't need to call parent::submitCreditCardForm() since we're not using
    // the default fields.
  }
}
