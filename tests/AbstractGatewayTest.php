<?php

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Gateways\AbstractGateway;
use Illuminate\Http\Request;

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

it('maps provider statuses ignoring case', function () {
    $map = ['CAPTURED' => PaymentStatus::Paid, 'failed' => PaymentStatus::Failed];

    expect(helperGateway()->mapStatus($map, 'captured'))->toBe(PaymentStatus::Paid)
        ->and(helperGateway()->mapStatus($map, 'FAILED'))->toBe(PaymentStatus::Failed);
});

it('rejects an unmapped provider status', function () {
    helperGateway()->mapStatus(['CAPTURED' => PaymentStatus::Paid], 'VOID');
})->throws(InvalidWebhookException::class, 'Acme returned an unsupported payment status [VOID]');

it('picks the sandbox or live url from test_mode', function (array $config, string $expected) {
    expect(helperGateway($config)->environmentUrl('https://sandbox.acme.test', 'https://api.acme.test'))->toBe($expected);
})->with([
    'default is test mode' => [[], 'https://sandbox.acme.test'],
    'live' => [['test_mode' => false], 'https://api.acme.test'],
]);

it('falls back to the callback url when there is no error url', function () {
    $request = new PaymentRequestData(amount: 1, reference: 'x', callbackUrl: 'https://shop.test/callback');

    expect(helperGateway()->errorUrl($request))->toBe('https://shop.test/callback')
        ->and(helperGateway()->errorUrl(new PaymentRequestData(
            amount: 1, reference: 'x', callbackUrl: 'https://shop.test/callback', errorUrl: 'https://shop.test/failed',
        )))->toBe('https://shop.test/failed');
});

it('splits a full name on the first space', function (string $name, array $expected) {
    expect(helperGateway()->splitName($name))->toBe($expected);
})->with([
    ['Amzad Khan Test', ['Amzad', 'Khan Test']],
    ['  Amzad   Khan ', ['Amzad', 'Khan']],
    ['Amzad', ['Amzad', null]],
]);

it('picks the payment page language from the request locale or config', function (string $locale, array $config, string $expected) {
    $request = new PaymentRequestData(amount: 1, reference: 'x', locale: $locale);

    expect(helperGateway($config)->language($request))->toBe($expected);
})->with([
    ['ar', [], 'ar'],
    ['en', [], 'en'],
    ['', ['language' => 'ar'], 'ar'],
]);
