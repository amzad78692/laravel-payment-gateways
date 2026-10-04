<?php

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Events\PaymentFailed;
use Amzad\PaymentGateways\Events\PaymentSucceeded;
use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Amzad\PaymentGateways\Facades\PaymentGateways;
use Amzad\PaymentGateways\Gateways\HesabeGateway;
use Amzad\PaymentGateways\Support\AesCrypt;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Hesabe's public sandbox credentials: https://developer.hesabe.com/docs/guides/pre-requisites/
const HESABE_SANDBOX_CONFIG = [
    'merchant_code' => '842217',
    'access_code' => 'c333729b-d060-4b74-a49d-7686a8353481',
    'secret_key' => 'PkW64zMe5NVdrlPVNnjo2Jy9nOb7v1Xg',
    'iv_key' => '5NVdrlPVNnjo2Jy9',
];

const HESABE_TOKEN = '84221716951937729753667644493';

function hesabeGateway(array $config = []): HesabeGateway
{
    return new HesabeGateway('hesabe', $config + HESABE_SANDBOX_CONFIG);
}

function hesabeCrypt(): AesCrypt
{
    return new AesCrypt(HESABE_SANDBOX_CONFIG['secret_key'], HESABE_SANDBOX_CONFIG['iv_key']);
}

function hesabeRequest(array $overrides = []): PaymentRequestData
{
    return new PaymentRequestData(...$overrides + [
        'amount' => 10.5,
        'reference' => 'order-1',
        'customerName' => 'Amzad Khan',
        'customerEmail' => 'amzad@example.com',
        'callbackUrl' => 'https://shop.test/payment-gateways/hesabe/callback',
    ]);
}

/**
 * The decrypted request payload the gateway sent to /checkout.
 */
function hesabeSentPayload(HttpRequest $request): array
{
    return hesabeCrypt()->decryptJson($request['data']);
}

/**
 * An encrypted /checkout response body, as Hesabe returns it.
 */
function hesabeCheckoutResponse(array $overrides = []): string
{
    return hesabeCrypt()->encryptJson(array_replace_recursive([
        'status' => true,
        'code' => 200,
        'message' => 'Authentication success!',
        'response' => ['data' => 'checkout-token-123'],
    ], $overrides));
}

function hesabeTransaction(array $overrides = []): array
{
    return [
        'status' => true,
        'message' => 'Transaction found',
        'data' => $overrides + [
            'token' => HESABE_TOKEN,
            'amount' => '10.500',
            'reference_number' => 'order-1',
            'status' => 'SUCCESSFUL',
            'payment_type' => 'KNET',
            'datetime' => '2026-09-26 10:09:36',
        ],
    ];
}

/**
 * The encrypted ?data= value Hesabe appends to responseUrl / failureUrl.
 */
function hesabeRedirectData(array $data = []): string
{
    return hesabeCrypt()->encryptJson([
        'status' => true,
        'code' => 1,
        'message' => 'Transaction Success',
        'response' => $data + [
            'resultCode' => 'CAPTURED',
            'amount' => '10.500',
            'currency' => 'KWD',
            'paymentToken' => HESABE_TOKEN,
            'paymentId' => '7904325837106489804805',
            'orderReferenceNumber' => 'order-1',
        ],
    ]);
}

it('encrypts exactly like the samples in the Hesabe docs', function () {
    // Sample from https://developer.hesabe.com/docs/api/post-refund-request/
    $plain = '{"merchantCode":"842217","refundAmount":"10.000","refundMethod":"1","token":"84221717575602869376365549977"}';
    $cipher = '0e7898bd7464d0c402fe8a949d9cbf9ba5d8ada481ab9f66e4555139335643f4e1904f125c6dac14503369ecf3f06cde1212ac5fdaef94ab225673f757a5a84ee182f9443ce727161e9720f0cb138e5d51a6a728003af542dec35e6a9d8eedae76430e633696b35aca4267c9a2cbb0e3';

    expect(hesabeCrypt()->encrypt($plain))->toBe($cipher)
        ->and(hesabeCrypt()->decrypt($cipher))->toBe($plain)
        ->and(hesabeCrypt()->decryptJson('0e7898bd7464d0c402fe8a949d9cbf9b826ccb9b83b56fa003373275f1947a65'))
        ->toBe(['merchantCode' => '842217']);
});

it('decrypts payloads padded to 32-byte blocks by the Hesabe kits', function () {
    $plain = '{"a":1}';
    $pad = 32 - strlen($plain);
    $cipher = bin2hex(openssl_encrypt(
        $plain.str_repeat(chr($pad), $pad),
        'AES-256-CBC',
        HESABE_SANDBOX_CONFIG['secret_key'],
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        HESABE_SANDBOX_CONFIG['iv_key'],
    ));

    expect(hesabeCrypt()->decrypt($cipher))->toBe($plain);
});

it('rejects keys of the wrong length', function () {
    new AesCrypt('too-short', HESABE_SANDBOX_CONFIG['iv_key']);
})->throws(InvalidGatewayConfigurationException::class, 'Encryption key must be 32 characters');

it('creates a checkout and returns the hosted payment page url', function () {
    Http::fake(['sandbox.hesabe.com/checkout' => Http::response(hesabeCheckoutResponse())]);

    $response = hesabeGateway()->charge(hesabeRequest());

    expect($response->status)->toBe(PaymentStatus::Pending)
        ->and($response->requiresRedirect())->toBeTrue()
        ->and($response->redirectUrl)->toBe('https://sandbox.hesabe.com/payment?data=checkout-token-123')
        ->and($response->reference)->toBe('order-1');

    Http::assertSent(function (HttpRequest $request) {
        $payload = hesabeSentPayload($request);

        return $request->method() === 'POST'
            && $request->url() === 'https://sandbox.hesabe.com/checkout'
            && $request->isForm()
            && $request->hasHeader('accessCode', HESABE_SANDBOX_CONFIG['access_code'])
            && $payload === [
                'merchantCode' => '842217',
                'amount' => 10.5,
                'currency' => 'KWD',
                'paymentType' => 0,
                'responseUrl' => 'https://shop.test/payment-gateways/hesabe/callback',
                'failureUrl' => 'https://shop.test/payment-gateways/hesabe/callback',
                'webhookUrl' => 'https://shop.test/payment-gateways/hesabe/callback',
                'version' => '2.0',
                'orderReferenceNumber' => 'order-1',
                'name' => 'Amzad Khan',
                'email' => 'amzad@example.com',
            ];
    });
});

it('uses the live url, payment type and error url', function () {
    Http::fake(['api.hesabe.com/checkout' => Http::response(hesabeCheckoutResponse())]);

    $response = hesabeGateway(['test_mode' => false, 'payment_type' => 1])->charge(hesabeRequest([
        'errorUrl' => 'https://shop.test/payment-failed',
    ]));

    expect($response->redirectUrl)->toBe('https://api.hesabe.com/payment?data=checkout-token-123');

    Http::assertSent(function (HttpRequest $request) {
        $payload = hesabeSentPayload($request);

        return $request->url() === 'https://api.hesabe.com/checkout'
            && $payload['paymentType'] === 1
            && $payload['failureUrl'] === 'https://shop.test/payment-failed';
    });
});

it('requires the credentials', function (string $key) {
    $config = HESABE_SANDBOX_CONFIG;
    unset($config[$key]);

    (new HesabeGateway('hesabe', $config))->charge(hesabeRequest());
})->with(['merchant_code', 'access_code', 'secret_key', 'iv_key'])
    ->throws(InvalidGatewayConfigurationException::class);

it('surfaces a checkout rejected by Hesabe', function () {
    Http::fake(['sandbox.hesabe.com/*' => Http::response(hesabeCrypt()->encryptJson([
        'status' => false,
        'code' => 501,
        'message' => 'Invalid Merchant',
    ]))]);

    hesabeGateway()->charge(hesabeRequest());
})->throws(PaymentGatewayException::class, 'Hesabe checkout failed: Invalid Merchant');

it('surfaces HTTP errors with their message', function () {
    Http::fake(['sandbox.hesabe.com/*' => Http::response(['message' => 'Invalid Access Code'], 401)]);

    hesabeGateway()->charge(hesabeRequest());
})->throws(PaymentGatewayException::class, 'Hesabe checkout failed: Invalid Access Code');

it('verifies a customer redirect with a transaction enquiry', function (string $hesabeStatus, PaymentStatus $expected) {
    Http::fake(['sandbox.hesabe.com/api/transaction/*' => Http::response(hesabeTransaction(['status' => $hesabeStatus]))]);

    $result = hesabeGateway()->handleCallback(Request::create('/callback', 'GET', ['data' => hesabeRedirectData()]));

    expect($result->status)->toBe($expected)
        ->and($result->reference)->toBe('order-1')
        ->and($result->transactionId)->toBe(HESABE_TOKEN)
        ->and($result->amount)->toBe(10.5);

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET'
        && $request->url() === 'https://sandbox.hesabe.com/api/transaction/'.HESABE_TOKEN
        && $request->hasHeader('accessCode', HESABE_SANDBOX_CONFIG['access_code']));
})->with([
    ['SUCCESSFUL', PaymentStatus::Paid],
    ['PENDING', PaymentStatus::Pending],
    ['FAILED', PaymentStatus::Failed],
    ['CANCELLED', PaymentStatus::Cancelled],
]);

it('reads an encrypted transaction enquiry response', function () {
    Http::fake(['sandbox.hesabe.com/*' => Http::response(hesabeCrypt()->encryptJson(hesabeTransaction()))]);

    $result = hesabeGateway()->handleCallback(Request::create('/callback', 'POST', ['token' => HESABE_TOKEN]));

    expect($result->status)->toBe(PaymentStatus::Paid);
});

it('trusts only the enquiry for webhooks, not the posted body', function () {
    Http::fake(['sandbox.hesabe.com/*' => Http::response(hesabeTransaction(['status' => 'FAILED']))]);

    // A forged webhook claiming success. Hesabe webhooks are not signed.
    $result = hesabeGateway()->handleCallback(Request::create('/callback', 'POST', [
        'token' => HESABE_TOKEN,
        'reference_number' => 'order-1',
        'status' => 'SUCCESSFUL',
    ]));

    expect($result->status)->toBe(PaymentStatus::Failed);
});

it('rejects a callback without a token', function () {
    hesabeGateway()->handleCallback(Request::create('/callback', 'POST'));
})->throws(InvalidWebhookException::class);

it('rejects a token that is not numeric', function () {
    hesabeGateway()->handleCallback(Request::create('/callback', 'POST', ['token' => '../checkout']));
})->throws(InvalidWebhookException::class, 'valid payment token');

it('rejects a token Hesabe does not know', function () {
    Http::fake(['sandbox.hesabe.com/*' => Http::response(['status' => false, 'message' => 'Transaction not found'])]);

    hesabeGateway()->handleCallback(Request::create('/callback', 'POST', ['token' => HESABE_TOKEN]));
})->throws(InvalidWebhookException::class, 'unsupported payment status');

it('rejects an unsupported transaction status', function () {
    Http::fake(['sandbox.hesabe.com/*' => Http::response(hesabeTransaction(['status' => 'REFUNDED']))]);

    hesabeGateway()->handleCallback(Request::create('/callback', 'POST', ['token' => HESABE_TOKEN]));
})->throws(InvalidWebhookException::class, 'unsupported payment status');

it('fires events through the package callback route', function () {
    config()->set('payment-gateways.gateways.hesabe', ['driver' => 'hesabe'] + HESABE_SANDBOX_CONFIG);
    Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
    Http::fake(['sandbox.hesabe.com/*' => Http::response(hesabeTransaction())]);

    $this->postJson('/payment-gateways/hesabe/callback', ['token' => HESABE_TOKEN])
        ->assertOk()
        ->assertJson(['reference' => 'order-1', 'status' => 'paid']);

    Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->gateway === 'hesabe' && $e->result->transactionId === HESABE_TOKEN);
});

it('is registered as the hesabe driver', function () {
    config()->set('payment-gateways.gateways.hesabe', ['driver' => 'hesabe'] + HESABE_SANDBOX_CONFIG);

    expect(PaymentGateways::gateway('hesabe'))->toBeInstanceOf(HesabeGateway::class);
});

it('creates a real checkout in the Hesabe sandbox', function () {
    $response = hesabeGateway()->charge(hesabeRequest(['amount' => 1.0, 'reference' => 'test-'.uniqid()]));

    expect($response->requiresRedirect())->toBeTrue()
        ->and($response->redirectUrl)->toStartWith('https://sandbox.hesabe.com/payment?data=');
})->group('sandbox');
