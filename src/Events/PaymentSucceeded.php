<?php

namespace Amzad\PaymentGateways\Events;

use Amzad\PaymentGateways\Data\WebhookResult;

/**
 * Fired when a gateway callback confirms a payment.
 *
 * Gateways may notify you more than once (customer redirect *and* webhook),
 * so listeners must be idempotent.
 */
final readonly class PaymentSucceeded
{
    public function __construct(
        public string $gateway,
        public WebhookResult $result,
    ) {}
}
