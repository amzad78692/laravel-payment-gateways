<?php

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Amzad\PaymentGateways\Gateways\AbstractGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * A minimal driver that exposes AbstractGateway's protected helpers for testing.
 */
function helperGateway(array $config = []): AbstractGateway
{
    return new class('acme', $config) extends AbstractGateway
    {
        public function charge(PaymentRequestData $request): PaymentResponseData
        {
            throw new LogicException('Not used.');
        }

        public function handleCallback(Request $request): WebhookResult
        {
            throw new LogicException('Not used.');
        }

        protected function providerName(): string
        {
            return 'Acme';
        }

        public function __call(string $method, array $arguments): mixed
        {
            return $this->{$method}(...$arguments);
        }
    };
}

it('rounds amounts to the currency decimals', function (string $currency, int $decimals, float $expected) {
    expect(helperGateway()->currencyDecimals($currency))->toBe($decimals)
        ->and(helperGateway()->roundAmount(1.23456, $currency))->toBe($expected);
})->with([
    ['KWD', 3, 1.235],
    ['bhd', 3, 1.235],
    ['OMR', 3, 1.235],
    ['SAR', 2, 1.23],
    ['USD', 2, 1.23],
]);

it('splits a full name on the first space', function (string $name, array $expected) {
    expect(helperGateway()->splitName($name))->toBe($expected);
})->with([
    ['Amzad Khan Test', ['Amzad', 'Khan Test']],
    ['  Amzad   Khan ', ['Amzad', 'Khan']],
    ['Amzad', ['Amzad', null]],
]);

it('splits a phone number into country code and number', function (string $phone, string $default, array $expected) {
    expect(helperGateway()->splitPhone($phone, $default))->toBe($expected);
})->with([
    'local' => ['5000 0000', '965', ['country_code' => '965', 'number' => '50000000']],
    'local with trunk zero' => ['050 123 4567', '+966', ['country_code' => '966', 'number' => '501234567']],
    'plus prefix' => ['+965 5000-0000', '965', ['country_code' => '965', 'number' => '50000000']],
    'double zero prefix' => ['00966501234567', '965', ['country_code' => '966', 'number' => '501234567']],
    'two digit code' => ['+20 100 123 4567', '965', ['country_code' => '20', 'number' => '1001234567']],
]);

it('rejects an international phone number with an unknown country code', function () {
    helperGateway()->splitPhone('+999 1234 5678');
})->throws(PaymentGatewayException::class, 'Acme: could not determine the country code');

it('picks the payment page language from the request locale or config', function (string $locale, array $config, string $expected) {
    $request = new PaymentRequestData(amount: 1, reference: 'x', locale: $locale);

    expect(helperGateway($config)->language($request))->toBe($expected);
})->with([
    ['ar', [], 'ar'],
    ['ar_KW', [], 'ar'],
    ['en', [], 'en'],
    ['', ['language' => 'ar'], 'ar'],
]);

it('sends JSON requests and decodes JSON responses', function () {
    Http::fake(['acme.test/*' => Http::response(['ok' => true])]);

    $gateway = helperGateway();
    $data = $gateway->responseJson(
        $gateway->send(fn (PendingRequest $http) => $http->post('https://acme.test/pay', ['a' => 1]), 'charge')
    );

    expect($data)->toBe(['ok' => true]);

    Http::assertSent(fn ($request) => $request->hasHeader('Accept', 'application/json')
        && $request->isJson()
        && $request['a'] === 1);
});

it('turns failed responses into a PaymentGatewayException', function () {
    Http::fake(['acme.test/*' => Http::response(['message' => 'Invalid API key'], 401)]);

    helperGateway()->send(fn (PendingRequest $http) => $http->get('https://acme.test/status'), 'status lookup');
})->throws(PaymentGatewayException::class, 'Acme status lookup failed: Invalid API key');

it('falls back to the HTTP status when a failed response has no message', function () {
    Http::fake(['acme.test/*' => Http::response('Server Error', 500)]);

    helperGateway()->send(fn (PendingRequest $http) => $http->get('https://acme.test/status'), 'status lookup');
})->throws(PaymentGatewayException::class, 'Acme status lookup failed: HTTP 500');

it('returns an empty array for non-JSON responses', function () {
    Http::fake(['acme.test/*' => Http::response('not json')]);

    $response = helperGateway()->send(fn (PendingRequest $http) => $http->get('https://acme.test/x'), 'lookup');

    expect($response)->toBeInstanceOf(Response::class)
        ->and(helperGateway()->responseJson($response))->toBe([]);
});
