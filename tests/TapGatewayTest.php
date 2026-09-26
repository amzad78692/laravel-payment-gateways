<?php

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Events\PaymentFailed;
use Amzad\PaymentGateways\Events\PaymentSucceeded;
use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Amzad\PaymentGateways\Facades\PaymentGateways;
use Amzad\PaymentGateways\Gateways\TapGateway;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Tap's public test credentials: https://developers.tap.company/reference/testing-keys
const TAP_PUBLIC_TEST_SECRET_KEY = 'sk_test_XKokBfNWv6FIYuTMg5sLPjhJ';

function tapGateway(array $config = []): TapGateway
{
    return new TapGateway('tap', $config + ['secret_key' => TAP_PUBLIC_TEST_SECRET_KEY]);
}

function tapRequest(array $overrides = []): PaymentRequestData
{
    return new PaymentRequestData(...$overrides + [
        'amount' => 10.5,
        'reference' => 'order-1',
        'customerName' => 'Amzad Khan Test',
        'customerEmail' => 'amzad@example.com',
        'callbackUrl' => 'https://shop.test/payment-gateways/tap/callback',
    ]);
}

function tapCharge(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'chg_TS01A1234567890',
        'status' => 'INITIATED',
        'amount' => 10.5,
        'currency' => 'KWD',
        'reference' => ['transaction' => 'order-1', 'order' => 'order-1'],
        'transaction' => ['url' => 'https://checkout.payments.tap.company/?mode=page&token=abc'],
    ], $overrides);
}

it('creates a charge and returns the hosted payment page url', function () {
    Http::fake(['api.tap.company/v2/charges' => Http::response(tapCharge())]);

    $response = tapGateway()->charge(tapRequest());

    expect($response->status)->toBe(PaymentStatus::Pending)
        ->and($response->requiresRedirect())->toBeTrue()
        ->and($response->transactionId)->toBe('chg_TS01A1234567890')
        ->and($response->redirectUrl)->toBe('https://checkout.payments.tap.company/?mode=page&token=abc')
        ->and($response->reference)->toBe('order-1');

    Http::assertSent(function (HttpRequest $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.tap.company/v2/charges'
            && $request->hasHeader('Authorization', 'Bearer '.TAP_PUBLIC_TEST_SECRET_KEY)
            && $request->hasHeader('lang_code', 'en')
            && $request['amount'] === 10.5
            && $request['currency'] === 'KWD'
            && $request['source'] === ['id' => 'src_all']
            && $request['reference'] === ['transaction' => 'order-1', 'order' => 'order-1']
            && $request['redirect'] === ['url' => 'https://shop.test/payment-gateways/tap/callback']
            && $request['post'] === ['url' => 'https://shop.test/payment-gateways/tap/callback']
            && $request['customer'] === [
                'first_name' => 'Amzad',
                'last_name' => 'Khan Test',
                'email' => 'amzad@example.com',
            ];
    });
});

it('uses the configured source, merchant and arabic locale', function () {
    Http::fake(['api.tap.company/*' => Http::response(tapCharge())]);

    tapGateway(['source' => 'src_kw.knet', 'merchant_id' => '599424'])
        ->charge(tapRequest(['locale' => 'ar']));

    Http::assertSent(fn (HttpRequest $request) => $request['source'] === ['id' => 'src_kw.knet']
        && $request['merchant'] === ['id' => '599424']
        && $request->hasHeader('lang_code', 'ar'));
});

it('rounds the amount to the currency decimals', function (string $currency, float $amount, float $expected) {
    Http::fake(['api.tap.company/*' => Http::response(tapCharge())]);

    tapGateway()->charge(tapRequest(['amount' => $amount, 'currency' => $currency]));

    Http::assertSent(fn (HttpRequest $request) => $request['amount'] === $expected);
})->with([
    'KWD has 3 decimals' => ['KWD', 1.23456, 1.235],
    'SAR has 2 decimals' => ['SAR', 1.23456, 1.23],
]);

it('splits the customer phone into country code and number', function (string $phone, string $code, string $number) {
    Http::fake(['api.tap.company/*' => Http::response(tapCharge())]);

    tapGateway()->charge(tapRequest(['customerEmail' => null, 'customerPhone' => $phone]));

    Http::assertSent(fn (HttpRequest $request) => $request['customer']['phone'] === ['country_code' => $code, 'number' => $number]);
})->with([
    'local' => ['5000 0000', '965', '50000000'],
    'plus prefix' => ['+965 5000-0000', '965', '50000000'],
    'double zero prefix' => ['0096550000000', '965', '50000000'],
    'foreign number' => ['+966 50 123 4567', '966', '501234567'],
    'egyptian number' => ['+20 100 123 4567', '20', '1001234567'],
]);

it('uses the configured country code for local numbers', function () {
    Http::fake(['api.tap.company/*' => Http::response(tapCharge())]);

    tapGateway(['phone_country_code' => '+966'])->charge(tapRequest(['customerPhone' => '0501234567']));

    Http::assertSent(fn (HttpRequest $request) => $request['customer']['phone'] === ['country_code' => '966', 'number' => '501234567']);
});

it('rejects an international phone number with an unrecognised country code', function () {
    tapGateway()->charge(tapRequest(['customerPhone' => '+999 1234 5678']));
})->throws(PaymentGatewayException::class, 'country code');

it('requires a customer name', function () {
    tapGateway()->charge(tapRequest(['customerName' => null]));
})->throws(PaymentGatewayException::class, 'customer name');

it('requires a customer email or phone', function () {
    tapGateway()->charge(tapRequest(['customerEmail' => null, 'customerPhone' => null]));
})->throws(PaymentGatewayException::class, 'email or phone');

it('requires a secret_key', function () {
    (new TapGateway('tap', []))->charge(tapRequest());
})->throws(InvalidGatewayConfigurationException::class);

it('surfaces Tap API errors', function () {
    Http::fake(['api.tap.company/*' => Http::response([
        'errors' => [['code' => '1117', 'description' => 'Order - Amount is invalid']],
    ], 400)]);

    tapGateway()->charge(tapRequest());
})->throws(PaymentGatewayException::class, '1117 Order - Amount is invalid');

it('verifies a customer redirect by re-fetching the charge', function (string $tapStatus, PaymentStatus $expected) {
    Http::fake(['api.tap.company/v2/charges/chg_TS01A1234567890' => Http::response(tapCharge(['status' => $tapStatus]))]);

    $result = tapGateway()->handleCallback(Request::create('/callback', 'GET', ['tap_id' => 'chg_TS01A1234567890']));

    expect($result->status)->toBe($expected)
        ->and($result->reference)->toBe('order-1')
        ->and($result->transactionId)->toBe('chg_TS01A1234567890')
        ->and($result->amount)->toBe(10.5)
        ->and($result->currency)->toBe('KWD');

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.tap.company/v2/charges/chg_TS01A1234567890');
})->with([
    ['CAPTURED', PaymentStatus::Paid],
    ['INITIATED', PaymentStatus::Pending],
    ['IN_PROGRESS', PaymentStatus::Pending],
    ['ABANDONED', PaymentStatus::Cancelled],
    ['CANCELLED', PaymentStatus::Cancelled],
    ['FAILED', PaymentStatus::Failed],
    ['DECLINED', PaymentStatus::Failed],
    ['RESTRICTED', PaymentStatus::Failed],
    ['TIMEDOUT', PaymentStatus::Failed],
    ['VOID', PaymentStatus::Failed],
    ['UNKNOWN', PaymentStatus::Failed],
]);

it('trusts only the re-fetched charge for webhooks, not the posted body', function () {
    Http::fake(['api.tap.company/v2/charges/chg_TS01A1234567890' => Http::response(tapCharge(['status' => 'DECLINED']))]);

    // A forged webhook claiming success.
    $result = tapGateway()->handleCallback(Request::create('/callback', 'POST', tapCharge(['status' => 'CAPTURED'])));

    expect($result->status)->toBe(PaymentStatus::Failed);
});

it('rejects a callback without a charge id', function () {
    tapGateway()->handleCallback(Request::create('/callback', 'GET'));
})->throws(InvalidWebhookException::class);

it('rejects a callback whose id is not a charge id', function () {
    tapGateway()->handleCallback(Request::create('/callback', 'GET', ['tap_id' => '../customers/cus_123']));
})->throws(InvalidWebhookException::class);

it('rejects authorize-only charges, which this driver does not support', function () {
    Http::fake(['api.tap.company/*' => Http::response(tapCharge(['status' => 'AUTHORIZED']))]);

    tapGateway()->handleCallback(Request::create('/callback', 'GET', ['tap_id' => 'chg_TS01A1234567890']));
})->throws(InvalidWebhookException::class, 'AUTHORIZED');

it('fires events through the package callback route', function () {
    config()->set('payment-gateways.gateways.tap', ['driver' => 'tap', 'secret_key' => TAP_PUBLIC_TEST_SECRET_KEY]);
    Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
    Http::fake(['api.tap.company/*' => Http::response(tapCharge(['status' => 'CAPTURED']))]);

    $this->postJson('/payment-gateways/tap/callback', ['id' => 'chg_TS01A1234567890'])
        ->assertOk()
        ->assertJson(['reference' => 'order-1', 'status' => 'paid']);

    Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->gateway === 'tap' && $e->result->transactionId === 'chg_TS01A1234567890');
});

it('is registered as the tap driver', function () {
    config()->set('payment-gateways.gateways.tap', ['driver' => 'tap', 'secret_key' => TAP_PUBLIC_TEST_SECRET_KEY]);

    expect(PaymentGateways::gateway('tap'))->toBeInstanceOf(TapGateway::class);
});

it('creates and re-fetches a real charge in the Tap sandbox', function () {
    $gateway = tapGateway(['secret_key' => getenv('TAP_TEST_SECRET_KEY') ?: TAP_PUBLIC_TEST_SECRET_KEY]);

    $response = $gateway->charge(tapRequest(['amount' => 1.0, 'reference' => 'test-'.uniqid()]));

    expect($response->requiresRedirect())->toBeTrue()
        ->and($response->transactionId)->toStartWith('chg_');

    $result = $gateway->handleCallback(Request::create('/callback', 'GET', ['tap_id' => $response->transactionId]));

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($result->reference)->toBe($response->reference);
})->group('sandbox');
