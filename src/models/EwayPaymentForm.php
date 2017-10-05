<?php

namespace craft\commerce\eway\models;

use craft\commerce\models\payments\CreditCardPaymentForm;

/**
 * Eway Payment form model.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 */
class EwayPaymentForm extends CreditCardPaymentForm
{
    /**
     * @var string
     */
    public $encryptedCardNumber;

    /**
     * @var string
     */
    public $encryptedCardCvv;

    /**
     * @inheritdoc
     */
    public function setAttributes($values, $safeOnly = true)
    {
        parent::setAttributes($values, $safeOnly);
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['firstName', 'lastName', 'month', 'year', 'encryptedCardNumber', 'encryptedCardCvv'], 'required'],
            [['month'], 'integer', 'integerOnly' => true, 'min' => 1, 'max' => 12],
            [['year'], 'integer', 'integerOnly' => true, 'min' => date('Y'), 'max' => date('Y') + 12],
        ];
    }
}