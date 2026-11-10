<?php

declare(strict_types=1);

namespace CraftCms\CommerceEway\Events;

use CraftCms\Commerce\Payment\Data\Transaction;
use CraftCms\Commerce\Payment\Forms\BasePaymentForm;

/**
 * Dispatched before each request is sent to eWAY. Listeners may modify `$request`, which is sent as the JSON body of
 * the eWAY Rapid API request.
 *
 * `$type` is `purchase`, `authorize`, `capture` or `refund` (the Commerce transaction type), or `paymentSource` when a
 * token customer is created for a payment source. `$transaction` is `null` for payment sources.
 *
 * @see https://eway.io/api-v3/#direct-connection
 */
class PaymentRequestBuilding
{
    /**
     * @param array<string, mixed> $request
     */
    public function __construct(
        public readonly string $type,
        public readonly ?Transaction $transaction,
        public readonly ?BasePaymentForm $form,
        public array $request,
    ) {
    }
}
