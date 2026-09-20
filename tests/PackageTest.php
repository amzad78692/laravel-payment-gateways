<?php

use Amzad\PaymentGateways\PaymentGatewaysServiceProvider;

it('registers the service provider', function () {
    expect(app()->getProviders(PaymentGatewaysServiceProvider::class))->not->toBeEmpty();
});

it('merges the package config', function () {
    expect(config('payment-gateways'))->toBeArray();
});
