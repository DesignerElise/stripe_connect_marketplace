(function ($, Drupal, drupalSettings) {
    Drupal.behaviors.vendorCommerceDashboard = {
      attach: function (context, settings) {
        const dashboardElement = document.getElementById('vendor-commerce-dashboard-app');
        if (dashboardElement && drupalSettings.stripeConnectMarketplace) {
          ReactDOM.render(
            React.createElement(VendorCommerceDashboard, {
              storeId: drupalSettings.stripeConnectMarketplace.storeId,
              storeName: drupalSettings.stripeConnectMarketplace.storeName,
            }),
            dashboardElement
          );
        }
      }
    };
  })(jQuery, Drupal, drupalSettings);