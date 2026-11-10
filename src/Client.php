<?php

declare(strict_types=1);

namespace CraftCms\CommerceEway;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A small client for the eWAY Rapid API (Direct Connection).
 *
 * It uses the same JSON endpoints the Omnipay eWAY driver used, which are still documented by eWAY.
 *
 * @see https://eway.io/api-v3/
 */
class Client
{
    public const string LIVE_URL = 'https://api.ewaypayments.com';

    public const string SANDBOX_URL = 'https://api.sandbox.ewaypayments.com';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $password,
        private readonly bool $testMode = false,
    ) {
    }

    /**
     * Sends a Direct Connection request: a payment (`ProcessPayment`, `TokenPayment`), a pre-auth (`Authorise`) or a
     * new token customer (`CreateTokenCustomer`).
     *
     * @see https://eway.io/api-v3/#direct-connection
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function directPayment(array $data): array
    {
        return $this->post('DirectPayment.json', $data);
    }

    /**
     * Captures a pre-auth.
     *
     * @see https://eway.io/api-v3/#capture-a-payment
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function capturePayment(array $data): array
    {
        return $this->post('CapturePayment', $data);
    }

    /**
     * Refunds a payment, in full or in part.
     *
     * @see https://eway.io/api-v3/#refunds
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function directRefund(array $data): array
    {
        return $this->post('DirectRefund.json', $data);
    }

    public function baseUrl(): string
    {
        return $this->testMode ? self::SANDBOX_URL : self::LIVE_URL;
    }

    /**
     * eWAY reports declines and validation errors in a JSON body, so any JSON object is returned as it is, whatever
     * the HTTP status. Anything else, such as the empty body of a 401 for a wrong API key or password, throws.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function post(string $path, array $data): array
    {
        $response = $this->request()->post($path, $data);
        $json = $response->json();

        if (!is_array($json)) {
            throw new RuntimeException(sprintf('eWAY returned an invalid response (HTTP %s).', $response->status()));
        }

        return $json;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withBasicAuth($this->apiKey, $this->password)
            ->acceptJson()
            ->asJson();
    }
}
