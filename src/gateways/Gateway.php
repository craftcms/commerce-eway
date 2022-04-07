<?php

namespace craft\commerce\eway\gateways;

use Craft;
use craft\commerce\errors\PaymentException;
use craft\commerce\eway\EwayPaymentBundle;
use craft\commerce\eway\models\EwayPaymentForm;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\omnipay\base\CreditCardGateway;
use craft\helpers\App;
use craft\helpers\Html;
use craft\web\View;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\ResponseInterface;
use Omnipay\Eway\Message\RapidResponse;
use Omnipay\Eway\RapidDirectGateway as OmnipayGateway;
use Throwable;

/**
 * Gateway represents eWay Rapid Direct gateway
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since     1.0
 *
 * @property null|string $cSEKey
 * @property bool|string $testMode
 * @property bool|string $sendCartInfo
 * @property null|string $apiKey
 * @property null|string $password
 * @property-read null|string $settingsHtml
 */
class Gateway extends CreditCardGateway
{
    /**
     * @var string|null
     */
    private ?string $_apiKey = null;

    /**
     * @var string|null
     */
    private ?string $_password = null;

    /**
     * @var bool|string
     */
    private bool|string $_testMode = false;

    /**
     * @var string|null
     */
    private ?string $_CSEKey = null;

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
    public function getSettings(): array
    {
        $settings = parent::getSettings();
        $settings['apiKey'] = $this->getApiKey(false);
        $settings['password'] = $this->getPassword(false);
        $settings['testMode'] = $this->getTestMode(false);
        $settings['CSEKey'] = $this->getCSEKey(false);

        return $settings;
    }

    /**
     * @param bool $parse
     * @return bool|string
     * @since 4.0.0
     */
    public function getTestMode(bool $parse = true): bool|string
    {
        return $parse ? App::parseEnv($this->_testMode) : $this->_testMode;
    }

    /**
     * @param bool|string $testMode
     * @return void
     * @since 4.0.0
     */
    public function setTestMode(bool|string $testMode): void
    {
        $this->_testMode = $testMode;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 4.0.0
     */
    public function getApiKey(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_apiKey) : $this->_apiKey;
    }

    /**
     * @param string|null $apiKey
     * @return void
     * @since 4.0.0
     */
    public function setApiKey(?string $apiKey): void
    {
        $this->_apiKey = $apiKey;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 4.0.0
     */
    public function getPassword(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_password) : $this->_password;
    }

    /**
     * @param string|null $password
     * @return void
     * @since 4.0.0
     */
    public function setPassword(?string $password): void
    {
        $this->_password = $password;
    }

    /**
     * @param bool $parse
     * @return string|null
     * @since 4.0.0
     */
    public function getCSEKey(bool $parse = true): ?string
    {
        return $parse ? App::parseEnv($this->_CSEKey) : $this->_CSEKey;
    }

    /**
     * @param string|null $CSEKey
     * @return void
     * @since 4.0.0
     */
    public function setCSEKey(?string $CSEKey): void
    {
        $this->_CSEKey = $CSEKey;
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
    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null): void
    {
        /** @var EwayPaymentForm|null $paymentForm */
        if ($paymentForm) {
            $request['encryptedCardNumber'] = $paymentForm->encryptedCardNumber ?? null;
            $request['encryptedCardCvv'] = $paymentForm->encryptedCardCvv ?? null;

            $request['cardReference'] = $paymentForm->cardReference ?? null;
        }
    }

    /**
     * @inheritdoc
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var OmnipayGateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());

        $gateway->setApiKey($this->getApiKey());
        $gateway->setPassword($this->getPassword());
        $gateway->setTestMode($this->getTestMode());

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
        return '\\' . OmnipayGateway::class;
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
