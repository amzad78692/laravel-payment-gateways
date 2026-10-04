<?php

namespace Amzad\PaymentGateways\Gateways;

use Amzad\PaymentGateways\Contracts\Gateway;
use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

/**
 * Convenience base class for drivers. Extending it is optional; implementing
 * the Gateway contract is all a driver actually needs.
 *
 * Besides config and callback helpers it provides provider-neutral building blocks:
 *  - Callbacks:   callbackUrl(), errorUrl()
 *  - Environment: isTestMode(), environmentUrl()
 *  - Statuses:    mapStatus()
 *  - Customers:   splitName()
 *  - Locale:      language()
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
        if (!empty($request->callbackUrl)) {
            return $request->callbackUrl;
        }

        return Route::has('payment-gateways.callback')
            ? route('payment-gateways.callback', ['gateway' => $this->name])
            : throw new InvalidGatewayConfigurationException(
                'No callback URL: pass one in PaymentRequestData or enable the package routes.'
            );
    }

    /**
     * Where the provider should send the customer when the payment fails: the request's
     * errorUrl, or the callback URL so failures are handled like successes.
     */
    protected function errorUrl(PaymentRequestData $request): string
    {
        return $request->errorUrl ?? $this->callbackUrl($request);
    }

    /**
     * Human-readable provider name for error messages. Derived from the class name
     * ("AcmeGateway" -> "Acme"); drivers override it so subclasses keep the right name.
     */
    protected function providerName(): string
    {
        $class = class_basename(static::class);

        return str_ends_with($class, 'Gateway') ? substr($class, 0, -strlen('Gateway')) : $class;
    }

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    */

    /**
     * Whether to use the provider's sandbox, from the `test_mode` config key (default true).
     */
    protected function isTestMode(): bool
    {
        return (bool) $this->config('test_mode', true);
    }

    /**
     * The sandbox or live URL, depending on isTestMode().
     */
    protected function environmentUrl(string $sandboxUrl, string $liveUrl): string
    {
        return $this->isTestMode() ? $sandboxUrl : $liveUrl;
    }

    /*
    |--------------------------------------------------------------------------
    | Statuses
    |--------------------------------------------------------------------------
    */

    /**
     * Translate a provider's payment status using a [provider status => PaymentStatus] map.
     * The lookup ignores case.
     *
     * @param  array<string, PaymentStatus>  $map
     *
     * @throws InvalidWebhookException When the status is not in the map.
     */
    protected function mapStatus(array $map, string $status): PaymentStatus
    {
        return array_change_key_case($map, CASE_UPPER)[strtoupper($status)]
            ?? throw new InvalidWebhookException("{$this->providerName()} returned an unsupported payment status [{$status}].");
    }

    /*
    |--------------------------------------------------------------------------
    | Customers
    |--------------------------------------------------------------------------
    */

    /**
     * Split a full name on the first whitespace: "Amzad Khan Test" -> ["Amzad", "Khan Test"].
     *
     * @return array{0: string, 1: ?string} First name and last name (null when there is none).
     */
    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [trim($name)];

        return [$parts[0], $parts[1] ?? null];
    }

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    */

    /**
     * The payment page language, 'ar' or 'en', from the request locale or the
     * `language` config key. Drivers change the case if their provider needs it.
     */
    protected function language(PaymentRequestData $request): string
    {
        $locale = $request->locale ?: (string) $this->config('language', app()->getLocale());

        return $locale;
    }

    /**
     * The gateways returns a stdClass (or array, depending on version); normalise either to an array.
     *
     * @return array<string, mixed>
     */
    protected function toArray(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) json_encode($data), true) ?? [];

        return $decoded;
    }
}
