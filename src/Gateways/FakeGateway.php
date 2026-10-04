<?php

namespace Amzad\PaymentGateways\Gateways;

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Illuminate\Http\Request;

/**
 * In-memory gateway for local development and automated tests. Makes no HTTP calls.
 *
 * Callback input: `reference` (required) and `status` (paid|failed|cancelled|pending, default paid).
 */
class FakeGateway extends AbstractGateway
{
    public function charge(PaymentRequestData $request): PaymentResponseData
    {
        return new PaymentResponseData(
            status: PaymentStatus::Pending,
            reference: $request->reference,
            transactionId: 'fake_'.$request->reference,
            redirectUrl: 'https://fake-gateway.test/pay/'.$request->reference,
            payload: ['amount' => $request->amount, 'currency' => $request->currency],
        );
    }

    public function handleCallback(Request $request): WebhookResult
    {
        $reference = $request->input('reference');

        if (empty($reference)) {
            throw new InvalidWebhookException('Callback is missing the payment reference.');
        }

        $status = PaymentStatus::tryFrom((string) $request->input('status', 'paid'))
            ?? throw new InvalidWebhookException('Callback contains an unknown payment status.');

        return new WebhookResult(
            status: $status,
            reference: $reference,
            transactionId: 'fake_'.$reference,
            payload: $request->all(),
        );
    }
}
