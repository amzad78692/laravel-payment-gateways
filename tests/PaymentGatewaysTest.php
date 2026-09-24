<?php

use Amzad\PaymentGateways\Contracts\Gateway;
use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Events\PaymentFailed;
use Amzad\PaymentGateways\Events\PaymentSucceeded;
use Amzad\PaymentGateways\Exceptions\GatewayNotFoundException;
use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Amzad\PaymentGateways\Facades\PaymentGateways;
use Amzad\PaymentGateways\Gateways\FakeGateway;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config()->set('payment-gateways.default', 'fake');
    config()->set('payment-gateways.gateways', ['fake' => ['driver' => 'fake']]);
});

it('resolves the default gateway', function () {
    expect(PaymentGateways::gateway())->toBeInstanceOf(FakeGateway::class)
        ->and(PaymentGateways::gateway()->name())->toBe('fake');
});

it('charges through the default gateway', function () {
    $response = PaymentGateways::charge(new PaymentRequestData(amount: 10.5, reference: 'order-1'));

    expect($response->requiresRedirect())->toBeTrue()
        ->and($response->reference)->toBe('order-1');
});

it('registers custom drivers with a closure', function () {
    PaymentGateways::extend('custom', fn (array $config, $app, string $name) => new FakeGateway($name, $config));
    config()->set('payment-gateways.gateways.acme', ['driver' => 'custom']);

    expect(PaymentGateways::gateway('acme')->name())->toBe('acme');
});

it('registers custom drivers with a class name', function () {
    PaymentGateways::extend('custom', FakeGateway::class);
    config()->set('payment-gateways.gateways.acme', ['driver' => 'custom']);

    expect(PaymentGateways::gateway('acme'))->toBeInstanceOf(Gateway::class);
});

it('throws for an unknown gateway', function () {
    PaymentGateways::gateway('nope');
})->throws(GatewayNotFoundException::class);

it('throws for an unregistered driver', function () {
    config()->set('payment-gateways.gateways.acme', ['driver' => 'missing']);

    PaymentGateways::gateway('acme');
})->throws(GatewayNotFoundException::class);

it('throws when no default gateway is configured', function () {
    config()->set('payment-gateways.default', null);

    PaymentGateways::gateway();
})->throws(InvalidGatewayConfigurationException::class);

it('fires PaymentSucceeded from the callback route', function () {
    Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

    $this->postJson('/payment-gateways/fake/callback', ['reference' => 'order-1', 'status' => 'paid'])
        ->assertOk()
        ->assertJson(['reference' => 'order-1', 'status' => 'paid']);

    Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->gateway === 'fake' && $e->result->reference === 'order-1');
    Event::assertNotDispatched(PaymentFailed::class);
});

it('fires PaymentFailed for failed payments', function () {
    Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

    $this->postJson('/payment-gateways/fake/callback', ['reference' => 'order-2', 'status' => 'failed'])->assertOk();

    Event::assertDispatched(PaymentFailed::class);
    Event::assertNotDispatched(PaymentSucceeded::class);
});

it('rejects invalid callbacks', function () {
    $this->postJson('/payment-gateways/fake/callback', [])->assertStatus(400);
});

it('returns 404 for unknown gateways', function () {
    $this->postJson('/payment-gateways/nope/callback', ['reference' => 'x'])->assertNotFound();
});

it('redirects customers returning from the gateway when configured', function () {
    config()->set('payment-gateways.redirects.success', '/thanks');

    $this->get('/payment-gateways/fake/callback?reference=order-3&status=paid')->assertRedirect('/thanks?payment_reference=order-3&payment_status=paid');
});
