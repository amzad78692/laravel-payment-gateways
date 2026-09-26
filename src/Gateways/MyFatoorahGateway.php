<?php

namespace Amzad\PaymentGateways\Gateways;

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Illuminate\Http\Request;
use MyFatoorah\Library\API\Payment\MyFatoorahPayment;
use MyFatoorah\Library\API\Payment\MyFatoorahPaymentStatus;
use Throwable;

/**
 * MyFatoorah gateway, built on the official `myfatoorah/library` SDK
 * (`getInvoiceURL()` for charging, `getPaymentStatus()` for callback verification).
 *
 * Config keys (under payment-gateways.gateways.<name>):
 *  - api_key              (required) Token from the MyFatoorah portal.
 *  - vc_code               (default 'KWT') Vendor country code, e.g. 'KWT', 'SAU', 'ARE'.
 *  - test_mode             (bool, default true) Use the MyFatoorah sandbox.
 *  - mobile_country_code   (default '+965') Only sent when the request has a phone number.
 *
 * Docs: https://docs.myfatoorah.com/docs/php-library
 */
class MyFatoorahGateway extends AbstractGateway
{
    /**
     * Statuses MyFatoorah returns from GetPaymentStatus, normalised to ours.
     *
     * @var array<string, PaymentStatus>
     */
    private const STATUS_MAP = [
        'paid' => PaymentStatus::Paid,
        'pending' => PaymentStatus::Pending,
        'failed' => PaymentStatus::Failed,
        'expired' => PaymentStatus::Cancelled,
        'canceled' => PaymentStatus::Cancelled,
        'cancelled' => PaymentStatus::Cancelled,
    ];

    public function charge(PaymentRequestData $request): PaymentResponseData
    {
        $isTest = (bool) $this->config('test_mode', true);

        $postFields = array_filter([
            'InvoiceValue' => $request->amount,
            'CustomerName' => $request->customerName,
            'CustomerEmail' => $request->customerEmail,
            'CustomerMobile' => $request->customerPhone,
            'MobileCountryCode' => $request->customerPhone !== null
                ? (string) $this->config('mobile_country_code', '+965')
                : null,
            'DisplayCurrencyIso' => $request->currency,
            'Language' => strtoupper($this->language($request)),
            'CallBackUrl' => $this->callbackUrl($request),
            'ErrorUrl' => $request->errorUrl ?? $this->callbackUrl($request),
            'CustomerReference' => $request->reference,
            'UserDefinedField' => $request->metadata === [] ? null : json_encode($request->metadata),
        ], fn ($value) => $value !== null);

        try {
            $data = (new MyFatoorahPayment($this->sdkConfig()))->getInvoiceURL($postFields, $isTest, $request->reference);
        } catch (Throwable $e) {
            throw new PaymentGatewayException("MyFatoorah charge failed: {$e->getMessage()}", previous: $e);
        }

        $data = $this->toArray($data);

        return new PaymentResponseData(
            status: PaymentStatus::Pending,
            reference: $request->reference,
            transactionId: isset($data['invoiceId']) ? (string) $data['invoiceId'] : null,
            redirectUrl: $data['invoiceURL'] ?? null,
            payload: $data,
        );
    }

    public function handleCallback(Request $request): WebhookResult
    {
        $paymentId = $request->input('paymentId');

        if (! is_string($paymentId) || $paymentId === '') {
            throw new InvalidWebhookException('MyFatoorah callback is missing the paymentId.');
        }

        try {
            $data = (new MyFatoorahPaymentStatus($this->sdkConfig()))->getPaymentStatus($paymentId, 'PaymentId');
        } catch (Throwable $e) {
            throw new PaymentGatewayException("MyFatoorah status lookup failed: {$e->getMessage()}", previous: $e);
        }

        $data = $this->toArray($data);

        $reference = $data['CustomerReference'] ?? null;

        if (! is_string($reference) || $reference === '') {
            throw new InvalidWebhookException('MyFatoorah GetPaymentStatus response has no CustomerReference.');
        }

        $status = self::STATUS_MAP[strtolower((string) ($data['InvoiceStatus'] ?? ''))]
            ?? throw new InvalidWebhookException("MyFatoorah returned an unrecognised InvoiceStatus [{$data['InvoiceStatus']}].");

        return new WebhookResult(
            status: $status,
            reference: $reference,
            transactionId: $paymentId,
            amount: isset($data['InvoiceValue']) ? (float) $data['InvoiceValue'] : null,
            payload: $data,
        );
    }

    /**
     * @return array{apiKey: string, vcCode: string, isTest: bool}
     */
    private function sdkConfig(): array
    {
        return [
            'apiKey' => (string) $this->requireConfig('api_key'),
            'vcCode' => (string) $this->config('vc_code', 'KWT'),
            'isTest' => (bool) $this->config('test_mode', true),
        ];
    }

    /**
     * The SDK returns a stdClass (or array, depending on version); normalise either to an array.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) json_encode($data), true) ?? [];

        return $decoded;
    }
}
