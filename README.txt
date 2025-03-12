# Stripe Connect Marketplace for Drupal Commerce

This module extends the Commerce Stripe module to add Stripe Connect functionality for creating a multi-vendor marketplace in Drupal Commerce. It allows vendors to onboard with Stripe Connect Express accounts, process payments through your platform, and receive automatic splits of payments.

## Requirements

* Drupal 10 or 11
* PHP 8.1+
* Drupal Commerce 2.x or 3.x
* Commerce Stripe module
* Stripe account with Connect enabled
* Composer for dependencies

## Installation

### Step 1: Install the required modules

```bash
composer require drupal/commerce_stripe designerelise/stripe_connect_marketplace
drush en commerce_stripe stripe_connect_marketplace
```

### Step 2: Configure Stripe API Keys

1. Configure the standard Stripe settings at `/admin/commerce/config/stripe`
2. Configure your Stripe Connect specific settings at `/admin/commerce/config/stripe-connect`

### Step 3: Set up webhooks

1. In your Stripe Dashboard, go to Developers > Webhooks.
2. Add a new endpoint with the URL provided in the module settings.
3. Select the following events to monitor:
   - `account.updated`
   - `charge.refunded`
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `payout.created`
   - `payout.paid`
   - `payout.failed`
4. Copy the webhook signing secret to the module settings.

## Features

* **Vendor Onboarding**: Automated onboarding to Stripe Connect Express accounts.
* **Split Payments**: Automatically split payments between the platform and vendors.
* **Customizable Fees**: Set platform fees as a percentage of each transaction.
* **Vendor Dashboard**: Vendors can track their earnings and view payout history.
* **Admin Dashboard**: Comprehensive dashboard for platform owners to manage vendors and track revenue.
* **Webhook Integration**: Secure webhook handling for real-time Stripe event processing.
* **Comprehensive Reporting**: Track transaction history, payouts, and fees.

## Usage

### For Vendors

1. Go to `/vendor/register` to sign up as a vendor.
2. Complete the Stripe Connect onboarding process.
3. Access your vendor dashboard at `/vendor/dashboard`.

### For Administrators

1. Access the admin dashboard at `/admin/commerce/stripe-connect/dashboard`.
2. View and manage all connected vendors.
3. Configure platform settings and fees.
4. View platform revenue and vendor payouts.

## Security

This module follows Drupal's secure coding practices and Stripe's security recommendations:

* API keys are stored securely in Drupal's configuration system.
* Secret keys are masked when displayed in the admin interface.
* Webhook payloads are verified using Stripe's signature verification.
* All communication with Stripe API is done over HTTPS.

## Troubleshooting

### Webhook Issues

If webhooks are not being processed:

1. Check that your webhook URL is correctly set in your Stripe Dashboard.
2. Verify that the webhook signing secret is correctly entered in the module settings.
3. Check the Drupal logs for detailed error messages.

### Payment Issues

If payments are failing:

1. Check that your vendors have completed the Stripe Connect onboarding process.
2. Verify that your Stripe account is properly configured for Stripe Connect.
3. Check the Drupal logs for detailed error messages.

## Development

### Local Testing with Stripe CLI

You can use the [Stripe CLI](https://stripe.com/docs/stripe-cli) for local webhook testing:

```bash
stripe listen --forward-to https://yourdrupalsite.local/stripe/webhook
```

### Customizing the Module

The module is designed to be extensible. You can customize it by:

* Extending the provided services with your own implementations.
* Altering the provided forms using Drupal's Form API alter hooks.
* Overriding the template files for customized display.

## License

This module is licensed under GPL-2.0+.

## Credits

Developed by DesignerElise.