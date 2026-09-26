<?php

namespace Amzad\PaymentGateways\Gateways;

use Amzad\PaymentGateways\Contracts\Gateway;
use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * Convenience base class for drivers. Extending it is optional; implementing
 * the Gateway contract is all a driver actually needs.
 *
 * Besides config and callback helpers it provides provider-neutral building blocks:
 *  - HTTP:      http(), send(), responseJson(), errorMessage()
 *  - Amounts:   currencyDecimals(), roundAmount()
 *  - Customers: splitName(), splitPhone()
 *  - Locale:    language()
 */
abstract class AbstractGateway implements Gateway
{
    /**
     * ISO 4217 currencies with 3 decimal places. Every other currency these gateways support uses 2.
     *
     * @var list<string>
     */
    protected const THREE_DECIMAL_CURRENCIES = ['KWD', 'BHD', 'OMR', 'JOD', 'IQD', 'LYD', 'TND'];

    /**
     * Dialling codes recognised in international-format phone numbers: GCC and wider
     * MENA, plus common expatriate-community countries. Country codes are prefix-free,
     * so matching a number against this list is unambiguous.
     *
     * @var list<string>
     */
    protected const KNOWN_COUNTRY_CODES = [
        '965', '966', '971', '973', '974', '968',       // GCC
        '962', '961', '964', '963', '970', '967', '20', '212', '213', '216', '218', '249',
        '91', '92', '880', '977', '94', '63', '62', '60', '90', '98',
        '44', '1', '33', '49', '61', '7', '86',
    ];

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
    | HTTP
    |--------------------------------------------------------------------------
    */

    /**
     * Base HTTP client for the provider's API: JSON in and out, with the `timeout`
     * config key (default 30 seconds). Drivers override this to add their base URL
     * and authentication, calling parent::http() first.
     */
    protected function http(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout((int) $this->config('timeout', 30));
    }

    /**
     * Run a request built on http() and turn transport or API errors into package exceptions.
     *
     * @param  callable(PendingRequest): Response  $callback
     * @param  string  $action  What is being done, for the error message, e.g. "charge".
     *
     * @throws PaymentGatewayException
     */
    protected function send(callable $callback, string $action): Response
    {
        try {
            $response = $callback($this->http());
        } catch (ConnectionException $e) {
            throw new PaymentGatewayException("{$this->providerName()} {$action} failed: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            throw new PaymentGatewayException("{$this->providerName()} {$action} failed: {$this->errorMessage($response)}");
        }

        return $response;
    }

    /**
     * The response body as an array (empty when the body is not a JSON object).
     *
     * @return array<string, mixed>
     */
    protected function responseJson(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Extract a readable message from a failed response. Drivers override this for
     * their provider's error format; the default reads a top-level "message".
     */
    protected function errorMessage(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) && $message !== '' ? $message : "HTTP {$response->status()}";
    }

    /*
    |--------------------------------------------------------------------------
    | Amounts
    |--------------------------------------------------------------------------
    */

    /**
     * Number of decimal places for an ISO 4217 currency code.
     */
    protected function currencyDecimals(string $currency): int
    {
        return in_array(strtoupper($currency), static::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2;
    }

    /**
     * Round an amount to its currency's decimal places (10.12345 KWD -> 10.123).
     */
    protected function roundAmount(float $amount, string $currency): float
    {
        return round($amount, $this->currencyDecimals($currency));
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

    /**
     * Split a phone number into country code and local number, both digits only.
     *
     * A local number ("5000 0000") gets $defaultCountryCode. An international number
     * ("+966 50...", "00966 50...") keeps its own code, recognised from $defaultCountryCode
     * plus KNOWN_COUNTRY_CODES.
     *
     * @return array{country_code: string, number: string}
     *
     * @throws PaymentGatewayException When an international number's country code is not recognised.
     */
    protected function splitPhone(string $phone, string $defaultCountryCode = '965'): array
    {
        $defaultCode = ltrim($defaultCountryCode, '+0');
        $trimmed = trim($phone);
        $digits = (string) preg_replace('/\D+/', '', $trimmed);

        $international = str_starts_with($trimmed, '+') || str_starts_with($trimmed, '00');

        if (! $international) {
            return ['country_code' => $defaultCode, 'number' => ltrim($digits, '0')];
        }

        if (str_starts_with($trimmed, '00')) {
            $digits = substr($digits, 2);
        }

        foreach (array_unique([$defaultCode, ...static::KNOWN_COUNTRY_CODES]) as $code) {
            if ($code !== '' && str_starts_with($digits, $code)) {
                return ['country_code' => $code, 'number' => substr($digits, strlen($code))];
            }
        }

        throw new PaymentGatewayException(
            "{$this->providerName()}: could not determine the country code of phone number [{$phone}]. Pass the local number instead."
        );
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
        $locale = $request->locale !== '' ? $request->locale : (string) $this->config('language', 'en');

        return str_starts_with(strtolower($locale), 'ar') ? 'ar' : 'en';
    }
}
