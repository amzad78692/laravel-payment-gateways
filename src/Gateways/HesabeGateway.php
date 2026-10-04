<?php

namespace Amzad\PaymentGateways\Gateways;

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Amzad\PaymentGateways\Support\AesCrypt;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Hesabe gateway, using the Checkout API and Hesabe's hosted payment page.
 *
 * Flow: charge() sends the encrypted payment data to /checkout and returns
 * {base}/payment?data=<token> as the redirect. Hesabe sends the customer back with an
 * encrypted `?data=` and also POSTs an unsigned JSON webhook with a `token`. In both
 * cases we take only the payment token and verify it with the Transaction Enquiry API.
 *
 * Config keys (under payment-gateways.gateways.<name>):
 *  - merchant_code, access_code, secret_key (32 chars), iv_key (16 chars)  (required)
 *  - test_mode     (default true) sandbox.hesabe.com instead of api.hesabe.com.
 *  - payment_type  (default 0) 0 lets the customer choose on Hesabe's page; 1 KNET, 2 MPGS cards, ...
 *
 * Docs: https://developer.hesabe.com/docs/guides/hesabe-indirect-integration/
 */
class HesabeGateway extends AbstractGateway
{
    private const STATUS_MAP = [
        'CAPTURED' => PaymentStatus::Paid,
        'PENDING' => PaymentStatus::Pending,
        'NOT CAPTURED' => PaymentStatus::Failed,
        'CANCELED' => PaymentStatus::Cancelled,
    ];

    public function charge(PaymentRequestData $request): PaymentResponseData
    {
        $callbackUrl = $this->callbackUrl($request);

        $payload = array_filter([
            'merchantCode' => $this->requireConfig('merchant_code'),
            'amount' => $request->amount,
            'currency' => strtoupper($request->currency),
            'paymentType' => (int) $this->config('payment_type', 0),
            'responseUrl' => $callbackUrl,
            'failureUrl' => $this->errorUrl($request),
            'webhookUrl' => $callbackUrl,
            'version' => '2.0',
            'orderReferenceNumber' => $request->reference,
            'name' => $request->customerName,
            'email' => $request->customerEmail,
        ], filled(...));

        $response = $this->client()->asForm()->post('/checkout', ['data' => $this->crypt()->encryptJson($payload)]);
        $data = $this->decode($response->body());

        if ($response->failed() || empty($data['response']['data'])) {
            throw new PaymentGatewayException('Hesabe checkout failed: '.($data['message'] ?? "HTTP {$response->status()}"));
        }

        return new PaymentResponseData(
            status: PaymentStatus::Pending,
            reference: $request->reference,
            redirectUrl: $this->baseUrl().'/payment?data='.$data['response']['data'],
            payload: $data,
        );
    }

    public function handleCallback(Request $request): WebhookResult
    {
        if (empty($request->input('data'))) {
            throw new InvalidWebhookException('Hesabe callback is missing a valid payment token.');
        }

        $response = $this->decode($request->input('data'));

        if (empty($response['response'])) {
            throw new PaymentGatewayException("Hesabe transaction decode failed.");
        }

        $transaction = $response['response'] ?? [];

        return new WebhookResult(
            status: $this->mapStatus(self::STATUS_MAP, $transaction['resultCode'] ?? ''),
            reference: (string) ($transaction['orderReferenceNumber'] ?? ''),
            transactionId: $transaction['transactionId'],
            amount: (float) ($transaction['amount'] ?? 0),
            payload: $response,
        );
    }

    /**
     * Hesabe API client, authenticated with the merchant access code header.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['accessCode' => $this->requireConfig('access_code')])
            ->acceptJson();
    }

    private function baseUrl(): string
    {
        return $this->environmentUrl('https://sandbox.hesabe.com', 'https://api.hesabe.com');
    }

    /**
     * Hesabe bodies are either encrypted hex strings or plain JSON.
     *
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        return ctype_xdigit($body) ? $this->crypt()->decryptJson($body) : (array) json_decode($body, true);
    }

    private function crypt(): AesCrypt
    {
        return new AesCrypt($this->requireConfig('secret_key'), $this->requireConfig('iv_key'));
    }
}
