<p align="center"><img src="./resources/icon.svg" width="100" height="100" alt="eWay for Craft Commerce icon"></p>

<h1 align="center">eWay for Craft Commerce</h1>

This plugin provides an [eWay](https://www.eway.com.au/) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

eWay for Craft Commerce 5.x requires Craft CMS 6.0, Craft Commerce 6.0 and PHP 8.5 or later.

eWay for Craft Commerce 4.x supports Craft 4 and Craft Commerce 4, or Craft 5 and Craft Commerce 5. If you’re upgrading from 4.x, see [UPGRADE.md](UPGRADE.md).

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the **Plugin Store** in your project’s control panel and search for “eWay for Craft Commerce”. Then click on the **Install** button in the sidebar.

#### With Composer

Open your terminal and run the following commands:

```bash
# Navigate to your project directory:
cd /path/to/my-project.test

# Require the plugin package with Composer:
composer require craftcms/commerce-eway

# Run the installer:
php artisan craft:plugin:install commerce-eway
```

## Setup

To add an eWay payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to “eWay Rapid”.

> [!NOTE]
> The **API Key**, **Password**, **Client side encryption key** and **Test mode?** settings can be set to environment variables. See [Environmental Configuration](https://craftcms.com/docs/6.x/configure.html#control-panel-settings) in the Craft docs to learn more about using secrets in configuration.

The gateway uses eWAY’s [Direct Connection](https://eway.io/api-v3/#direct-connection) with [Client Side Encryption](https://eway.io/api-v3/#client-side-encryption): the card number and CVV are encrypted in the customer’s browser, so they never reach your server in plain text. It supports purchases, authorizations (pre-auths) and captures, full and partial refunds, and saved payment sources (eWAY token customers). **Test mode?** sends requests to the eWAY sandbox.

## Payment forms

Render the payment form inside the gateway’s payment form namespace, as with any Commerce gateway:

```twig
{% namespace cart.gateway.handle|commercePaymentFormNamespace %}
  {{ cart.gateway.getPaymentFormHtml({})|raw }}
{% endnamespace %}
```

The form registers eWAY’s eCrypt library and a script that encrypts the `number` and `cvv` inputs when the form is submitted, posting them as `encryptedCardNumber` and `encryptedCardCvv`. If you build your own form, keep an element with the `eway-form` class and the `data-key` (the client side encryption key) and `data-payment-form-namespace` attributes inside the `<form>`, and keep the `number` and `cvv` input names.

## Customizing requests

To change a request before it’s sent to eWAY, listen for the `PaymentRequestBuilding` event and modify its `request` array. Its keys are eWAY Rapid API fields.

```php
use CraftCms\CommerceEway\Events\PaymentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentRequestBuilding $event): void {
    if ($event->type === 'purchase') {
        $event->request['PartnerID'] = 'my-partner-id';
    }
});
```
