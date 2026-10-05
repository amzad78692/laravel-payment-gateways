<?php

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Events\PaymentFailed;
use Amzad\PaymentGateways\Events\PaymentSucceeded;
use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Amzad\PaymentGateways\Facades\PaymentGateways;
use Amzad\PaymentGateways\Gateways\UPaymentGateway;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// UPayments' public non-whitelabel sandbox token: https://developers.upayments.com/reference/test-environment-details
const UPAYMENT_PUBLIC_TEST_API_KEY = 'NWty8gdjt79cf756hdtyu6u8jht5ns6gfhgy8hdjL';

const UPAYMENT_TRACK_ID = '019ae3cc7da914644b7efd5638cdc83fv2';

function upaymentGateway(array $config = []): UPaymentGateway
{
    return new UPaymentGateway('upayment', $config + ['api_key' => UPAYMENT_PUBLIC_TEST_API_KEY]);
}

function upaymentRequest(array $overrides = []): PaymentRequestData
{
    return new PaymentRequestData(...$overrides + [
        'amount' => 10.5,
        'reference' => 'order-1',
        'description' => 'Test order',
        'customerName' => 'Amzad Khan',
        'customerEmail' => 'amzad@example.com',
        'callbackUrl' => 'https://shop.test/payment-gateways/upayment/callback',
    ]);
}

function upaymentStatus(array $transaction = []): array
{
    return [
        'status' => true,
        'message' => 'Response received successfully',
        'data' => ['transaction' => $transaction + [
            'result' => 'CAPTURED',
            'track_id' => UPAYMENT_TRACK_ID,
            'merchant_requested_order_id' => 'order-1',
            'reference' => 'order-1',
            'total_price' => '10.500',
            'currency_type' => 'KWD',
        ]],
    ];
}

it('creates a charge and returns the hosted payment page url', function () {
    Http::fake(['sandboxapi.upayments.com/api/v1/charge' => Http::response([
        'status' => true,
        'message' => 'Payment link generated successfully',
        'data' => ['link' => 'https://sandbox.upayments.com/?session_id=abc'],
    ], 201)]);

    $response = upaymentGateway()->charge(upaymentRequest(['errorUrl' => 'https://shop.test/failed']));

    expect($response->status)->toBe(PaymentStatus::Pending)
        ->and($response->requiresRedirect())->toBeTrue()
        ->and($response->redirectUrl)->toBe('https://sandbox.upayments.com/?session_id=abc')
        ->and($response->transactionId)->toBe('abc')
        ->and($response->reference)->toBe('order-1');

    Http::assertSent(function (HttpRequest $request) {
        return $request->method() === 'POST'
            && $request->url() === 'https://sandboxapi.upayments.com/api/v1/charge'
            && $request->hasHeader('Authorization', 'Bearer '.UPAYMENT_PUBLIC_TEST_API_KEY)
            && $request['order'] === [
                'id' => 'order-1',
                'reference' => 'order-1',
                'description' => 'Test order',
                'currency' => 'KWD',
                'amount' => 10.5,
            ]
            && $request['reference'] === ['id' => 'order-1']
            && $request['language'] === 'en'
            && $request['customer'] === ['name' => 'Amzad Khan', 'email' => 'amzad@example.com']
            && $request['returnUrl'] === 'https://shop.test/payment-gateways/upayment/callback'
            && $request['cancelUrl'] === 'https://shop.test/failed'
            && $request['notificationUrl'] === 'https://shop.test/payment-gateways/upayment/callback'
            && ! isset($request['paymentGateway']);
    });
});

it('uses the live API, arabic locale and a default description', function () {
    Http::fake(['uapi.upayments.com/*' => Http::response(['status' => true, 'data' => ['link' => 'https://pay.test']])]);

    upaymentGateway(['test_mode' => false])->charge(upaymentRequest(['locale' => 'ar', 'description' => null]));

    Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://uapi.upayments.com/api/v1/charge'
        && $request['language'] === 'ar'
        && $request['order']['description'] === 'Order order-1');
});

it('requires an api_key', function () {
    (new UPaymentGateway('upayment', []))->charge(upaymentRequest());
})->throws(InvalidGatewayConfigurationException::class);

it('surfaces UPayments API errors', function () {
    Http::fake(['sandboxapi.upayments.com/*' => Http::response(['status' => false, 'message' => 'The order.amount field is required.'], 400)]);

    upaymentGateway()->charge(upaymentRequest());
})->throws(PaymentGatewayException::class, 'UPayment charge failed: The order.amount field is required.');

it('verifies a callback by re-fetching the payment status', function (?string $result, PaymentStatus $expected) {
    Http::fake(['sandboxapi.upayments.com/api/v1/get-payment-status/'.UPAYMENT_TRACK_ID => Http::response(upaymentStatus(['result' => $result]))]);

    $callback = upaymentGateway()->handleCallback(Request::create('/callback', 'GET', ['track_id' => UPAYMENT_TRACK_ID, 'result' => 'CAPTURED']));

    expect($callback->status)->toBe($expected)
        ->and($callback->reference)->toBe('order-1')
        ->and($callback->transactionId)->toBe(UPAYMENT_TRACK_ID)
        ->and($callback->amount)->toBe(10.5)
        ->and($callback->currency)->toBe('KWD');
})->with([
    ['CAPTURED', PaymentStatus::Paid],
    ['PENDING', PaymentStatus::Pending],
    [null, PaymentStatus::Pending],
    ['CANCELED', PaymentStatus::Cancelled],
    ['NOT CAPTURED', PaymentStatus::Failed],
    ['FAILED', PaymentStatus::Failed],
    ['ERROR', PaymentStatus::Failed],
    ['VOIDED', PaymentStatus::Failed],
]);

it('trusts only the re-fetched status for webhooks, not the posted body', function () {
    Http::fake(['sandboxapi.upayments.com/*' => Http::response(upaymentStatus(['result' => 'NOT CAPTURED']))]);

    // A forged webhook claiming success.
    $result = upaymentGateway()->handleCallback(Request::create('/callback', 'POST', ['track_id' => UPAYMENT_TRACK_ID, 'result' => 'CAPTURED']));

    expect($result->status)->toBe(PaymentStatus::Failed);
});

it('rejects a callback without a valid track_id', function (array $query) {
    upaymentGateway()->handleCallback(Request::create('/callback', 'GET', $query));
})->with([
    'missing' => [[]],
    'path traversal' => [['track_id' => '../charge']],
])->throws(InvalidWebhookException::class);

it('surfaces a failed status lookup', function () {
    Http::fake(['sandboxapi.upayments.com/*' => Http::response(['status' => false, 'message' => 'Transaction not found', 'data' => []], 404)]);

    upaymentGateway()->handleCallback(Request::create('/callback', 'GET', ['track_id' => UPAYMENT_TRACK_ID]));
})->throws(PaymentGatewayException::class, 'Transaction not found');

it('fires events through the package callback route', function () {
    config()->set('payment-gateways.gateways.upayment', ['driver' => 'upayment', 'api_key' => UPAYMENT_PUBLIC_TEST_API_KEY]);
    Event::fake([PaymentSucceeded::class, PaymentFailed::class]);
    Http::fake(['sandboxapi.upayments.com/*' => Http::response(upaymentStatus())]);

    $this->postJson('/payment-gateways/upayment/callback', ['track_id' => UPAYMENT_TRACK_ID])
        ->assertOk()
        ->assertJson(['reference' => 'order-1', 'status' => 'paid']);

    Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->gateway === 'upayment' && $e->result->transactionId === UPAYMENT_TRACK_ID);
});

it('is registered as the upayment driver', function () {
    config()->set('payment-gateways.gateways.upayment', ['driver' => 'upayment', 'api_key' => UPAYMENT_PUBLIC_TEST_API_KEY]);

    expect(PaymentGateways::gateway('upayment'))->toBeInstanceOf(UPaymentGateway::class);
});

it('creates a real charge in the UPayments sandbox', function () {
    $response = upaymentGateway()->charge(upaymentRequest(['amount' => 1.0, 'reference' => 'test'.uniqid()]));

    expect($response->requiresRedirect())->toBeTrue()
        ->and($response->transactionId)->not->toBeEmpty();
})->group('sandbox');
