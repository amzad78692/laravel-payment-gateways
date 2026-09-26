<?php

namespace Amzad\PaymentGateways\Gateways;

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;

/**
 * Tap Payments gateway, using Tap's hosted payment page (Charges API v2).
 *
 * Flow: charge() creates a charge and returns Tap's `transaction.url` as the redirect.
 * Tap sends the customer back to our callback with `?tap_id=chg_...` (GET) and also
 * POSTs the charge JSON to the same URL as a webhook. In both cases we only take the
 * charge id from the request and re-fetch the charge from Tap before trusting it.
 *
 * Test and live mode are selected by the key itself (sk_test_... / sk_live_...);
 * Tap uses the same base URL for both.
 *
 * Config keys (under payment-gateways.gateways.<name>):
 *  - secret_key          (required) Secret API key from the Tap dashboard.
 *  - source              (default 'src_all') Payment source: 'src_all' shows every enabled
 *                        method, 'src_card' cards only, 'src_kw.knet' goes straight to KNET.
 *  - phone_country_code  (default '965') Used when the customer phone has no country code.
 *  - merchant_id         (optional) Tap merchant id, for accounts with several merchants.
 *  - base_url            (default 'https://api.tap.company/v2')
 *  - timeout             (default 30) HTTP timeout in seconds.
 *
 * Docs: https://developers.tap.company/reference/create-a-charge
 */
class TapGateway extends AbstractGateway
{
    public const DEFAULT_BASE_URL = 'https://api.tap.company/v2';

    /**
     * Tap charge statuses, normalised to ours. AUTHORIZED (authorize/capture flow)
     * is intentionally absent: this driver only creates immediate charges.
     *
     * @var array<string, PaymentStatus>
     */
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
        $currency = strtoupper($request->currency);
        $callbackUrl = $this->callbackUrl($request);

        $merchantId = $this->config('merchant_id');

        $payload = array_filter([
            'amount' => $this->roundAmount($request->amount, $currency),
            'currency' => $currency,
            'customer_initiated' => true,
            'threeDSecure' => true,
            'description' => $request->description,
            'reference' => [
                'transaction' => $request->reference,
                'order' => $request->reference,
            ],
            'metadata' => $request->metadata === [] ? null : $request->metadata,
            'customer' => $this->customer($request),
            'merchant' => is_string($merchantId) && $merchantId !== '' ? ['id' => $merchantId] : null,
            'source' => ['id' => (string) $this->config('source', 'src_all')],
            'post' => ['url' => $callbackUrl],
            'redirect' => ['url' => $callbackUrl],
        ], fn ($value) => $value !== null);

        $data = $this->responseJson($this->send(
            fn (PendingRequest $http) => $http
                ->withHeaders(['lang_code' => $this->language($request)])
                ->post('/charges', $payload),
            'charge',
        ));

        $chargeId = $data['id'] ?? null;

        if (! is_string($chargeId) || $chargeId === '') {
            throw new PaymentGatewayException('Tap charge response has no charge id.');
        }

        $status = self::STATUS_MAP[strtoupper((string) ($data['status'] ?? ''))] ?? PaymentStatus::Pending;
        $redirectUrl = $data['transaction']['url'] ?? null;

        return new PaymentResponseData(
            status: $status,
            reference: $request->reference,
            transactionId: $chargeId,
            redirectUrl: is_string($redirectUrl) && $redirectUrl !== '' ? $redirectUrl : null,
            payload: $data,
        );
    }

    public function handleCallback(Request $request): WebhookResult
    {
        // Customer redirect: ?tap_id=chg_...  Webhook: charge JSON with an "id" field.
        $chargeId = $request->input('tap_id') ?? $request->input('id');

        if (! is_string($chargeId) || ! str_starts_with($chargeId, 'chg_')) {
            throw new InvalidWebhookException('Tap callback is missing a valid charge id (tap_id).');
        }

        $data = $this->responseJson($this->send(
            fn (PendingRequest $http) => $http->get('/charges/'.rawurlencode($chargeId)),
            'status lookup',
        ));

        $reference = $data['reference']['transaction'] ?? $data['reference']['order'] ?? null;

        if (! is_string($reference) || $reference === '') {
            throw new InvalidWebhookException("Tap charge [{$chargeId}] has no reference.");
        }

        $tapStatus = strtoupper((string) ($data['status'] ?? ''));
        $status = self::STATUS_MAP[$tapStatus]
            ?? throw new InvalidWebhookException("Tap returned an unsupported charge status [{$tapStatus}].");

        return new WebhookResult(
            status: $status,
            reference: $reference,
            transactionId: $chargeId,
            amount: isset($data['amount']) ? (float) $data['amount'] : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            payload: $data,
        );
    }

    /**
     * Tap API client: base URL plus the secret key as a bearer token.
     */
    protected function http(): PendingRequest
    {
        return parent::http()
            ->baseUrl(rtrim((string) $this->config('base_url', self::DEFAULT_BASE_URL), '/'))
            ->withToken((string) $this->requireConfig('secret_key'));
    }

    /**
     * Tap errors look like {"errors": [{"code": "1117", "description": "..."}]}.
     */
    protected function errorMessage(Response $response): string
    {
        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            return collect($errors)
                ->map(fn ($error) => is_array($error)
                    ? trim(($error['code'] ?? '').' '.($error['description'] ?? $error['message'] ?? ''))
                    : (string) $error)
                ->filter()
                ->implode('; ');
        }

        return parent::errorMessage($response);
    }

    /**
     * Tap requires a first name plus an email or phone number.
     *
     * @return array<string, mixed>
     */
    private function customer(PaymentRequestData $request): array
    {
        if (blank($request->customerName)) {
            throw new PaymentGatewayException('Tap requires a customer name (customerName).');
        }

        if (blank($request->customerEmail) && blank($request->customerPhone)) {
            throw new PaymentGatewayException('Tap requires a customer email or phone number.');
        }

        [$firstName, $lastName] = $this->splitName((string) $request->customerName);

        return array_filter([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => blank($request->customerEmail) ? null : $request->customerEmail,
            'phone' => blank($request->customerPhone)
                ? null
                : $this->splitPhone((string) $request->customerPhone, (string) $this->config('phone_country_code', '965')),
        ], fn ($value) => $value !== null);
    }
}
