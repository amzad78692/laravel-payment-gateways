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
 * UPayments gateway, using the non-whitelabel hosted payment page (UInterfaceV2).
 *
 * Flow: charge() creates a charge and returns UPayments' `data.link` as the redirect; the
 * customer picks a payment method there. UPayments sends the customer back to returnUrl
 * (success) or cancelUrl (failure) with `?track_id=...` and also POSTs the result to
 * notificationUrl. In every case we take only the track id and re-fetch the status.
 *
 * Config keys (under payment-gateways.gateways.<name>):
 *  - api_key    (required) Non-whitelabel API token (Bearer).
 *  - test_mode  (default true) Use the sandbox API.
 *  - base_url   (optional) Override the API base URL.
 *
 * Docs: https://developers.upayments.com/reference/addcharge
 */
class UPaymentGateway extends AbstractGateway
{
    private const SANDBOX_URL = 'https://sandboxapi.upayments.com/api/v1';

    private const LIVE_URL = 'https://uapi.upayments.com/api/v1';

    private const STATUS_MAP = [
        'CAPTURED' => PaymentStatus::Paid,
        'PENDING' => PaymentStatus::Pending,
        'PROCESSING' => PaymentStatus::Pending,
        'CANCELED' => PaymentStatus::Cancelled,
        'CANCELLED' => PaymentStatus::Cancelled,
        'NOT CAPTURED' => PaymentStatus::Failed,
        'FAILED' => PaymentStatus::Failed,
        'ERROR' => PaymentStatus::Failed,
        'VOIDED' => PaymentStatus::Failed,
    ];

    public function charge(PaymentRequestData $request): PaymentResponseData
    {
        $response = $this->client()->post('charge', [
            'order' => [
                'id' => $request->reference,
                'reference' => $request->reference,
                'description' => $request->description ?: "Order {$request->reference}",
                'currency' => strtoupper($request->currency),
                'amount' => $request->amount,
            ],
            'reference' => ['id' => $request->reference],
            'language' => strtolower($this->language($request)) === 'ar' ? 'ar' : 'en',
            'customer' => array_filter([
                'name' => $request->customerName,
                'email' => $request->customerEmail,
            ]),
            'returnUrl' => $this->callbackUrl($request),
            'cancelUrl' => $this->errorUrl($request),
            'notificationUrl' => $this->callbackUrl($request),
        ]);

        $data = $response->json();

        if ($response->failed() || ($data['status'] ?? false) !== true || empty($data['data']['link'])) {
            throw new PaymentGatewayException('UPayment charge failed: '.($data['message'] ?? "HTTP {$response->status()}"));
        }

        // UPayments only returns the session id inside the payment link's query string.
        parse_str((string) parse_url($data['data']['link'], PHP_URL_QUERY), $query);

        return new PaymentResponseData(
            status: PaymentStatus::Pending,
            reference: $request->reference,
            transactionId: isset($query['session_id']) ? (string) $query['session_id'] : null,
            redirectUrl: $data['data']['link'],
            payload: $data,
        );
    }

    public function handleCallback(Request $request): WebhookResult
    {
        $trackId = (string) $request->input('track_id');

        if (! preg_match('/^[A-Za-z0-9]+$/', $trackId)) {
            throw new InvalidWebhookException('UPayment callback is missing a valid track_id.');
        }

        $response = $this->client()->get('get-payment-status/'.$trackId);
        $data = $response->json();

        if ($response->failed() || ($data['status'] ?? false) !== true) {
            throw new PaymentGatewayException('UPayment status lookup failed: '.($data['message'] ?? "HTTP {$response->status()}"));
        }

        $transaction = $data['data']['transaction'] ?? [];
        $amount = $transaction['total_price'] ?? null;

        return new WebhookResult(
            // A missing result means UPayments has not finalised the payment yet.
            status: $this->mapStatus(self::STATUS_MAP, (string) (($transaction['result'] ?? null) ?: 'PENDING')),
            reference: (string) ($transaction['merchant_requested_order_id'] ?? $transaction['reference'] ?? ''),
            transactionId: $trackId,
            amount: is_numeric($amount) ? (float) $amount : null,
            currency: $transaction['currency_type'] ?? null,
            payload: $data,
        );
    }

    /**
     * UPayments API client, authenticated with the API key as a bearer token.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl($this->config('base_url') ?: $this->environmentUrl(self::SANDBOX_URL, self::LIVE_URL))
            ->withToken($this->requireConfig('api_key'))
            ->acceptJson();
    }
}
