<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Data\PaymentSource;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Exceptions\PaymentException;
use CraftCms\Commerce\Payment\Exceptions\PaymentSourceException;
use CraftCms\Commerce\Payment\Gateway\Gateways;
use CraftCms\Commerce\Payment\Gateway\GatewayTypes;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\Commerce\Payment\Payments;
use CraftCms\Commerce\Payment\Transactions;
use CraftCms\Commerce\Store\Data\Store;
use CraftCms\CommerceEway\Events\PaymentRequestBuilding;
use CraftCms\CommerceEway\Forms\EwayPaymentForm;
use CraftCms\CommerceEway\Gateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const EWAY_LIVE = 'https://api.ewaypayments.com';
const EWAY_SANDBOX = 'https://api.sandbox.ewaypayments.com';

function ewayGateway(array $settings = [], string $paymentType = 'purchase'): Gateway
{
    /** @var Gateway $gateway */
    $gateway = app(Gateways::class)->createGateway([
        'type' => Gateway::class,
        'name' => 'eWAY',
        'handle' => 'eway',
        'paymentType' => $paymentType,
        'settings' => array_merge(['apiKey' => 'api-key', 'password' => 'api-password', 'CSEKey' => 'cse-key'], $settings),
    ]);

    return $gateway;
}

/**
 * Saves a gateway and an order with a billing address, and returns a transaction for it.
 */
function ewayTransaction(float $amount = 12.3, array $settings = [], string $type = TransactionModel::TYPE_PURCHASE): Transaction
{
    $gateway = ewayGateway($settings);
    app(Gateways::class)->saveGateway($gateway);

    $order = new Order();
    $order->email = 'customer@example.com';
    $order->gatewayId = $gateway->id;
    $order->setBillingAddress([
        'firstName' => 'Jenny',
        'lastName' => 'Andrews',
        'organization' => 'Andrews Pty Ltd',
        'addressLine1' => '369 Queen Street',
        'addressLine2' => 'Level 5',
        'locality' => 'Sydney',
        'administrativeArea' => 'NSW',
        'postalCode' => '2000',
        'countryCode' => 'AU',
    ]);
    Elements::saveElement($order);

    $transaction = app(Transactions::class)->createTransaction($order, null, $type);
    $transaction->paymentAmount = $amount;
    $transaction->amount = $amount;

    return $transaction;
}

function ewayCardForm(array $attributes = []): EwayPaymentForm
{
    $form = new EwayPaymentForm();
    $form->setAttributes(array_merge([
        'firstName' => 'Card',
        'lastName' => 'Holder',
        'expiry' => '5/' . ((int)date('Y') + 2),
        'encryptedCardNumber' => 'eCrypted:number',
        'encryptedCardCvv' => 'eCrypted:cvv',
    ], $attributes));

    return $form;
}

/**
 * An approved Direct Connection response, in the shape of eWAY’s documented examples.
 */
function ewayApproved(array $attributes = []): array
{
    return array_merge([
        'AuthorisationCode' => '522587',
        'ResponseCode' => '00',
        'ResponseMessage' => 'A2000',
        'TransactionID' => 11259580,
        'TransactionStatus' => true,
        'TransactionType' => 'Purchase',
        'Errors' => null,
    ], $attributes);
}

it('registers the gateway type', function() {
    expect(app(GatewayTypes::class)->types()->all())->toContain(Gateway::class);
});

it('loads gateways saved with the Craft Commerce 5 class name', function() {
    $settings = [
        'apiKey' => '$EWAY_API_KEY',
        'password' => '$EWAY_PASSWORD',
        'CSEKey' => '$EWAY_CSE_KEY',
        'testMode' => '$EWAY_TEST_MODE',
        'sendCartInfo' => true,
    ];

    $gateway = app(Gateways::class)->createGateway([
        'type' => 'craft\\commerce\\eway\\gateways\\Gateway',
        'settings' => $settings,
    ]);

    expect($gateway)->toBeInstanceOf(Gateway::class)
        ->and($gateway->getSettings())->toBe($settings)
        ->and($gateway->getConfig()['type'])->toBe(Gateway::class);
});

it('requires the API key, password and client side encryption key', function() {
    $gateway = ewayGateway(['apiKey' => '', 'password' => null, 'CSEKey' => '']);

    expect($gateway->validate())->toBeFalse()
        ->and($gateway->errors()->keys())->toContain('apiKey', 'password', 'CSEKey');

    expect(ewayGateway()->validate())->toBeTrue();
});

it('renders the settings', function() {
    $html = ewayGateway(['apiKey' => '$EWAY_API_KEY', 'CSEKey' => '$EWAY_CSE_KEY'])->getSettingsHtml();

    expect($html)->toContain('apiKey')
        ->toContain('$EWAY_API_KEY')
        ->toContain('password')
        ->toContain('$EWAY_CSE_KEY')
        ->toContain('testMode')
        ->toContain('sendCartInfo');
});

it('parses environment variables in the settings', function() {
    putenv('EWAY_TEST_KEY=key-from-env');
    putenv('EWAY_TEST_SANDBOX=true');

    $gateway = ewayGateway(['apiKey' => '$EWAY_TEST_KEY', 'testMode' => '$EWAY_TEST_SANDBOX']);

    expect($gateway->getApiKey())->toBe('key-from-env')
        ->and($gateway->getApiKey(false))->toBe('$EWAY_TEST_KEY')
        ->and($gateway->getTestMode())->toBeTrue()
        ->and($gateway->getTestMode(false))->toBe('$EWAY_TEST_SANDBOX')
        ->and($gateway->getSendCartInfo())->toBeFalse();

    putenv('EWAY_TEST_KEY');
    putenv('EWAY_TEST_SANDBOX');
});

it('sends a purchase with the encrypted card details', function() {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response(ewayApproved())]);
    $transaction = ewayTransaction(12.3);
    $expiryYear = substr((string)((int)date('Y') + 2), -2);

    $response = $transaction->getGateway()->purchase($transaction, ewayCardForm());

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->getTransactionReference())->toBe('11259580')
        ->and($response->getCode())->toBe('A2000')
        ->and($response->getMessage())->toBe('Transaction Approved');

    Http::assertSent(function(Request $request) use ($transaction, $expiryYear) {
        return $request->method() === 'POST'
            && $request->url() === EWAY_LIVE . '/DirectPayment.json'
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode('api-key:api-password'))
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === [
                'DeviceID' => 'craftcms/commerce-eway',
                'CustomerIP' => '127.0.0.1',
                'Customer' => [
                    'FirstName' => 'Jenny',
                    'LastName' => 'Andrews',
                    'CompanyName' => 'Andrews Pty Ltd',
                    'Street1' => '369 Queen Street',
                    'Street2' => 'Level 5',
                    'City' => 'Sydney',
                    'State' => 'NSW',
                    'PostalCode' => '2000',
                    'Country' => 'au',
                    'Email' => 'customer@example.com',
                    'CardDetails' => [
                        'Name' => 'Card Holder',
                        'ExpiryMonth' => '05',
                        'ExpiryYear' => $expiryYear,
                        'Number' => 'eCrypted:number',
                        'CVN' => 'eCrypted:cvv',
                    ],
                ],
                'ShippingAddress' => ['FirstName' => 'Card', 'LastName' => 'Holder'],
                'TransactionType' => 'Purchase',
                'Payment' => [
                    'TotalAmount' => 1230,
                    'InvoiceNumber' => $transaction->hash,
                    'InvoiceDescription' => 'Order #' . $transaction->orderId,
                    'CurrencyCode' => 'USD',
                ],
                'Method' => 'ProcessPayment',
            ];
    });
});

it('sends the shipping address', function() {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response(ewayApproved())]);
    $transaction = ewayTransaction();
    $transaction->getOrder()->setShippingAddress([
        'firstName' => 'Sam',
        'lastName' => 'Smith',
        'addressLine1' => '1 Scrubby Creek Road',
        'locality' => 'Scrubby Creek',
        'administrativeArea' => 'QLD',
        'postalCode' => '4999',
        'countryCode' => 'AU',
    ]);

    $transaction->getGateway()->purchase($transaction, ewayCardForm());

    Http::assertSent(fn(Request $request) => $request['ShippingAddress'] === [
        'FirstName' => 'Sam',
        'LastName' => 'Smith',
        'Street1' => '1 Scrubby Creek Road',
        'City' => 'Scrubby Creek',
        'State' => 'QLD',
        'Country' => 'au',
        'PostalCode' => '4999',
    ]);
});

it('uses the sandbox in test mode', function() {
    Http::fake([EWAY_SANDBOX . '/DirectPayment.json' => Http::response(ewayApproved())]);
    $transaction = ewayTransaction(settings: ['testMode' => '1']);

    expect($transaction->getGateway()->purchase($transaction, ewayCardForm())->isSuccessful())->toBeTrue();

    Http::assertSent(fn(Request $request) => str_starts_with($request->url(), EWAY_SANDBOX . '/'));
    Http::assertSentCount(1);
});

it('sends amounts in the currency’s minor unit', function(float $amount, string $currency, int $expected) {
    Http::fake([EWAY_LIVE . '/*' => Http::response(ewayApproved())]);
    $transaction = ewayTransaction($amount);
    $transaction->paymentCurrency = $currency;

    $transaction->getGateway()->purchase($transaction, ewayCardForm());

    Http::assertSent(fn(Request $request) => $request['Payment']['TotalAmount'] === $expected
        && $request['Payment']['CurrencyCode'] === $currency);
})->with([
    'cents' => [19.99, 'AUD', 1999],
    'float that truncates when multiplied' => [4.35, 'USD', 435],
    'zero-decimal currency' => [1000.0, 'JPY', 1000],
]);

it('maps declines and validation errors to messages', function(array $data, string $code, string $message) {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response($data)]);
    $transaction = ewayTransaction();

    $response = $transaction->getGateway()->purchase($transaction, ewayCardForm());

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->isProcessing())->toBeFalse()
        ->and($response->isRedirect())->toBeFalse()
        ->and($response->getCode())->toBe($code)
        ->and($response->getMessage())->toBe($message);
})->with([
    'declined' => [ewayApproved(['ResponseCode' => '05', 'ResponseMessage' => 'D4405', 'TransactionStatus' => false]), 'D4405', 'Do Not Honour'],
    'several response messages' => [ewayApproved(['ResponseMessage' => 'D4482, D4451', 'TransactionStatus' => false]), 'D4482, D4451', 'CVV Validation Error, Insufficient Funds'],
    'validation errors' => [['TransactionStatus' => false, 'ResponseMessage' => null, 'Errors' => 'V6021,V6126'], 'V6021,V6126', 'EWAY_CARDNAME Required, Invalid encrypted card number, decryption failed'],
    'code missing from the driver' => [['TransactionStatus' => false, 'Errors' => 'V6146'], 'V6146', 'Client Side Encryption Key Missing or Invalid'],
    'unknown code' => [['TransactionStatus' => false, 'Errors' => 'V9999'], 'V9999', 'V9999'],
]);

it('throws when eWAY doesn’t return JSON, such as for a wrong API key', function() {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response('', 401)]);
    $transaction = ewayTransaction();

    expect(fn() => $transaction->getGateway()->purchase($transaction, ewayCardForm()))
        ->toThrow(RuntimeException::class, 'eWAY returned an invalid response (HTTP 401).');
});

it('fails the transaction when a payment is declined', function() {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response(ewayApproved(['ResponseMessage' => 'D4405', 'TransactionStatus' => false]))]);
    $transaction = ewayTransaction();
    $order = $transaction->getOrder();
    // The test order has no line items, so let the gateway process it instead of completing it as a free order.
    $order->getStore()->setFreeOrderPaymentStrategy(Store::FREE_ORDER_PAYMENT_STRATEGY_PROCESS);
    $redirect = null;
    $result = null;

    try {
        app(Payments::class)->processPayment($order, ewayCardForm(), $redirect, $result);
        $this->fail('The payment should have failed.');
    } catch (PaymentException $e) {
        expect($e->getMessage())->toBe('Do Not Honour');
    }

    expect($result->status)->toBe(TransactionModel::STATUS_FAILED)
        ->and($result->code)->toBe('D4405')
        ->and($result->response)->toBeArray();
});

it('pays with a saved token customer as a MOTO token payment', function() {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response(ewayApproved())]);
    $transaction = ewayTransaction();

    $form = new EwayPaymentForm();
    $form->populateFromPaymentSource(new PaymentSource(['token' => '917758625852']));
    $transaction->getGateway()->purchase($transaction, $form);

    Http::assertSent(fn(Request $request) => $request['Method'] === 'TokenPayment'
        && $request['TransactionType'] === 'MOTO'
        && $request['Customer']['TokenCustomerID'] === '917758625852'
        && $request['Customer']['CardDetails'] === ['Name' => 'Jenny Andrews']);
});

it('keeps the Purchase transaction type for token payments with a CVV', function() {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response(ewayApproved())]);
    $transaction = ewayTransaction();

    $form = ewayCardForm();
    $form->cardReference = '917758625852';
    $transaction->getGateway()->purchase($transaction, $form);

    Http::assertSent(fn(Request $request) => $request['Method'] === 'TokenPayment'
        && $request['TransactionType'] === 'Purchase'
        && $request['Customer']['CardDetails']['CVN'] === 'eCrypted:cvv');
});

it('authorizes a payment', function() {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response(ewayApproved(['TransactionType' => 'Authorise']))]);
    $transaction = ewayTransaction(type: TransactionModel::TYPE_AUTHORIZE);

    $form = ewayCardForm();
    $form->cardReference = '917758625852';
    $response = $transaction->getGateway()->authorize($transaction, $form);

    expect($response->isSuccessful())->toBeTrue();
    Http::assertSent(fn(Request $request) => $request['Method'] === 'Authorise'
        && $request['TransactionType'] === 'Purchase'
        && $request['Customer']['TokenCustomerID'] === '917758625852'
        && $request['Payment']['TotalAmount'] === 1230);
});

it('captures a pre-auth', function() {
    Http::fake([EWAY_LIVE . '/CapturePayment' => Http::response(['ResponseCode' => '399854', 'ResponseMessage' => '399854', 'TransactionID' => 11260840, 'TransactionStatus' => true, 'Errors' => null])]);
    $authorization = ewayTransaction(20, type: TransactionModel::TYPE_AUTHORIZE);
    $authorization->reference = '11260833';
    $capture = app(Transactions::class)->createTransaction(null, $authorization, TransactionModel::TYPE_CAPTURE);

    $response = $authorization->getGateway()->capture($capture, '11260833');

    expect($response->isSuccessful())->toBeTrue()
        ->and($response->getTransactionReference())->toBe('11260840');

    Http::assertSent(fn(Request $request) => $request->url() === EWAY_LIVE . '/CapturePayment'
        && $request->hasHeader('Authorization', 'Basic ' . base64_encode('api-key:api-password'))
        && $request->data() === [
            'Payment' => [
                'TotalAmount' => 2000,
                'InvoiceNumber' => $capture->hash,
                'InvoiceDescription' => 'Order #' . $capture->orderId,
                'CurrencyCode' => 'USD',
            ],
            'TransactionId' => '11260833',
        ]);
});

it('reports a failed capture', function() {
    Http::fake([EWAY_LIVE . '/CapturePayment' => Http::response(['TransactionStatus' => false, 'Errors' => 'V6134'])]);
    $authorization = ewayTransaction(type: TransactionModel::TYPE_AUTHORIZE);
    $capture = app(Transactions::class)->createTransaction(null, $authorization, TransactionModel::TYPE_CAPTURE);

    $response = $authorization->getGateway()->capture($capture, '11260833');

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toBe('Invalid Auth Transaction ID for Capture/Void');
});

it('refunds part of a payment through Commerce', function() {
    Http::fake([EWAY_LIVE . '/DirectRefund.json' => Http::response([
        'AuthorisationCode' => '587611',
        'ResponseCode' => null,
        'ResponseMessage' => 'A2000',
        'TransactionID' => 11259552,
        'TransactionStatus' => true,
        'Refund' => ['TransactionID' => '11259550', 'TotalAmount' => 525],
        'Errors' => null,
    ])]);
    $purchase = ewayTransaction(20);
    $purchase->reference = '11259550';
    $purchase->status = TransactionModel::STATUS_SUCCESS;
    app(Transactions::class)->saveTransaction($purchase);

    $refund = app(Payments::class)->refundTransaction($purchase, 5.25, 'Damaged');

    expect($refund->status)->toBe(TransactionModel::STATUS_SUCCESS)
        ->and($refund->reference)->toBe('11259552')
        ->and($refund->message)->toBe('Transaction Approved');

    Http::assertSent(fn(Request $request) => $request->url() === EWAY_LIVE . '/DirectRefund.json'
        && $request->data() === [
            'DeviceID' => 'craftcms/commerce-eway',
            'CustomerIP' => '127.0.0.1',
            'Refund' => [
                'TotalAmount' => 525,
                'TransactionID' => '11259550',
                'InvoiceNumber' => $refund->hash,
                'InvoiceDescription' => 'Order #' . $purchase->orderId,
                'CurrencyCode' => 'USD',
            ],
        ]);
});

it('fails a refund eWAY rejects', function() {
    Http::fake([EWAY_LIVE . '/DirectRefund.json' => Http::response(['TransactionStatus' => false, 'ResponseMessage' => null, 'Errors' => 'V6151'])]);
    $purchase = ewayTransaction(20);
    $purchase->reference = '11259550';
    $purchase->status = TransactionModel::STATUS_SUCCESS;
    app(Transactions::class)->saveTransaction($purchase);

    $refund = app(Payments::class)->refundTransaction($purchase, 5.0);

    expect($refund->status)->toBe(TransactionModel::STATUS_FAILED)
        ->and($refund->message)->toBe('Refund amount greater than original transaction');
});

it('lets listeners modify each request', function() {
    Http::fake([EWAY_LIVE . '/*' => Http::response(ewayApproved())]);
    $types = [];
    Event::listen(function(PaymentRequestBuilding $event) use (&$types) {
        $types[] = $event->type;
        $event->request['PartnerID'] = 'partner';
        $event->request['Customer']['Title'] = null;
    });
    $transaction = ewayTransaction();

    $transaction->getGateway()->purchase($transaction, ewayCardForm());

    expect($types)->toBe(['purchase']);
    Http::assertSent(fn(Request $request) => $request['PartnerID'] === 'partner'
        && !array_key_exists('Title', $request['Customer']));
});

it('sends the cart when “Send cart information” is on', function() {
    Http::fake([EWAY_LIVE . '/*' => Http::response(ewayApproved())]);
    $transaction = ewayTransaction(settings: ['sendCartInfo' => true]);
    $order = $transaction->getOrder();
    $order->setLineItems([]);
    $order->setAdjustments([
        new \CraftCms\Commerce\Order\Data\OrderAdjustment(['name' => 'Coupon', 'description' => '10% off', 'type' => 'discount', 'amount' => -2.5]),
        new \CraftCms\Commerce\Order\Data\OrderAdjustment(['name' => 'GST', 'description' => 'Included tax', 'type' => 'tax', 'amount' => 1.12, 'included' => true]),
        new \CraftCms\Commerce\Order\Data\OrderAdjustment(['name' => 'Handling', 'description' => 'Handling fee', 'type' => 'shipping', 'amount' => 3.0]),
    ]);

    $transaction->getGateway()->purchase($transaction, ewayCardForm());

    Http::assertSent(fn(Request $request) => $request['Items'] === [
        ['SKU' => 'Discount', 'Description' => '10% off', 'Quantity' => '1', 'UnitCost' => '-250'],
        ['SKU' => 'Handling', 'Description' => 'Handling fee', 'Quantity' => '1', 'UnitCost' => '300'],
    ]);
});

it('doesn’t send the cart by default', function() {
    Http::fake([EWAY_LIVE . '/*' => Http::response(ewayApproved())]);
    $transaction = ewayTransaction();
    $transaction->getOrder()->setAdjustments([
        new \CraftCms\Commerce\Order\Data\OrderAdjustment(['name' => 'Handling', 'description' => 'Handling fee', 'type' => 'shipping', 'amount' => 3.0]),
    ]);

    $transaction->getGateway()->purchase($transaction, ewayCardForm());

    Http::assertSent(fn(Request $request) => !isset($request['Items']));
});

it('creates a token customer for a payment source', function() {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response(ewayApproved([
        'TransactionID' => null,
        'TransactionStatus' => false,
        'Customer' => ['TokenCustomerID' => 917758625852, 'CardDetails' => ['Number' => '444433XXXXXX1111', 'Name' => 'Card Holder']],
    ]))]);
    $gateway = ewayGateway();
    app(Gateways::class)->saveGateway($gateway);

    $source = $gateway->createPaymentSource(ewayCardForm(), 999);

    expect($source->token)->toBe('917758625852')
        ->and($source->gatewayId)->toBe($gateway->id)
        ->and($source->customerId)->toBe(999)
        ->and($source->description)->toBe('Payment card 444433XXXXXX1111')
        ->and(json_decode($source->response, true)['Customer']['TokenCustomerID'])->toBe(917758625852);

    Http::assertSent(fn(Request $request) => $request['Method'] === 'CreateTokenCustomer'
        && $request['TransactionType'] === 'Purchase'
        && $request['Payment'] === ['TotalAmount' => 0]
        && $request['Customer']['CardDetails']['Number'] === 'eCrypted:number'
        && $request['Customer']['CardDetails']['CVN'] === 'eCrypted:cvv'
        && $request['Customer']['FirstName'] === 'Card');
});

it('throws a payment source exception when eWAY rejects the card', function() {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response(['ResponseMessage' => null, 'Errors' => 'V6044,V6127'])]);

    expect(fn() => ewayGateway()->createPaymentSource(ewayCardForm(), 999))
        ->toThrow(PaymentSourceException::class, 'Customer CountryCode Required, Invalid encrypted cvn, decryption failed');
});

it('throws a payment source exception when eWAY can’t be reached', function() {
    Http::fake([EWAY_LIVE . '/DirectPayment.json' => Http::response('Unauthorized', 401)]);

    expect(fn() => ewayGateway()->createPaymentSource(ewayCardForm(), 999))
        ->toThrow(PaymentSourceException::class, 'HTTP 401');
});

it('deletes payment sources without calling eWAY', function() {
    Http::fake();

    expect(ewayGateway()->deletePaymentSource('917758625852'))->toBeTrue();
    Http::assertNothingSent();
});

it('validates the encrypted card fields', function() {
    $form = new EwayPaymentForm();
    $form->setAttributes(['firstName' => 'Card', 'lastName' => 'Holder', 'number' => '4444333322221111', 'expiry' => '13/2000']);

    expect($form->validate())->toBeFalse()
        ->and($form->errors()->keys())->toContain('month', 'year', 'encryptedCardNumber', 'encryptedCardCvv')
        ->not->toContain('number', 'cvv');

    expect(ewayCardForm()->validate())->toBeTrue();
});

it('doesn’t validate card fields when paying with a payment source', function() {
    $form = new EwayPaymentForm();
    $form->populateFromPaymentSource(new PaymentSource(['token' => '917758625852']));

    expect($form->validate())->toBeTrue()
        ->and($form->cardReference)->toBe('917758625852');
});

it('renders the card form with the encryption key and namespace', function() {
    $html = ewayGateway(['CSEKey' => 'cse-key<"&'])->getPaymentFormHtml([]);

    expect($html)->toContain('<div class="eway-form" data-key="cse-key&lt;&quot;&amp;" data-payment-form-namespace="paymentForm[eway]"></div>')
        ->toContain('name="firstName"')
        ->toContain('name="lastName"')
        ->toContain('name="number"')
        ->toContain('name="expiry"')
        ->toContain('type="tel" id="ui-cvv-input" class="text fullwidth form-control" name="cvv"')
        ->not->toContain('inert');
});

it('shows the submitted values and errors on the card form', function() {
    $form = new EwayPaymentForm();
    $form->setAttributes(['firstName' => 'Card', 'lastName' => 'Holder', 'expiry' => '13/2000']);
    $form->validate();

    $html = ewayGateway()->getPaymentFormHtml(['paymentForm' => $form]);

    expect($html)->toContain('name="firstName" value="Card"')
        ->toContain('name="expiry" value="13/2000"')
        ->toMatch('/id="ui-number".*?<li>[^<]*encrypted card number[^<]*<\\/li>/is')
        ->toMatch('/id="ui-cvv".*?<li>[^<]*encrypted card cvv[^<]*<\\/li>/is')
        ->toMatch('/id="ui-expiry".*?<li>[^<]*month[^<]*<\\/li>/is');
});

it('renders the CVV confirmation form for saved payment sources', function() {
    $html = ewayGateway()->getPaymentConfirmationFormHtml([]);

    expect($html)->toContain('class="eway-form" data-key="cse-key" data-payment-form-namespace="paymentForm[eway]"')
        ->toContain('name="cvv"')
        ->not->toContain('name="number"');
});

it('registers the eCrypt library and the encryption script with the payment form', function() {
    ewayGateway()->getPaymentFormHtml([]);

    $html = HtmlStack::bodyEndHtml();

    expect($html)->toContain('<script src="https://secure.ewaypayments.com/scripts/eCrypt.min.js"></script>')
        ->toContain('eCrypt.encryptValue($number.value, key)')
        ->toContain('\'input[name="\' + paymentFormNamespace + \'[number]"]\'')
        ->toContain("'[encryptedCardNumber]'")
        ->toContain("'[encryptedCardCvv]'");
});
