# Laravel Payment Gateways

[![Latest Version on Packagist](https://img.shields.io/packagist/v/amzad/laravel-payment-gateways.svg?style=flat-square)](https://packagist.org/packages/amzad/laravel-payment-gateways)
[![Tests](https://img.shields.io/github/actions/workflow/status/amzad/laravel-payment-gateways/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/amzad/laravel-payment-gateways/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/amzad/laravel-payment-gateways.svg?style=flat-square)](https://packagist.org/packages/amzad/laravel-payment-gateways)

Unified payment processing for Laravel with Gulf-region gateways (MyFatoorah, KNET, Tap, Hesabe, UPayment) behind a single driver-based API. Every gateway returns the same normalised response shapes, and a new provider can be added by implementing one driver class.

> **Status:** under active development. The `myfatoorah`, `tap`, `hesabe` and `knet` drivers are implemented; the rest are planned.

## How it works

- **No phone numbers.** The package never sends a customer phone number to any gateway; `PaymentRequestData` has no phone field.
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

# or, for Tap
TAP_SECRET_KEY=sk_test_XKokBfNWv6FIYuTMg5sLPjhJ

# or, for Hesabe (these are Hesabe's public sandbox credentials)
HESABE_MERCHANT_CODE=842217
HESABE_ACCESS_CODE=c333729b-d060-4b74-a49d-7686a8353481
HESABE_SECRET_KEY=PkW64zMe5NVdrlPVNnjo2Jy9nOb7v1Xg
HESABE_IV_KEY=5NVdrlPVNnjo2Jy9
HESABE_TEST_MODE=true
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
        'tap' => [
            'driver' => 'tap',
            'secret_key' => env('TAP_SECRET_KEY'),
            'source' => env('TAP_SOURCE', 'src_all'),
        ],
        'hesabe' => [
            'driver' => 'hesabe',
            'merchant_code' => env('HESABE_MERCHANT_CODE'),
            'access_code' => env('HESABE_ACCESS_CODE'),
            'secret_key' => env('HESABE_SECRET_KEY'),
            'iv_key' => env('HESABE_IV_KEY'),
            'test_mode' => env('HESABE_TEST_MODE', true),
            'payment_type' => env('HESABE_PAYMENT_TYPE', 0),
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

`AbstractGateway` also has provider-neutral helpers, so most drivers only contain what is specific to their provider (see `TapGateway` for a full example). Each driver makes its own HTTP calls with Laravel's `Http` client, typically through a small private `client()` method that sets the base URL and credentials. For providers that encrypt payloads, `Amzad\PaymentGateways\Support\AesCrypt` handles AES-CBC with hex encoding (`encryptJson()` / `decryptJson()`):

| Helper | What it does |
|---|---|
| `mapStatus($map, $status)` | Translates a provider status with a `[provider status => PaymentStatus]` map, ignoring case; unknown statuses throw `InvalidWebhookException`. |
| `isTestMode()` / `environmentUrl($sandbox, $live)` | Reads the `test_mode` config key (default `true`) and picks the matching URL. |
| `errorUrl($request)` | The request's `errorUrl`, or the callback URL when none is given. |
| `splitName($name)` | `"Amzad Khan Test"` becomes `["Amzad", "Khan Test"]`. |
| `language($request)` | The request locale, or the `language` config key, or the app locale. |
| `providerName()` | Name used in error messages. Override it to return your provider's name. |

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

### Tap Payments

| Key | Default | Notes |
|---|---|---|
| `secret_key` | — (required) | `sk_test_...` for the sandbox, `sk_live_...` for live. Tap uses the same URL for both, so there is no test-mode flag. |
| `source` | `src_all` | `src_all` shows every method enabled on your account, `src_card` cards only, `src_kw.knet` sends the customer straight to KNET. |
| `merchant_id` | — | Only needed if your Tap account has several merchants. |

Tap requires a customer name and email. `customerName` is split on the first space into Tap's first and last name.

Tap redirects the customer to the callback route with `?tap_id=chg_...` and also POSTs a webhook to the same route. Either way, the driver re-fetches the charge from Tap before firing an event. Authorize-only (`AUTHORIZED`) charges are not supported yet.

For a quick sandbox test you can use Tap's [public test keys](https://developers.tap.company/reference/testing-keys) and [test cards](https://developers.tap.company/reference/testing-cards), but use your own dashboard keys for real development.

### Hesabe

| Key | Default | Notes |
|---|---|---|
| `merchant_code` | — (required) | From the Hesabe merchant panel (Profile → Account). |
| `access_code` | — (required) | Sent as the `accessCode` header. |
| `secret_key` | — (required) | 32-character encryption key. |
| `iv_key` | — (required) | 16-character IV key. |
| `test_mode` | `true` | `true` uses `https://sandbox.hesabe.com`, `false` uses `https://api.hesabe.com`. Sandbox and live have different credentials. |
| `payment_type` | `0` | `0` shows Hesabe's page where the customer picks a method. Set a method id to go straight to it: `1` KNET, `2` MPGS cards, `7` AMEX, `9` Apple Pay, `16` Google Pay, `18` Deema, `20` Samsung Pay. |

The driver uses Hesabe's Checkout API. Requests and responses are AES-256-CBC encrypted with your secret key and IV key; the driver handles this for you.

- Customer name and email are optional.
- `errorUrl`, when given, is used as Hesabe's `failureUrl`; otherwise failures come back to the callback route too.

Hesabe redirects the customer to the callback route with an encrypted `?data=` value and also POSTs a webhook there. The webhook is neither encrypted nor signed, so in both cases the driver takes only the payment token and verifies it with Hesabe's Transaction Enquiry API before firing an event. Refunds, authorize/capture and subscriptions are not supported yet.

Hesabe publishes [sandbox credentials](https://developer.hesabe.com/docs/guides/pre-requisites/) and [test cards](https://developer.hesabe.com/docs/support/test-cards/) for trying the flow.

### KNET

| Key | Default | Notes |
|---|---|---|
| `tranportal_id` | — (required) | From the KNET merchant portal (Merchant Process → View Terminal → Plugin). |
| `tranportal_password` | — (required) | Terminal password, from your acquiring bank / KNET support. |
| `resource_key` | — (required) | 16-character terminal resource key, from your acquiring bank / KNET support. |
| `test_mode` | `true` | `true` uses `https://www.kpaytest.com.kw`, `false` uses `https://www.kpay.com.kw`. Test and live terminals have different credentials. |

The driver uses KNET's RAW (Tranportal) integration with the hosted payment page. `charge()` makes no HTTP call: it encrypts the payment parameters (AES-128-CBC with your resource key) and returns the KNET payment page URL.

- KNET only accepts KWD, and amounts are always sent with three decimals.
- KNET's track id must be alphanumeric, so the reference is sent with every other character removed (`ORD-2026-00001` becomes `ORD202600001`, at most 40 characters). The original reference travels in `udf1` and is what you get back in `WebhookResult::$reference`. Make sure references stay unique after that clean-up.
- `metadata` keys `udf2` to `udf5` are passed through as KNET's user-defined fields, e.g. `['udf3' => '12345678']` for a KFAST customer token. Values must not contain `&`.
- `locale` `ar` shows the Arabic page; anything else shows English.

When a payment is processed, KNET's **server** POSTs an encrypted `trandata` to the callback route. The driver trusts it only if it decrypts with your resource key, and answers with the single plain-text line KNET requires, `REDIRECT=<redirects.success or redirects.failed, with payment_reference and payment_status appended>` (the app's home page if none is configured). KNET then sends the customer's browser there. If that answer is missing, malformed, or KNET cannot reach the route, KNET voids the payment. Errors come back as plain `Error` / `ErrorText` parameters, and the Cancel button on KNET's page posts those fields empty with no order details at all, so the driver adds `?reference=<your reference>` to `errorURL` (unless your `errorUrl` already has a query string) to know which payment was cancelled. Since anyone could forge these plain-text callbacks, they are only ever treated as failed or cancelled, never as paid.

Error and Cancel callbacks come through the customer's browser and are redirected to the configured pages as usual. Because successful payments are notified server to server, the callback route must be publicly reachable over HTTPS with a valid certificate, even in the test environment: use a tunnel such as ngrok when developing locally (open the app through the tunnel so the generated callback URL uses it). Ask KNET or your acquiring bank for the test card details of your terminal. Inquiry, refunds and Apple Pay are not supported yet.

## Supported gateways

| Gateway | Driver key | Status |
|---|---|---|
| MyFatoorah | `myfatoorah` | Implemented, built on the official [`myfatoorah/library`](https://packagist.org/packages/myfatoorah/library) SDK |
| KNET | `knet` | Implemented (RAW integration, hosted payment page) |
| Tap Payments | `tap` | Implemented (hosted payment page, Charges API v2) |
| Hesabe | `hesabe` | Implemented (hosted payment page, Checkout API) |
| UPayment | `upayment` | Planned |

## Testing

```bash
composer test
composer analyse
vendor/bin/pest --exclude-group=sandbox   # skip tests that call the real Tap and Hesabe sandboxes
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
