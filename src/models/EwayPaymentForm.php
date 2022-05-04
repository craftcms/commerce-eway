<?php

namespace craft\commerce\eway\models;

use craft\commerce\models\payments\CreditCardPaymentForm;
use craft\commerce\models\PaymentSource;

/**
 * Eway Payment form model.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class EwayPaymentForm extends CreditCardPaymentForm
{
    /**
     * @var string|null
     */
    public ?string $encryptedCardNumber = null;

    /**
     * @var string|null
     */
    public ?string $encryptedCardCvv = null;

    /**
     * @var string|null credit card reference
     */
    public ?string $cardReference = null;

    /**
     * @inheritdoc
     */
    public function populateFromPaymentSource(PaymentSource $paymentSource): void
    {
        $this->cardReference = $paymentSource->token;
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        if (empty($this->cardReference)) {
            return [
                [['firstName', 'lastName', 'month', 'year', 'encryptedCardNumber', 'encryptedCardCvv'], 'required'],
                [['month'], 'integer', 'integerOnly' => true, 'min' => 1, 'max' => 12],
                [['year'], 'integer', 'integerOnly' => true, 'min' => date('Y'), 'max' => date('Y') + 12],
            ];
        }

        return [];
    }
}
