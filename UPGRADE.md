# Upgrading to eWay for Craft Commerce 5.0 (Commerce 6)

eWay for Craft Commerce 5.0 supports Craft Commerce 6. It talks to the [eWAY Rapid API](https://eway.io/api-v3/) directly, rather than through Omnipay.

## Requirements

- Craft CMS 6.0 and Craft Commerce 6.0 or later
- PHP 8.5 or later
- `craftcms/yii2-adapter` is not needed.

## Upgrade steps

1. Update to eWay for Craft Commerce 4.1.1 and Craft Commerce 5 first.
2. Upgrade your project to Craft 6 and Craft Commerce 6.
3. Update the plugin: `composer require craftcms/commerce-eway:^5.0`.
4. Review the breaking changes below.

Existing eWay gateways keep working: gateways saved with the old `craft\commerce\eway\gateways\Gateway` type load as `CraftCms\CommerceEway\Gateway`, and save with the new class name the next time they’re saved. Their settings (`apiKey`, `password`, `CSEKey`, `testMode` and `sendCartInfo`) keep the same names, and saved payment sources (eWAY token customers) keep working.

## Breaking changes

### Omnipay

The plugin no longer depends on `craftcms/commerce-omnipay`, `omnipay/eway` or `omnipay/common`. If your project uses Omnipay classes directly, require those packages yourself.

It sends the same eWAY Rapid API requests the Omnipay driver sent, to the same endpoints (`DirectPayment.json`, `CapturePayment` and `DirectRefund.json`), with HTTP basic authentication.

### Events

The Omnipay gateway events are gone. To change a request before it’s sent to eWAY, listen for `CraftCms\CommerceEway\Events\PaymentRequestBuilding` and modify its `$request` array, which is the JSON body of the eWAY request. `$type` is the Commerce transaction type (`purchase`, `authorize`, `capture` or `refund`), or `paymentSource` when a token customer is created for a payment source.

| Before | After |
| --- | --- |
| `craft\commerce\omnipay\base\Gateway::EVENT_BUILD_GATEWAY_REQUEST` | `CraftCms\CommerceEway\Events\PaymentRequestBuilding` |
| `craft\commerce\omnipay\base\Gateway::EVENT_BEFORE_SEND_PAYMENT_REQUEST` | `CraftCms\CommerceEway\Events\PaymentRequestBuilding` |
| `craft\commerce\omnipay\base\Gateway::EVENT_AFTER_CREATE_ITEM_BAG` | `CraftCms\CommerceEway\Events\PaymentRequestBuilding` (modify `$event->request['Items']`) |
| `craft\commerce\omnipay\base\Gateway::EVENT_BEFORE_GATEWAY_REQUEST_SEND` | No replacement |

Before:

```php
use craft\commerce\eway\gateways\Gateway;
use craft\commerce\omnipay\events\SendPaymentRequestEvent;
use yii\base\Event;

Event::on(Gateway::class, Gateway::EVENT_BEFORE_SEND_PAYMENT_REQUEST, function(SendPaymentRequestEvent $event) {
    $data = $event->requestData;
    $data['PartnerID'] = 'my-partner-id';
    $event->modifiedRequestData = $data;
});
```

After:

```php
use CraftCms\CommerceEway\Events\PaymentRequestBuilding;
use Illuminate\Support\Facades\Event;

Event::listen(function(PaymentRequestBuilding $event): void {
    $event->request['PartnerID'] = 'my-partner-id';
});
```

`EVENT_BUILD_GATEWAY_REQUEST` listeners received Omnipay’s parameters (such as `amount`, `card` or `transactionId`). The keys of `$event->request` are eWAY’s own field names (such as `Payment`, `Customer` or `Method`), so those listeners need to use eWAY’s names. Values set to `null` or an empty string are removed before the request is sent.

### Classes and namespaces

| Before | After |
| --- | --- |
| `craft\commerce\eway\Plugin` | `CraftCms\CommerceEway\Plugin` |
| `craft\commerce\eway\gateways\Gateway` | `CraftCms\CommerceEway\Gateway` (the old name is kept as an alias) |
| `craft\commerce\eway\models\EwayPaymentForm` | `CraftCms\CommerceEway\Forms\EwayPaymentForm` |
| `craft\commerce\eway\EwayPaymentBundle` | Removed. The payment form registers its scripts itself. |
| `craft\commerce\eway\migrations\Install` | Removed. It converted Commerce 1 `craft\commerce\gateways\Eway_RapidDirect` gateways. |

`Gateway` no longer extends `craft\commerce\omnipay\base\CreditCardGateway`, so the Omnipay methods (`populateRequest()`, `createCard()`, `createGateway()`, `getItemListForOrder()` and the like) are gone. The responses are `CraftCms\CommerceEway\Responses\RapidResponse` objects, and `RapidResponse::MESSAGES` holds the eWAY response code descriptions.

`EwayPaymentForm` now extends `CraftCms\Commerce\Payment\Forms\CreditCardPaymentForm` and validates with `getRules()` (Laravel rules) instead of `rules()`.

### Templates and Twig

`gateway.getPaymentFormHtml()` and `gateway.getPaymentConfirmationFormHtml()` still work, and the forms post the same fields as before: `firstName`, `lastName`, `expiry`, and the `encryptedCardNumber` and `encryptedCardCvv` fields the encryption script adds. The plain `number` and `cvv` inputs are still disabled before the form is submitted.

The `commerce-eway/paymentForm` and `commerce-eway/confirmationForm` templates are gone. The forms are built by `Gateway::paymentFormUi()` and `Gateway::paymentConfirmationFormUi()`, using Craft’s form markup, so to change them, override those methods in a subclass rather than overriding the templates.

The `.eway-form` element with the `data-key` and `data-payment-form-namespace` attributes is now an empty element before the card fields, rather than a wrapper around them. If you build your own payment form, it still needs that element inside the `<form>`. The encryption script now only matches `<input>` elements named `…[number]` and `…[cvv]`, because Craft’s form markup also puts the name on the `<craft-input>` wrapper.

### Other changes

- Errors for the encrypted card number and CVV, and for the expiry month and year, are shown on the card number, CVV and expiry inputs.
- The card holder name (`Customer.CardDetails.Name`) is now the name entered on the payment form. Omnipay sent the billing address name when the order had a billing address.
- `DeviceID` is now `craftcms/commerce-eway` rather than Omnipay’s `https://github.com/adrianmacneil/omnipay`.
- When a payment source is saved, the customer details come from the customer’s primary billing address, or the current cart’s billing address, and the email from the customer’s account. The plugin no longer changes the cart’s billing address, and no longer requires a primary billing address when the cart has one. eWAY still needs a country (validation error `V6044`).
- Validation errors eWAY added after the Omnipay driver was last updated (`V6136` and `V6143` to `V6149`, and `V6154`) now have descriptions.
- If eWAY doesn’t return JSON (for example a `401` for a wrong API key or password), the transaction fails with “eWAY returned an invalid response (HTTP 401).”
