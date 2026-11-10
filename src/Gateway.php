<?php

declare(strict_types=1);

namespace CraftCms\CommerceEway;

use CraftCms\Cms\Address\Elements\Address;
use CraftCms\Cms\Cp\SelectOptions;
use CraftCms\Cms\Support\Env;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\Support\Facades\Users;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Ui\Controls\BooleanMenu;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\TemplateContent;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\View\Enums\Position;
use CraftCms\Commerce\Exceptions\NotImplementedException;
use CraftCms\Commerce\Helpers\PaymentForm as PaymentFormHelper;
use CraftCms\Commerce\Order\Adjuster\Discount;
use CraftCms\Commerce\Order\Carts;
use CraftCms\Commerce\Order\Elements\Order;
use CraftCms\Commerce\Payment\Currencies;
use CraftCms\Commerce\Payment\Data\PaymentSource;
use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Exceptions\PaymentSourceException;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;
use CraftCms\Commerce\Payment\Forms\CreditCardPaymentForm;
use CraftCms\Commerce\Payment\Gateway\Contracts\RequestResponseInterface;
use CraftCms\Commerce\Payment\Gateway\Gateway as BaseGateway;
use CraftCms\Commerce\Payment\Models\Transaction as TransactionModel;
use CraftCms\CommerceEway\Events\PaymentRequestBuilding;
use CraftCms\CommerceEway\Forms\EwayPaymentForm;
use CraftCms\CommerceEway\Responses\RapidResponse;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * eWAY Rapid gateway, using Direct Connection with client side encryption.
 *
 * The card number and CVV are encrypted in the customer’s browser with eWAY’s eCrypt library, so they never reach the
 * server in plain text. Payments, pre-auths, captures and refunds are processed synchronously, and saved payment
 * sources are eWAY token customers.
 */
class Gateway extends BaseGateway
{
    /**
     * The eWAY client side encryption library.
     *
     * @see https://eway.io/api-v3/#client-side-encryption
     */
    public const string ECRYPT_JS_URL = 'https://secure.ewaypayments.com/scripts/eCrypt.min.js';

    /**
     * Identifies the application to eWAY (`DeviceID`).
     */
    public const string DEVICE_ID = 'craftcms/commerce-eway';

    /**
     * @var string|null The Rapid API key. Can be set to an environment variable.
     */
    public ?string $apiKey = null;

    /**
     * @var string|null The Rapid API password. Can be set to an environment variable.
     */
    public ?string $password = null;

    /**
     * @var string|null The client side encryption key. Can be set to an environment variable.
     */
    public ?string $CSEKey = null;

    /**
     * @var bool|string Whether to use the eWAY sandbox. Can be set to an environment variable.
     */
    public bool|string $testMode = false;

    /**
     * @var bool|string Whether to send the order’s line items and adjustments to eWAY. Can be set to an environment variable.
     */
    public bool|string $sendCartInfo = false;

    #[Override]
    public static function displayName(): string
    {
        return t('eWay Rapid', category: 'commerce-eway');
    }

    public function getApiKey(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->apiKey) : $this->apiKey;
    }

    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function getPassword(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->password) : $this->password;
    }

    public function setPassword(?string $password): void
    {
        $this->password = $password;
    }

    public function getCSEKey(bool $parse = true): ?string
    {
        return $parse ? Env::parse($this->CSEKey) : $this->CSEKey;
    }

    public function setCSEKey(?string $CSEKey): void
    {
        $this->CSEKey = $CSEKey;
    }

    public function getTestMode(bool $parse = true): bool|string
    {
        return $parse ? (Env::parseBoolean($this->testMode) ?? false) : $this->testMode;
    }

    public function setTestMode(bool|string $testMode): void
    {
        $this->testMode = $testMode;
    }

    public function getSendCartInfo(bool $parse = true): bool|string
    {
        return $parse ? (Env::parseBoolean($this->sendCartInfo) ?? false) : $this->sendCartInfo;
    }

    public function setSendCartInfo(bool|string $sendCartInfo): void
    {
        $this->sendCartInfo = $sendCartInfo;
    }

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'apiKey' => ['required'],
            'password' => ['required'],
            'CSEKey' => ['required'],
        ]);
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext()): ?Ui
    {
        return Ui::make([
            Field::make(t('Should commerce send cart information to gateway when making transactions.', category: 'commerce'))
                ->control(BooleanMenu::make('sendCartInfo')
                    ->value(BooleanMenu::optionValue($this->sendCartInfo))
                    ->includeEnvVars()),
            Field::make(t('API Key', category: 'commerce'))
                ->required()
                ->control(Text::make('apiKey')
                    ->value($this->apiKey)
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
            Field::make(t('Password', category: 'commerce'))
                ->required()
                ->control(Text::make('password')
                    ->value($this->password)
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
            Field::make(t('Client side encryption key', category: 'commerce'))
                ->required()
                ->control(Text::make('CSEKey')
                    ->value($this->CSEKey)
                    ->textExpanderTriggers(SelectOptions::getEnvTextExpanderTriggers())),
            Field::make(t('Test mode?', category: 'commerce'))
                ->control(BooleanMenu::make('testMode')
                    ->value(BooleanMenu::optionValue($this->testMode))
                    ->includeEnvVars()),
        ]);
    }

    #[Override]
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new EwayPaymentForm();
    }

    /**
     * The card form. The card number and CVV are encrypted in the browser and posted as `encryptedCardNumber` and
     * `encryptedCardCvv`; the plain `number` and `cvv` inputs are disabled before the form is submitted.
     */
    #[Override]
    public function paymentFormUi(BasePaymentForm $form, array $params = []): ?Ui
    {
        $this->registerPaymentFormJs();

        if ($form instanceof EwayPaymentForm) {
            // Show errors for the posted fields on the inputs the customer sees.
            $this->moveErrors($form, ['encryptedCardNumber' => 'number', 'encryptedCardCvv' => 'cvv', 'month' => 'expiry', 'year' => 'expiry']);
        }

        return Ui::make([
            $this->encryptionNode(),
            Field::make(t('First Name', category: 'commerce'), Text::make('firstName')->maxLength(70)->autocomplete(false))
                ->required()
                ->width(50),
            Field::make(t('Last Name', category: 'commerce'), Text::make('lastName')->maxLength(70)->autocomplete(false))
                ->required()
                ->width(50),
            Field::make(t('Card Number', category: 'commerce'), Text::make('number')->maxLength(19)->autocomplete(false))
                ->required(),
            Field::make(t('Expiry', category: 'commerce'), Text::make('expiry')->placeholder(t('MM', category: 'commerce') . ' / ' . t('YYYY', category: 'commerce')))
                ->required()
                ->width(50),
            Field::make(t('CVV', category: 'commerce'), Text::make('cvv')->inputType('tel')->maxLength(4)->autocomplete(false))
                ->required()
                ->width(50),
        ]);
    }

    /**
     * Asks for the CVV when paying with a saved payment source, and encrypts it like the card form does.
     */
    #[Override]
    public function paymentConfirmationFormUi(array $params = []): ?Ui
    {
        $this->registerPaymentFormJs();

        return Ui::make([
            $this->encryptionNode(),
            Field::make(t('CVV', category: 'commerce'), Text::make('cvv')->inputType('tel')->maxLength(4)->autocomplete(false)),
        ]);
    }

    /**
     * Registers eWAY’s eCrypt library and the script that encrypts the card number and CVV when the payment form is
     * submitted. The script looks for an element with the `eway-form` class and these data attributes:
     *
     * - `data-key`: the client side encryption key ({@see getCSEKey()})
     * - `data-payment-form-namespace`: the gateway’s payment form namespace, such as `paymentForm[eway]`
     */
    protected function registerPaymentFormJs(): void
    {
        HtmlStack::jsFile(self::ECRYPT_JS_URL);
        HtmlStack::js((string)file_get_contents(dirname(__DIR__) . '/resources/js/paymentForm.js'), Position::Ready, 'commerce-eway-payment-form');
    }

    /**
     * The element the encryption script reads the key and the payment form namespace from.
     */
    private function encryptionNode(): TemplateContent
    {
        return TemplateContent::make('eway-encryption', Html::tag('div', '', [
            'class' => 'eway-form',
            'data' => [
                'key' => (string)$this->getCSEKey(),
                'payment-form-namespace' => PaymentFormHelper::getPaymentFormNamespace((string)$this->handle),
            ],
        ]))->trusted();
    }

    /**
     * @param array<string, string> $map
     */
    private function moveErrors(BasePaymentForm $form, array $map): void
    {
        $errors = $form->errors();

        foreach ($map as $from => $to) {
            foreach ($errors->get($from) as $message) {
                if (!in_array($message, $errors->get($to), true)) {
                    $errors->add($to, $message);
                }
            }
        }
    }

    #[Override]
    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        $request = $this->paymentRequest($transaction, $form);
        $hasToken = isset($request['Customer']['TokenCustomerID']);

        if ($hasToken && empty($request['Customer']['CardDetails']['CVN'])) {
            // A token payment without the card present is a MOTO transaction.
            $request['TransactionType'] = 'MOTO';
        }

        $request['Method'] = $hasToken ? 'TokenPayment' : 'ProcessPayment';

        return new RapidResponse($this->client()->directPayment($this->dispatchRequestBuilding(TransactionModel::TYPE_PURCHASE, $transaction, $form, $request)));
    }

    #[Override]
    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        $request = $this->paymentRequest($transaction, $form);
        $request['Method'] = 'Authorise';

        return new RapidResponse($this->client()->directPayment($this->dispatchRequestBuilding(TransactionModel::TYPE_AUTHORIZE, $transaction, $form, $request)));
    }

    #[Override]
    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        $request = [
            'Payment' => $this->paymentDetails($transaction),
            'TransactionId' => $reference,
        ];

        return new RapidResponse($this->client()->capturePayment($this->dispatchRequestBuilding(TransactionModel::TYPE_CAPTURE, $transaction, null, $request)));
    }

    #[Override]
    public function refund(Transaction $transaction): RequestResponseInterface
    {
        // Refund transactions start with their parent’s reference, which is the eWAY transaction ID.
        $payment = $this->paymentDetails($transaction);
        $request = $this->baseRequest() + [
            'Refund' => [
                'TotalAmount' => $payment['TotalAmount'],
                'TransactionID' => (string)$transaction->reference,
                'InvoiceNumber' => $payment['InvoiceNumber'],
                'InvoiceDescription' => $payment['InvoiceDescription'],
                'CurrencyCode' => $payment['CurrencyCode'],
            ],
        ];

        return new RapidResponse($this->client()->directRefund($this->dispatchRequestBuilding(TransactionModel::TYPE_REFUND, $transaction, null, $request)));
    }

    /**
     * Creates an eWAY token customer from the encrypted card details.
     *
     * The customer’s details come from their primary billing address, or the current cart’s billing address.
     */
    #[Override]
    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        $customer = Users::getUserById($customerId);
        /** @phpstan-ignore-next-line method.notFound (getPrimaryBillingAddress() is a Commerce macro on User) */
        $address = $customer?->getPrimaryBillingAddress() ?? app(Carts::class)->peekCart()?->getBillingAddress();

        $request = $this->baseRequest() + [
            'Customer' => $this->customerDetails($sourceData, $address, $customer?->email),
            'TransactionType' => 'Purchase',
            'Payment' => ['TotalAmount' => 0],
            'Method' => 'CreateTokenCustomer',
        ];

        try {
            $data = $this->client()->directPayment($this->dispatchRequestBuilding('paymentSource', null, $sourceData, $request));
        } catch (Throwable $e) {
            throw new PaymentSourceException($e->getMessage(), 0, $e);
        }

        $code = (string)($data['ResponseMessage'] ?? '');

        if ($code !== 'A2000') {
            throw new PaymentSourceException(RapidResponse::describeCodes($code ?: (string)($data['Errors'] ?? '')));
        }

        $token = $data['Customer']['TokenCustomerID'] ?? $data['TokenCustomerID'] ?? null;

        if (!$token) {
            throw new PaymentSourceException(t('eWAY didn’t return a token customer ID.', category: 'commerce-eway'));
        }

        $paymentSource = new PaymentSource();
        $paymentSource->customerId = $customerId;
        $paymentSource->gatewayId = (int)$this->id;
        $paymentSource->token = (string)$token;
        $paymentSource->response = Json::encode($data);
        $paymentSource->description = t('Payment card {masked}', [
            'masked' => (string)($data['Customer']['CardDetails']['Number'] ?? ''),
        ], category: 'commerce-eway');

        return $paymentSource;
    }

    /**
     * eWAY has no API for deleting token customers, so the token is only removed from Commerce.
     */
    #[Override]
    public function deletePaymentSource(string $token): bool
    {
        return true;
    }

    #[Override]
    public function processWebHook(): Response
    {
        return response('');
    }

    #[Override]
    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(t('Completing authorization is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(t('Completing purchase is not supported by this gateway', category: 'commerce'));
    }

    #[Override]
    public function supportsAuthorize(): bool
    {
        return true;
    }

    #[Override]
    public function supportsCapture(): bool
    {
        return true;
    }

    #[Override]
    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    #[Override]
    public function supportsCompletePurchase(): bool
    {
        return false;
    }

    #[Override]
    public function supportsPaymentSources(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPurchase(): bool
    {
        return true;
    }

    #[Override]
    public function supportsRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsPartialRefund(): bool
    {
        return true;
    }

    #[Override]
    public function supportsWebhooks(): bool
    {
        return false;
    }

    /**
     * Builds the Direct Connection request shared by purchases and pre-auths, without the `Method`.
     *
     * @return array<string, mixed>
     */
    protected function paymentRequest(Transaction $transaction, BasePaymentForm $form): array
    {
        $order = $transaction->getOrder() ?? throw new InvalidArgumentException('The transaction has no order.');
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();
        $customer = $this->customerDetails($form, $billingAddress, $order->getEmail());

        // Without a shipping address, the shipping name is the name on the card, as Omnipay sent it.
        $creditCard = $form instanceof CreditCardPaymentForm ? $form : null;
        $shipping = $shippingAddress ? [
            'FirstName' => $shippingAddress->getGivenName(),
            'LastName' => $shippingAddress->getFamilyName(),
        ] : [
            'FirstName' => $creditCard?->firstName ?: $customer['FirstName'],
            'LastName' => $creditCard?->lastName ?: $customer['LastName'],
        ];

        if ($shippingAddress) {
            $shipping += [
                'Street1' => $shippingAddress->getAddressLine1(),
                'Street2' => $shippingAddress->getAddressLine2(),
                'City' => $shippingAddress->getLocality(),
                'State' => $shippingAddress->getAdministrativeArea(),
                'Country' => strtolower($shippingAddress->getCountryCode()),
                'PostalCode' => $shippingAddress->getPostalCode(),
            ];
        }

        $request = $this->baseRequest() + [
            'Customer' => $customer,
            'ShippingAddress' => $shipping,
            'TransactionType' => 'Purchase',
        ];

        if ($this->getSendCartInfo()) {
            $request['Items'] = $this->lineItems($order, (string)$transaction->paymentCurrency);
        }

        $request['Payment'] = $this->paymentDetails($transaction);

        return $request;
    }

    /**
     * Returns the eWAY `Customer` object: billing details, the card details and, when paying with a saved payment
     * source, the token customer ID.
     *
     * @return array<string, mixed>
     */
    protected function customerDetails(BasePaymentForm $form, ?Address $billingAddress, ?string $email): array
    {
        $creditCard = $form instanceof CreditCardPaymentForm ? $form : null;
        $cardFirstName = $creditCard?->firstName ?: $billingAddress?->getGivenName();
        $cardLastName = $creditCard?->lastName ?: $billingAddress?->getFamilyName();

        $customer = [
            'FirstName' => $billingAddress?->getGivenName() ?: $cardFirstName,
            'LastName' => $billingAddress?->getFamilyName() ?: $cardLastName,
            'CompanyName' => $billingAddress?->getOrganization(),
            'Street1' => $billingAddress?->getAddressLine1(),
            'Street2' => $billingAddress?->getAddressLine2(),
            'City' => $billingAddress?->getLocality(),
            'State' => $billingAddress?->getAdministrativeArea(),
            'PostalCode' => $billingAddress?->getPostalCode(),
            'Country' => $billingAddress ? strtolower($billingAddress->getCountryCode()) : null,
            'Email' => $email,
        ];

        if ($form instanceof EwayPaymentForm && $form->cardReference) {
            $customer['TokenCustomerID'] = $form->cardReference;
        }

        $card = ['Name' => trim($cardFirstName . ' ' . $cardLastName)];

        if ($creditCard && $creditCard->month && $creditCard->year) {
            // eWAY expects two-digit months and years.
            $expiry = gmmktime(0, 0, 0, (int)$creditCard->month, 1, $this->normalizeYear((int)$creditCard->year));
            $card['ExpiryMonth'] = gmdate('m', $expiry);
            $card['ExpiryYear'] = gmdate('y', $expiry);
        }

        if ($creditCard) {
            $card['Number'] = $form instanceof EwayPaymentForm && $form->encryptedCardNumber ? $form->encryptedCardNumber : $creditCard->number;
            $card['CVN'] = $form instanceof EwayPaymentForm && $form->encryptedCardCvv ? $form->encryptedCardCvv : $creditCard->cvv;
        }

        $customer['CardDetails'] = $card;

        return $customer;
    }

    /**
     * Returns the eWAY `Payment` object for a transaction.
     *
     * @return array{TotalAmount: int, InvoiceNumber: string, InvoiceDescription: string, CurrencyCode: string}
     */
    protected function paymentDetails(Transaction $transaction): array
    {
        $currency = (string)$transaction->paymentCurrency;

        return [
            'TotalAmount' => $this->amountInteger($transaction->paymentAmount, $currency),
            'InvoiceNumber' => (string)$transaction->hash,
            'InvoiceDescription' => t('Order', category: 'commerce') . ' #' . $transaction->orderId,
            'CurrencyCode' => $currency,
        ];
    }

    /**
     * Returns the order’s line items and adjustments as eWAY line items, for the “Send cart information” setting.
     *
     * @return list<array{SKU: string, Description: string, Quantity: string, UnitCost: string}>
     */
    protected function lineItems(Order $order, string $currency): array
    {
        $items = [];
        $count = -1;

        foreach ($order->getLineItems() as $lineItem) {
            $unitCost = $this->amountInteger($lineItem->getSalePrice(), $currency);

            // Zero-value items are left out.
            if ($unitCost === 0) {
                continue;
            }

            $count++;
            $description = $lineItem->getDescription() ?: 'Item ' . $count;
            $items[] = [
                'SKU' => $description,
                'Description' => $description,
                'Quantity' => (string)$lineItem->qty,
                'UnitCost' => (string)$unitCost,
            ];
        }

        foreach ($order->getAdjustments() ?? [] as $adjustment) {
            $unitCost = $this->amountInteger($adjustment->amount, $currency);

            if ($adjustment->included || $unitCost === 0) {
                continue;
            }

            $items[] = [
                'SKU' => $adjustment->type === Discount::ADJUSTMENT_TYPE ? t('Discount', category: 'commerce') : $adjustment->name,
                'Description' => (string)$adjustment->description,
                'Quantity' => '1',
                'UnitCost' => (string)$unitCost,
            ];
        }

        return $items;
    }

    /**
     * Returns the fields sent with every Direct Connection and refund request.
     *
     * @return array{DeviceID: string, CustomerIP: string|null}
     */
    protected function baseRequest(): array
    {
        $ip = request()->ip();

        return [
            'DeviceID' => self::DEVICE_ID,
            // eWAY doesn’t accept the IPv6 loopback address.
            'CustomerIP' => $ip === '::1' ? '127.0.0.1' : $ip,
        ];
    }

    /**
     * Returns an amount in the currency’s minor unit, such as cents, which is how eWAY expects amounts.
     */
    protected function amountInteger(float $amount, string $currency): int
    {
        return (int)app(Currencies::class)->getTeller($currency)->convertToMoney($amount)->getAmount();
    }

    /**
     * Dispatches {@see PaymentRequestBuilding}, and returns the request with empty values removed.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    protected function dispatchRequestBuilding(string $type, ?Transaction $transaction, ?BasePaymentForm $form, array $request): array
    {
        $event = new PaymentRequestBuilding($type, $transaction, $form, $request);
        event($event);

        return $this->filterEmpty($event->request);
    }

    protected function client(): Client
    {
        return new Client((string)$this->getApiKey(), (string)$this->getPassword(), (bool)$this->getTestMode());
    }

    /**
     * Removes `null` and empty string values, and objects left empty, so they aren’t sent to eWAY.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function filterEmpty(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = $data[$key] = $this->filterEmpty($value);
            }

            if ($value === null || $value === '' || $value === []) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    private function normalizeYear(int $year): int
    {
        // Two-digit years are in the current century, as Omnipay treated them.
        return $year < 100 ? (int)(floor((int)date('Y') / 100) * 100) + $year : $year;
    }
}
