<?php

namespace craft\commerce\eway\gateways;

use Craft;
use craft\commerce\controllers\PaymentsController;
use craft\commerce\errors\PaymentException;
use craft\commerce\eway\EwayPaymentBundle;
use craft\commerce\eway\models\EwayPaymentForm;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\omnipay\base\CreditCardGateway;
use craft\helpers\Html;
use craft\web\View;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\ResponseInterface;
use Omnipay\Eway\Message\RapidResponse;
use Omnipay\Omnipay;
use Omnipay\Eway\RapidDirectGateway as OmnipayGateway;
use Throwable;

/**
 * Gateway represents eWay Rapid Direct gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 *
 * @property-read null|string $settingsHtml
 */
class Gateway extends CreditCardGateway
{
    /**
     * @var string|null
     */
    public ?string $apiKey = null;

    /**
     * @var string|null
     */
    public ?string $password = null;

    /**
     * @var boolean
     */
    public bool $testMode = false;

    /**
     * @var string|null
     */
    public ?string $CSEKey = null;

    /**
     * @var bool Whether cart information should be sent to the payment gateway
     */
    public bool $sendCartInfo = false;

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'eWay Rapid');
    }

    /**
     * @inheritdoc
     */
    public function getPaymentConfirmationFormHtml(array $params): string
    {
        return $this->_displayFormHtml($params, 'commerce-eway/confirmationForm');
    }


    /**
     * @inheritdoc
     */
    public function getPaymentFormHtml(array $params): ?string
    {
        return $this->_displayFormHtml($params, 'commerce-eway/paymentForm');
    }

    /**
     * @inheritdoc
     */
    public function getPaymentFormModel(): BasePaymentForm
    {
        return new EwayPaymentForm();
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-eway/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * @inheritdoc
     */
    public function populateRequest(array &$request, BasePaymentForm $form = null): void
    {
        /** @var EwayPaymentForm $form */
        if ($form) {
            $request['encryptedCardNumber'] = $form->encryptedCardNumber ?? null;
            $request['encryptedCardCvv'] = $form->encryptedCardCvv ?? null;

            $request['cardReference'] = $form->cardReference ?? null;
        }
    }

    /**
     * @inheritdoc
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var OmnipayGateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());

        $gateway->setApiKey(Craft::parseEnv($this->apiKey));
        $gateway->setPassword(Craft::parseEnv($this->password));
        $gateway->setTestMode($this->testMode);

        return $gateway;
    }

    /**
     * @inheritdoc
     */
    protected function extractCardReference(ResponseInterface $response): string
    {
        /** @var RapidResponse $response */
        if ($response->getCode() !== 'A2000') {
            throw new PaymentException($response->getMessage());
        }

        return $response->getCardReference();
    }


    /**
     * @inheritdoc
     */
    protected function extractPaymentSourceDescription(ResponseInterface $response): string
    {
        $data = $response->getData();

        return Craft::t('commerce-eway', 'Payment card {masked}', ['masked' => $data['Customer']['CardDetails']['Number']]);
    }

    /**
     * @inheritdoc
     */
    protected function getGatewayClassName(): ?string
    {
        return '\\'.OmnipayGateway::class;
    }

    /**
     * Display a payment form from HTML based on params and template path
     *
     * @param array $params   Parameters to use
     * @param string $template Template to use
     *
     * @return string
     * @throws Throwable if unable to render the template
     */
    private function _displayFormHtml(array $params, string $template): string
    {
        $defaults = [
            'gateway' => $this,
            'paymentForm' => $this->getPaymentFormModel(),
            'handle' => $this->handle,
        ];

        $params = array_merge($defaults, $params);

        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $view->registerJsFile('https://secure.ewaypayments.com/scripts/eCrypt.min.js');
        $view->registerAssetBundle(EwayPaymentBundle::class);

        $html = Craft::$app->getView()->renderTemplate($template, $params);

        $view->setTemplateMode($previousMode);

        return $html;
    }
}
