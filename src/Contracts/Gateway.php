<?php

namespace Amzad\PaymentGateways\Contracts;

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Illuminate\Http\Request;

interface Gateway
{
    /**
     * The configured name of this gateway (the key under `payment-gateways.gateways`).
     */
    public function name(): string;

    /**
     * Create a payment with the provider.
     *
     * @throws PaymentGatewayException
     */
    public function charge(PaymentRequestData $request): PaymentResponseData;

    /**
     * Handle a callback from the provider (customer redirect or webhook) and
     * translate it into a normalised result. Implementations must verify the
     * authenticity of the callback (signature, or a server-side status lookup)
     * before trusting it.
     *
     * @throws InvalidWebhookException
     * @throws PaymentGatewayException
     */
    public function handleCallback(Request $request): WebhookResult;
}
