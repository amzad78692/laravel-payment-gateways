<?php

namespace Amzad\PaymentGateways\Events;

use Amzad\PaymentGateways\Data\WebhookResult;

/**
 * Fired when a gateway callback reports a failed or cancelled payment.
 */
final readonly class PaymentFailed
{
    public function __construct(
        public string $gateway,
        public WebhookResult $result,
    ) {}
}
