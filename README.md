# Laravel Payment Gateways

[![Latest Version on Packagist](https://img.shields.io/packagist/v/amzad/laravel-payment-gateways.svg?style=flat-square)](https://packagist.org/packages/amzad/laravel-payment-gateways)
[![Tests](https://img.shields.io/github/actions/workflow/status/amzad/laravel-payment-gateways/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/amzad/laravel-payment-gateways/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/amzad/laravel-payment-gateways.svg?style=flat-square)](https://packagist.org/packages/amzad/laravel-payment-gateways)

Unified payment processing for Laravel with Gulf-region gateways (MyFatoorah, KNET, Tap, Hesabe, UPayment) behind a single driver-based API. Every gateway returns the same normalised response shapes, and a new provider can be added by implementing one driver class.

> **Status:** under active development. The `myfatoorah` driver is implemented; the rest are planned.

## How it works

- **One contract, many drivers.** Every gateway implements the `Gateway` interface (`charge()` and `handleCallback()`), so the rest of your app never needs to know which provider it's talking to.
- **Normalised data.** `charge()` always returns a `PaymentResponseData`, and a gateway callback always resolves to a `WebhookResult`, regardless of how different the underlying provider's API is (MyFatoorah's invoice flow vs. KNET's encrypted redirect, for example).
- **One callback route per gateway.** Both the customer's browser redirect and any async webhook a provider sends land on the same route and are turned into `PaymentSucceeded` or `PaymentFailed` events.

## Installation

Install the package via Composer:

```bash
composer require amzad/laravel-payment-gateways
```

Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-payment-gateways-config"
```

Set a default gateway and at least one gateway's credentials in `.env`:

```env
PAYMENT_GATEWAY=myfatoorah

MYFATOORAH_API_KEY=your-api-key
MYFATOORAH_VC_CODE=KWT
MYFATOORAH_TEST_MODE=true
```

## Configuration

`config/payment-gateways.php`:

```php
return [

    // Used when you call charge()/gateway() without naming a gateway.
    'default' => env('PAYMENT_GATEWAY'),

    // Each entry is a configured gateway. "driver" selects the implementation
    // (defaults to the entry's own key), and the rest of the keys are passed
    // straight to that driver.
    'gateways' => [
        'myfatoorah' => [
            'driver' => 'myfatoorah',
            'api_key' => env('MYFATOORAH_API_KEY'),
            'vc_code' => env('MYFATOORAH_VC_CODE', 'KWT'),
            'test_mode' => env('MYFATOORAH_TEST_MODE', true),
        ],
    ],

    // One route handles both browser redirects and webhooks:
    // {prefix}/{gateway}/callback (route name: payment-gateways.callback).
    'routes' => [
        'enabled' => true,
        'prefix' => 'payment-gateways',
        'middleware' => ['api'],
    ],

    // Where to send the customer after they return from the gateway's page.
    // Leave null to respond with JSON instead of redirecting.
    'redirects' => [
        'success' => null,
        'failed' => null,
    ],

];
```

You can define more than one entry for the same driver (e.g. separate live and test credentials, or the same gateway under two merchant accounts) — just give each a unique key and set `driver` accordingly.

## Usage

### Charging

```php
use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Facades\PaymentGateways;

$response = PaymentGateways::charge(new PaymentRequestData(
    amount: 10.500,
    reference: $order->reference,   // your own unique order/invoice reference
    currency: 'KWD',
    customerName: $order->customer->name,
    customerEmail: $order->customer->email,
    customerPhone: $order->customer->phone,
));

if ($response->requiresRedirect()) {
    return redirect()->away($response->redirectUrl);
}
```

To use a gateway other than the configured default:

```php
PaymentGateways::gateway('myfatoorah')->charge($request);
```

### Handling the result

The package's own route verifies every callback with the provider before trusting it, then fires one of these events. Listen for them wherever you update your own records:

```php
use Amzad\PaymentGateways\Events\PaymentSucceeded;
use Amzad\PaymentGateways\Events\PaymentFailed;

Event::listen(function (PaymentSucceeded $event) {
    // $event->gateway   e.g. 'myfatoorah'
    // $event->result    a WebhookResult: reference, transactionId, amount, payload...
    Order::where('reference', $event->result->reference)->update(['status' => 'paid']);
});

Event::listen(function (PaymentFailed $event) {
    Order::where('reference', $event->result->reference)->update(['status' => 'failed']);
});
```

Gateways can notify you more than once for the same payment (a browser redirect *and* an async webhook), so listeners must be idempotent.

### Adding a new gateway

Implement the `Gateway` contract — extending `AbstractGateway` is optional but gives you `config()`/`requireConfig()` helpers and a `callbackUrl()` builder:

```php
use Amzad\PaymentGateways\Gateways\AbstractGateway;
use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Illuminate\Http\Request;

class MyGateway extends AbstractGateway
{
    public function charge(PaymentRequestData $request): PaymentResponseData
    {
        // call the provider, then:
        return new PaymentResponseData(/* ... */);
    }

    public function handleCallback(Request $request): WebhookResult
    {
        // verify the callback (signature or a status lookup), then:
        return new WebhookResult(/* ... */);
    }
}
```

Register it, typically in a service provider's `boot()` method:

```php
PaymentGateways::extend('my-gateway', MyGateway::class);
```

Then configure an entry that uses it:

```php
'gateways' => [
    'acme' => ['driver' => 'my-gateway', 'api_key' => env('ACME_API_KEY')],
],
```

### Testing without hitting a real gateway

The package ships a `fake` driver that makes no HTTP calls, useful in local development and your own tests:

```php
'gateways' => [
    'fake' => ['driver' => 'fake'],
],
```

```php
$this->postJson('/payment-gateways/fake/callback', [
    'reference' => 'order-1',
    'status' => 'paid', // paid | failed | cancelled | pending
])->assertOk();
```

## Supported gateways

| Gateway | Driver key | Status |
|---|---|---|
| MyFatoorah | `myfatoorah` | Implemented, built on the official [`myfatoorah/library`](https://packagist.org/packages/myfatoorah/library) SDK |
| KNET | `knet` | Planned |
| Tap Payments | `tap` | Planned |
| Hesabe | `hesabe` | Planned |
| UPayment | `upayment` | Planned |

## Testing

```bash
composer test
composer analyse
composer format
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Amzad](https://github.com/amzad)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
