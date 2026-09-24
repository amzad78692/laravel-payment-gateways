<?php

namespace Amzad\PaymentGateways\Gateways;

use Amzad\PaymentGateways\Contracts\Gateway;
use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

/**
 * Convenience base class for drivers. Extending it is optional; implementing
 * the Gateway contract is all a driver actually needs.
 */
abstract class AbstractGateway implements Gateway
{
    /**
     * @param  array<string, mixed>  $config  This gateway's entry from `payment-gateways.gateways`.
     */
    public function __construct(
        protected string $name,
        protected array $config = [],
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->config, $key, $default);
    }

    /**
     * @throws InvalidGatewayConfigurationException
     */
    protected function requireConfig(string $key): mixed
    {
        return $this->config($key) ?? throw InvalidGatewayConfigurationException::missingKey($this->name, $key);
    }

    /**
     * The URL the provider should send the customer / webhook to.
     */
    protected function callbackUrl(PaymentRequestData $request): string
    {
        if ($request->callbackUrl !== null) {
            return $request->callbackUrl;
        }

        return Route::has('payment-gateways.callback')
            ? route('payment-gateways.callback', ['gateway' => $this->name])
            : throw new InvalidGatewayConfigurationException(
                'No callback URL: pass one in PaymentRequestData or enable the package routes.'
            );
    }
}
