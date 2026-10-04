<?php

namespace Amzad\PaymentGateways\Gateways;

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Tap Payments gateway, using Tap's hosted payment page (Charges API v2).
 *
 * Flow: charge() creates a charge and returns Tap's `transaction.url` as the redirect.
 * Tap sends the customer back with `?tap_id=chg_...` and also POSTs the charge JSON as a
 * webhook. In both cases we take only the charge id and re-fetch the charge from Tap.
 *
 * Config keys (under payment-gateways.gateways.<name>):
 *  - secret_key   (required) sk_test_... or sk_live_...; Tap uses the same URL for both.
 *  - source       (default 'src_all') 'src_card' for cards only, 'src_kw.knet' for KNET.
 *  - merchant_id  (optional) For Tap accounts with several merchants.
 *
 * Docs: https://developers.tap.company/reference/create-a-charge
 */
class TapGateway extends AbstractGateway
{
    private const STATUS_MAP = [
        'CAPTURED' => PaymentStatus::Paid,
        'INITIATED' => PaymentStatus::Pending,
        'IN_PROGRESS' => PaymentStatus::Pending,
        'ABANDONED' => PaymentStatus::Cancelled,
        'CANCELLED' => PaymentStatus::Cancelled,
        'FAILED' => PaymentStatus::Failed,
        'DECLINED' => PaymentStatus::Failed,
        'RESTRICTED' => PaymentStatus::Failed,
        'TIMEDOUT' => PaymentStatus::Failed,
        'VOID' => PaymentStatus::Failed,
        'UNKNOWN' => PaymentStatus::Failed,
    ];

    public function charge(PaymentRequestData $request): PaymentResponseData
    {
        if (empty($request->customerName) || empty($request->customerEmail)) {
            throw new PaymentGatewayException('Tap requires a customer name and email.');
        }

        [$firstName, $lastName] = $this->splitName($request->customerName);
        $callbackUrl = $this->callbackUrl($request);
        $merchantId = $this->config('merchant_id');

        $response = $this->client()
            ->withHeaders(['lang_code' => $this->language($request)])
            ->post('/charges', array_filter([
                'amount' => $request->amount,
                'currency' => strtoupper($request->currency),
                'customer_initiated' => true,
                'threeDSecure' => true,
                'description' => $request->description,
                'reference' => ['transaction' => $request->reference, 'order' => $request->reference],
                'metadata' => $request->metadata,
                'customer' => array_filter(['first_name' => $firstName, 'last_name' => $lastName, 'email' => $request->customerEmail]),
                'merchant' => !empty($merchantId) ? ['id' => $merchantId] : null,
                'source' => ['id' => $this->config('source', 'src_all')],
                'post' => ['url' => $callbackUrl],
                'redirect' => ['url' => $callbackUrl],
            ]));

        $data = $response->json();

        if ($response->failed() || empty($data['id'])) {
            throw new PaymentGatewayException('Tap charge failed: '.($data['errors'][0]['description'] ?? "HTTP {$response->status()}"));
        }

        return new PaymentResponseData(
            status: PaymentStatus::Pending,
            reference: $request->reference,
            transactionId: $data['id'],
            redirectUrl: $data['transaction']['url'] ?? null,
            payload: $data,
        );
    }

    public function handleCallback(Request $request): WebhookResult
    {
        $chargeId = (string) ($request->input('tap_id'));

        if (!str_starts_with($chargeId, 'chg_')) {
            throw new InvalidWebhookException('Tap callback is missing a valid charge id (tap_id).');
        }

        $response = $this->client()->get('/charges/'.rawurlencode($chargeId));

        if ($response->failed()) {
            throw new PaymentGatewayException("Tap status lookup failed: HTTP {$response->status()}");
        }

        $data = $response->json();

        return new WebhookResult(
            status: $this->mapStatus(self::STATUS_MAP, $data['status'] ?? ''),
            reference: (string) ($data['reference']['transaction'] ?? ''),
            transactionId: $chargeId,
            amount: $data['amount'] ?? null,
            currency: $data['currency'] ?? null,
            payload: $data,
        );
    }

    /**
     * Tap API client, authenticated with the secret key as a bearer token.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.tap.company/v2')
            ->withToken($this->requireConfig('secret_key'))
            ->acceptJson();
    }
}
