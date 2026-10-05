<?php

namespace Amzad\PaymentGateways\Gateways;

use Amzad\PaymentGateways\Contracts\CustomizesCallbackResponse;
use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Amzad\PaymentGateways\Support\AesCrypt;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * KNET gateway, using the RAW (Tranportal) integration and KNET's hosted payment page.
 *
 * Flow: charge() encrypts the payment parameters with the terminal resource key and returns
 * the PaymentHTTP.htm URL as the redirect; no server-to-server call is made. For a processed
 * payment, KNET's server POSTs an encrypted `trandata` to responseURL and expects a plain-text
 * `REDIRECT=<receipt url>` answer, which it uses to send the customer on; if that fails, KNET
 * voids the payment. Only holders of the resource key can produce `trandata`, so one that
 * decrypts is trusted. Errors come back through the customer's browser as plain, unencrypted
 * `Error` / `ErrorText` parameters, and a customer who presses Cancel is posted to errorURL with
 * those fields empty; these are only ever failures / cancellations.
 *
 * KNET's track id must be alphanumeric, so the reference is sent with every other character
 * removed and the real reference travels in udf1.
 *
 * Config keys (under payment-gateways.gateways.<name>):
 *  - tranportal_id, tranportal_password, resource_key (16 chars)  (required)
 *  - test_mode  (default true) kpaytest.com.kw instead of kpay.com.kw.
 *
 * Docs: KNET Merchant Integration Manual (K-064) and the RAW PHP kit from the KNET merchant portal.
 */
class KnetGateway extends AbstractGateway implements CustomizesCallbackResponse
{
    private const STATUS_MAP = [
        'CAPTURED' => PaymentStatus::Paid,
        'NOT CAPTURED' => PaymentStatus::Failed,
        'HOST TIMEOUT' => PaymentStatus::Failed,
        'DENIED BY RISK' => PaymentStatus::Failed,
        'CANCELED' => PaymentStatus::Cancelled,
        'CANCELLED' => PaymentStatus::Cancelled,
    ];

    /** ISO 4217 numeric code for KWD, the only currency KNET accepts. */
    private const CURRENCY_CODE = '414';

    public function charge(PaymentRequestData $request): PaymentResponseData
    {
        if (strtoupper($request->currency) !== 'KWD') {
            throw new PaymentGatewayException("KNET only supports KWD, got [{$request->currency}].");
        }

        $trackId = substr((string) preg_replace('/[^A-Za-z0-9]/', '', $request->reference), 0, 40);

        if (empty($trackId)) {
            throw new PaymentGatewayException('KNET needs a reference containing letters or digits.');
        }

        $tranportalId = (string) $this->requireConfig('tranportal_id');
        $responseUrl = $this->callbackUrl($request);
        $errorUrl = $this->errorUrl($request);

        // KNET's Cancel button posts only empty Error fields to errorURL, so carry the reference in its query.
        // A URL that already has a query is left alone: a second '&' parameter would break KNET's raw string.
        if (! str_contains($errorUrl, '?')) {
            $errorUrl .= '?reference='.rawurlencode($request->reference);
        }

        $params = [
            'id' => $tranportalId,
            'password' => (string) $this->requireConfig('tranportal_password'),
            'action' => '1',
            'langid' => strtolower($this->language($request)) === 'ar' ? 'AR' : 'USA',
            'currencycode' => self::CURRENCY_CODE,
            'amt' => number_format($request->amount, 3, '.', ''),
            'responseURL' => $responseUrl,
            'errorURL' => $errorUrl,
            'trackid' => $trackId,
            'udf1' => $request->reference,
        ];

        foreach (['udf2', 'udf3', 'udf4', 'udf5'] as $udf) {
            if (isset($request->metadata[$udf])) {
                $params[$udf] = (string) $request->metadata[$udf];
            }
        }

        // KNET reads the decrypted parameters as a raw, unencoded query string, like the RAW kit builds it.
        foreach ($params as $key => $value) {
            if (str_contains($value, '&')) {
                throw new PaymentGatewayException("KNET parameter [{$key}] must not contain '&'.");
            }
        }

        $plain = implode('&', array_map(fn ($key, $value) => "{$key}={$value}", array_keys($params), $params));

        $redirectUrl = $this->baseUrl().'/kpg/PaymentHTTP.htm?'.http_build_query([
            'param' => 'paymentInit',
            'trandata' => $this->crypt()->encrypt($plain),
            'tranportalId' => $tranportalId,
            'responseURL' => $responseUrl,
            'errorURL' => $errorUrl,
        ]);

        return new PaymentResponseData(
            status: PaymentStatus::Pending,
            reference: $request->reference,
            redirectUrl: $redirectUrl,
            payload: ['trackid' => $trackId],
        );
    }

    public function handleCallback(Request $request): WebhookResult
    {
        $trandata = $this->trandata($request);

        if (!empty($trandata) && blank($request->input('Error')) && blank($request->input('ErrorText'))) {
            return $this->fromTrandata($trandata);
        }

        return $this->fromError($request);
    }

    /**
     * KNET's server notification (with `trandata`) must be answered with a single plain-text
     * `REDIRECT=<url>` line and no HTTP redirect; KNET then sends the customer's browser there.
     * Error and Cancel callbacks arrive through the browser itself, so they get a normal redirect.
     */
    public function callbackResponse(Request $request, WebhookResult $result, ?string $redirectUrl): ?Response
    {
        if (!empty($this->trandata($request))) {
            return response('REDIRECT='.($redirectUrl ?? url('/')), 200, ['Content-Type' => 'text/plain']);
        }

        return $redirectUrl !== null ? redirect()->to($redirectUrl) : null;
    }

    /**
     * The encrypted transaction response. KNET's server notification sends it as the bare request
     * body (hex, labelled `Content-Type: text/html`); browser posts send it as a `trandata` field.
     */
    private function trandata(Request $request): string
    {
        $body = trim($request->getContent());

        return (string) ($request->input('trandata') ?: (ctype_xdigit($body) ? $body : ''));
    }

    /**
     * A transaction response: the encrypted query string KNET posts to responseURL.
     *
     * @throws InvalidWebhookException When the data does not decrypt with our resource key.
     */
    private function fromTrandata(string $trandata): WebhookResult
    {
        try {
            parse_str($this->crypt()->decrypt($trandata), $data);
        } catch (PaymentGatewayException) {
            throw new InvalidWebhookException('KNET callback has an invalid trandata.');
        }

        /** @var array<string, string> $data */
        $reference = $data['udf1'] ?? $data['trackid'] ?? '';

        if (empty($reference)) {
            throw new InvalidWebhookException('KNET callback is missing the payment reference.');
        }

        $status = filled($data['Error'] ?? null) || filled($data['ErrorText'] ?? null)
            ? PaymentStatus::Failed
            : $this->mapStatus(self::STATUS_MAP, $data['result'] ?? '');

        return new WebhookResult(
            status: $status,
            reference: $reference,
            transactionId: filled($data['tranid'] ?? null) ? $data['tranid'] : ($data['paymentid'] ?? null),
            amount: isset($data['amt']) && is_numeric($data['amt']) ? (float) $data['amt'] : null,
            currency: 'KWD',
            payload: ['trandata' => $trandata] + $data,
        );
    }

    /**
     * An error redirect, or the customer pressing Cancel on KNET's page (which posts empty
     * Error fields to errorURL). Its parameters are plain text and anyone can forge them, so
     * the result is only ever a failure or cancellation, never a paid payment.
     */
    private function fromError(Request $request): WebhookResult
    {
        $reference = (string) ($request->query('reference') ?: $request->input('udf1') ?: $request->input('trackid'));

        if (empty($reference)) {
            throw new InvalidWebhookException('KNET callback is missing trandata or a payment reference.');
        }

        $failed = filled($request->input('Error')) || filled($request->input('ErrorText'));
        $paymentId = $request->input('paymentid');

        return new WebhookResult(
            status: $failed ? PaymentStatus::Failed : PaymentStatus::Cancelled,
            reference: $reference,
            transactionId: filled($paymentId) ? (string) $paymentId : null,
            currency: 'KWD',
            payload: $request->only(['reference', 'rspcode', 'paymentid', 'trackid', 'amt', 'Error', 'ErrorText', 'udf1', 'udf2', 'udf3', 'udf4', 'udf5']),
        );
    }

    private function baseUrl(): string
    {
        return $this->environmentUrl('https://www.kpaytest.com.kw', 'https://www.kpay.com.kw');
    }

    /**
     * KNET encrypts with AES-128-CBC, using the terminal resource key as both key and IV.
     */
    private function crypt(): AesCrypt
    {
        $key = (string) $this->requireConfig('resource_key');

        return new AesCrypt($key, $key, 'AES-128-CBC');
    }
}
