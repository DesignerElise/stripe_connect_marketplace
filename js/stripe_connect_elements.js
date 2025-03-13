/**
 * @file
 * JavaScript for the Stripe Elements integration.
 */

(function ($, Drupal, drupalSettings) {
    'use strict';
  
    /**
     * Attach behavior for initializing Stripe Elements.
     */
    Drupal.behaviors.stripeConnectElements = {
      attach: function (context, settings) {
        if (!drupalSettings.stripeConnect || !drupalSettings.stripeConnect.publishableKey) {
          console.error('Stripe Connect: No publishable key provided.');
          return;
        }
  
        // Initialize Stripe with the publishable key.
        const stripe = Stripe(drupalSettings.stripeConnect.publishableKey);
        const elements = stripe.elements();
  
        // Create and mount the card element.
        const cardElement = elements.create('card', {
          style: {
            base: {
              color: '#32325d',
              fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
              fontSmoothing: 'antialiased',
              fontSize: '16px',
              '::placeholder': {
                color: '#aab7c4'
              }
            },
            invalid: {
              color: '#fa755a',
              iconColor: '#fa755a'
            }
          }
        });
  
        const cardElementContainer = document.getElementById('stripe-card-element');
        
        if (cardElementContainer) {
          cardElement.mount('#stripe-card-element');
  
          // Handle form submission to get the payment method ID.
          const form = $(cardElementContainer).closest('form')[0];
          
          if (form) {
            $(form).once('stripe-connect-form').on('submit', function (event) {
              // Prevent form submission until we get a payment method ID.
              event.preventDefault();
              
              const submitButton = $(this).find('input[type="submit"]');
              submitButton.prop('disabled', true);
              
              stripe.createPaymentMethod({
                type: 'card',
                card: cardElement,
                billing_details: getBillingDetails()
              })
              .then(function (result) {
                if (result.error) {
                  // Show error to the user.
                  const errorElement = document.getElementById('stripe-card-errors');
                  errorElement.textContent = result.error.message;
                  submitButton.prop('disabled', false);
                } else {
                  // Set the payment method ID in the form and submit.
                  const hiddenInput = document.getElementById('edit-payment-information-stripe-payment-method-id');
                  hiddenInput.value = result.paymentMethod.id;
                  form.submit();
                }
              });
            });
          }
        }
  
        /**
         * Extract billing details from the form if available.
         *
         * @return {Object}
         *   Billing details object for Stripe.
         */
        function getBillingDetails() {
          const billing = {};
          
          // Extract fields from billing information if present.
          const billingInfo = $('#edit-payment-information-billing-information');
          
          if (billingInfo.length) {
            const name = $('.form-item-payment-information-billing-information-address-0-address-given-name input', billingInfo).val() +
                       ' ' + $('.form-item-payment-information-billing-information-address-0-address-family-name input', billingInfo).val();
            
            billing.name = name.trim();
            billing.email = $('.form-item-payment-information-billing-information-email input', billingInfo).val();
            
            billing.address = {
              line1: $('.form-item-payment-information-billing-information-address-0-address-address-line1 input', billingInfo).val(),
              line2: $('.form-item-payment-information-billing-information-address-0-address-address-line2 input', billingInfo).val(),
              city: $('.form-item-payment-information-billing-information-address-0-address-locality input', billingInfo).val(),
              state: $('.form-item-payment-information-billing-information-address-0-address-administrative-area select', billingInfo).val(),
              postal_code: $('.form-item-payment-information-billing-information-address-0-address-postal-code input', billingInfo).val(),
              country: $('.form-item-payment-information-billing-information-address-0-address-country-code select', billingInfo).val()
            };
          }
          
          return billing;
        }
      }
    };
  
  })(jQuery, Drupal, drupalSettings);