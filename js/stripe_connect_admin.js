/**
 * @file
 * JavaScript for the Stripe Connect admin settings form.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * Attach behavior for detecting API key changes.
   */
  Drupal.behaviors.stripeConnectAdminSettings = {
    attach: function (context, settings) {
      // Mark when a key field is modified.
      const keyFields = [
        'edit-stripe-connect-keys-test-secret-key',
        'edit-stripe-connect-keys-test-publishable-key',
        'edit-stripe-connect-keys-live-secret-key',
        'edit-stripe-connect-keys-live-publishable-key',
        'edit-stripe-connect-webhook-webhook-secret'
      ];

      $.each(keyFields, function (index, fieldId) {
        $('#' + fieldId, context).once('stripe-connect-key-change').on('input', function () {
          // If the field has been modified and is not just the masked version,
          // mark it as changed.
          const value = $(this).val();
          const maskedValue = value.match(/^\*+/);
          
          if (value && !maskedValue) {
            $('input[name="stripe_connect[keys][keys_changed]"]').val('yes');
          }
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);