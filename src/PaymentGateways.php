<?php

namespace Amzad\PaymentGateways;

use Amzad\PaymentGateways\Contracts\Gateway;
use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Exceptions\GatewayNotFoundException;
use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Amzad\PaymentGateways\Gateways\FakeGateway;
use Amzad\PaymentGateways\Gateways\HesabeGateway;
use Amzad\PaymentGateways\Gateways\MyFatoorahGateway;
use Amzad\PaymentGateways\Gateways\TapGateway;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves configured gateways by name. Each entry in `payment-gateways.gateways`
 * picks a registered driver via its `driver` key (defaults to the entry name).
 */
class PaymentGateways
{
    /**
     * Resolved gateway instances, keyed by configured name.
     *
     * @var array<string, Gateway>
     */
    protected array $gateways = [];

    /**
     * Registered drivers: a Gateway class name, or a closure building one.
     *
     * @var array<string, Closure|class-string<Gateway>>
     */
    protected array $drivers = [
        'fake' => FakeGateway::class,
        'hesabe' => HesabeGateway::class,
        'myfatoorah' => MyFatoorahGateway::class,
        'tap' => TapGateway::class,
    ];

    public function __construct(
        protected Container $container,
        protected Repository $config,
    ) {}

    /**
     * Get a gateway by configured name, or the default gateway.
     *
     * @throws GatewayNotFoundException
     * @throws InvalidGatewayConfigurationException
     */
    public function gateway(?string $name = null): Gateway
    {
        $name ??= $this->getDefaultGateway();

        return $this->gateways[$name] ??= $this->resolve($name);
    }

    /**
     * @throws InvalidGatewayConfigurationException
     */
    public function getDefaultGateway(): string
    {
        $default = $this->config->get('payment-gateways.default');

        return $default ?: throw new InvalidGatewayConfigurationException('No default payment gateway is configured.');
    }

    /**
     * Register a driver. The resolver is a Gateway class name (constructed with
     * `name` and `config` arguments) or a closure `fn (array $config, Container $app, string $name): Gateway`.
     *
     * @param  Closure|class-string<Gateway>  $resolver
     */
    public function extend(string $driver, Closure|string $resolver): static
    {
        $this->drivers[$driver] = $resolver;
        $this->gateways = [];

        return $this;
    }

    /**
     * Drop cached gateway instances (e.g. after changing config at runtime).
     */
    public function forgetGateways(): static
    {
        $this->gateways = [];

        return $this;
    }

    /**
     * Shortcut for `gateway()->charge()` on the default gateway.
     */
    public function charge(PaymentRequestData $request): PaymentResponseData
    {
        return $this->gateway()->charge($request);
    }

    protected function resolve(string $name): Gateway
    {
        $config = $this->config->get("payment-gateways.gateways.{$name}");

        if (! is_array($config)) {
            throw GatewayNotFoundException::named($name);
        }

        $driver = $config['driver'] ?? $name;
        $resolver = $this->drivers[$driver] ?? throw GatewayNotFoundException::driver($driver, $name);

        $gateway = $resolver instanceof Closure
            ? $resolver($config, $this->container, $name)
            : $this->container->make($resolver, ['name' => $name, 'config' => $config]);

        if (! $gateway instanceof Gateway) {
            throw new InvalidGatewayConfigurationException(
                "Driver [{$driver}] for payment gateway [{$name}] must return an instance of ".Gateway::class.'.'
            );
        }

        return $gateway;
    }
}
