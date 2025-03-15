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

      // Initialize the application fee display
      const $feePercentField = $('#edit-application-fee-percent', context);
      const $feeDisplay = $('.fee-example', context);
      
      // Update example fee calculation when fee percent changes
      if ($feePercentField.length && $feeDisplay.length) {
        const updateFeeExample = function() {
          const percent = parseFloat($feePercentField.val()) || 0;
          const exampleAmount = 100;
          const fee = (exampleAmount * percent / 100).toFixed(2);
          
          $feeDisplay.html(Drupal.t('For example, on a $@amount transaction, the platform fee would be $@fee.', {
            '@amount': exampleAmount.toFixed(2),
            '@fee': fee
          }));
        };
        
        // Update on load and when changed
        updateFeeExample();
        $feePercentField.on('input change', updateFeeExample);
      }

      // Add tooltips for direct charges model
      $('.direct-charges-info', context).once('stripe-connect-info').each(function() {
        $(this).on('click', function(e) {
          e.preventDefault();
          
          const infoText = Drupal.t('In the direct charges model, vendors collect payments directly through their Stripe accounts, and your platform automatically receives the configured application fee for each transaction.');
          
          Drupal.dialog($('<div>' + infoText + '</div>'), {
            title: Drupal.t('Direct Charges Model'),
            buttons: [{
              text: Drupal.t('Close'),
              click: function() {
                $(this).dialog('close');
              }
            }]
          }).showModal();
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);