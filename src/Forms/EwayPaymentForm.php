<?php

declare(strict_types=1);

namespace CraftCms\CommerceEway\Forms;

use CraftCms\Commerce\Payment\Data\PaymentSource;
use CraftCms\Commerce\Payment\Forms\CreditCardPaymentForm;
use Override;

/**
 * The eWAY payment form.
 *
 * The card number and CVV are encrypted in the browser with eWAY’s client side encryption, and posted as
 * `encryptedCardNumber` and `encryptedCardCvv`. The plain `number` and `cvv` fields are never sent to the server.
 */
class EwayPaymentForm extends CreditCardPaymentForm
{
    public ?string $encryptedCardNumber = null;

    public ?string $encryptedCardCvv = null;

    /**
     * The eWAY token customer ID, when paying with a saved payment source.
     */
    public ?string $cardReference = null;

    #[Override]
    public function populateFromPaymentSource(PaymentSource $paymentSource): void
    {
        $this->cardReference = $paymentSource->token;
    }

    #[Override]
    public function getRules(): array
    {
        if (!empty($this->cardReference)) {
            return [];
        }

        return [
            'firstName' => ['required'],
            'lastName' => ['required'],
            'month' => ['required', 'numeric', 'min:1', 'max:12'],
            'year' => ['required', 'numeric', 'min:' . date('Y'), 'max:' . ((int)date('Y') + 12)],
            'encryptedCardNumber' => ['required'],
            'encryptedCardCvv' => ['required'],
        ];
    }
}
