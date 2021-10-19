<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

namespace craft\commerce\eway;

use yii\web\AssetBundle;

/**
 * Asset bundle for the Dashboard
 */
class EwayPaymentBundle extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@craft/commerce/eway/resources';

        $this->js = [
            'js/paymentForm.js',
        ];

        parent::init();
    }
}
