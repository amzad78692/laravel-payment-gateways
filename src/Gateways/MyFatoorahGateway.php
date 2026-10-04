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
        'PAID' => PaymentStatus::Paid,
        'PENDING' => PaymentStatus::Pending,
        'FAILED' => PaymentStatus::Failed,
        'EXPIRED' => PaymentStatus::Cancelled,
        'CANCELED' => PaymentStatus::Cancelled,
        'CANCELLED' => PaymentStatus::Cancelled,
    ];

    public function charge(PaymentRequestData $request): PaymentResponseData
    {
        $postFields = array_filter([
            'InvoiceValue' => $request->amount,
            'CustomerName' => $request->customerName,
            'CustomerEmail' => $request->customerEmail,
            'DisplayCurrencyIso' => $request->currency,
            'Language' => strtoupper($this->language($request)),
            'CallBackUrl' => $this->callbackUrl($request),
            'ErrorUrl' => $this->errorUrl($request),
            'CustomerReference' => $request->reference,
            'UserDefinedField' => $request->metadata ? json_encode($request->metadata) : null,
        ]);

        try {
            $data = (new MyFatoorahPayment($this->sdkConfig()))->getInvoiceURL($postFields, $this->isTestMode(), $request->reference);
        } catch (Throwable $e) {
            throw new PaymentGatewayException("MyFatoorah charge failed: {$e->getMessage()}", previous: $e);
        }

        $data = $this->toArray($data);

        return new PaymentResponseData(
            status: PaymentStatus::Pending,
            reference: $request->reference,
            transactionId: $data['invoiceId'] ?? null,
            redirectUrl: $data['invoiceURL'] ?? null,
            payload: $data,
        );
    }

    public function handleCallback(Request $request): WebhookResult
    {
        $paymentId = $request->input('paymentId');

        if (empty($paymentId)) {
            throw new InvalidWebhookException('MyFatoorah callback is missing the paymentId.');
        }

        try {
            $data = (new MyFatoorahPaymentStatus($this->sdkConfig()))->getPaymentStatus($paymentId, 'PaymentId');
        } catch (Throwable $e) {
            throw new PaymentGatewayException("MyFatoorah status lookup failed: {$e->getMessage()}", previous: $e);
        }

        $data = $this->toArray($data);

        return new WebhookResult(
            status: $this->mapStatus(self::STATUS_MAP, $data['InvoiceStatus'] ?? ''),
            reference: (string) ($data['CustomerReference'] ?? ''),
            transactionId: $paymentId,
            amount: $data['InvoiceValue'] ?? null,
            payload: $data,
        );
    }

    /**
     * @return array{apiKey: string, vcCode: string, isTest: bool}
     */
    private function sdkConfig(): array
    {
        return [
            'apiKey' => $this->requireConfig('api_key'),
            'vcCode' => $this->config('vc_code', 'KWT'),
            'isTest' => $this->isTestMode(),
        ];
    }
}
