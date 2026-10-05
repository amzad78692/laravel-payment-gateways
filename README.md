# Laravel Payment Gateways

One simple API for taking payments in Laravel through Gulf-region payment gateways.

Supported gateways:

| Gateway    | Driver name  | Currencies              |
|------------|--------------|-------------------------|
| MyFatoorah | `myfatoorah` | KWD, SAR, AED, ...      |
| Tap        | `tap`        | KWD, SAR, AED, ...      |
| Hesabe     | `hesabe`     | KWD                     |
| KNET       | `knet`       | KWD only                |
| Fake       | `fake`       | Any (for local testing) |

You write your payment code once. Switching gateways is a config change.

---

## Table of contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [How a payment works](#how-a-payment-works)
5. [Step 1: Start a payment](#step-1-start-a-payment)
6. [Step 2: Handle the result](#step-2-handle-the-result)
7. [Using more than one gateway](#using-more-than-one-gateway)
8. [Gateway setup reference](#gateway-setup-reference)
9. [Error handling](#error-handling)
10. [Testing with the fake gateway](#testing-with-the-fake-gateway)
11. [Adding your own gateway](#adding-your-own-gateway)

---

## Requirements

- PHP 8.2 or newer
- Laravel 11, 12 or 13

## Installation

Install the package with Composer:

```bash
composer require amzad/laravel-payment-gateways
```

Publish the config file:

```bash
php artisan vendor:publish --tag="payment-gateways-config"
```

This creates `config/payment-gateways.php`.

---

## Configuration

Open `config/payment-gateways.php`. Each entry under `gateways` is a gateway you can use. Uncomment the ones you need.

```php
return [
    // Gateway used when you don't pick one
    'default' => env('PAYMENT_GATEWAY'),

    'gateways' => [
        'myfatoorah' => [
            'driver' => 'myfatoorah',
            'api_key' => env('MYFATOORAH_API_KEY'),
            'vc_code' => env('MYFATOORAH_VC_CODE', 'KWT'),
            'test_mode' => env('MYFATOORAH_TEST_MODE', true),
        ],
    ],

    // The callback URL the gateways send results to
    'routes' => [
        'enabled' => true,
        'prefix' => 'payment-gateways',
        'middleware' => ['api'],
    ],

    // Where the customer lands after paying
    'redirects' => [
        'success' => '/payment/success',
        'failed' => '/payment/failed',
    ],
];
```

Then add your keys to `.env`:

```dotenv
PAYMENT_GATEWAY=myfatoorah

MYFATOORAH_API_KEY=your-api-key
MYFATOORAH_VC_CODE=KWT
MYFATOORAH_TEST_MODE=true
```

> **Tip:** Keep `test_mode` set to `true` until you are ready to go live.

---

## How a payment works

```
1. Your app       -> charge()            -> Gateway returns a payment page URL
2. Customer       -> pays on that page
3. Gateway        -> calls your callback URL  (handled by this package)
4. This package   -> verifies the payment and fires an event
5. Your listener  -> marks the order as paid
6. Customer       -> is redirected to your success / failed page
```

You only write code for **step 1** and **step 5**. The package does the rest.

---

## Step 1: Start a payment

Create a `PaymentRequestData` object and call `charge()`. Then send the customer to the payment page.

```php
use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Facades\PaymentGateways;

public function checkout(Order $order)
{
    $payment = PaymentGateways::charge(new PaymentRequestData(
        amount: 10.500,                 // in KWD (not fils)
        reference: $order->number,      // your own unique order reference
        currency: 'KWD',
        description: 'Order #'.$order->number,
        customerName: $order->customer_name,
        customerEmail: $order->customer_email,
    ));

    if ($payment->requiresRedirect()) {
        return redirect()->away($payment->redirectUrl);
    }

    return back()->with('error', 'Could not start the payment.');
}
```

### All `PaymentRequestData` options

| Option          | Required | Default | Description                                                     |
|-----------------|----------|---------|-----------------------------------------------------------------|
| `amount`        | Yes      |         | Amount in major units, for example `10.500`.                    |
| `reference`     | Yes      |         | Your own unique order or invoice reference.                     |
| `currency`      | No       | `KWD`   | ISO currency code.                                              |
| `description`   | No       | `null`  | Shown on some payment pages.                                    |
| `customerName`  | No       | `null`  | Customer's full name.                                           |
| `customerEmail` | No       | `null`  | Customer's email.                                               |
| `callbackUrl`   | No       | auto    | Override the callback URL. Leave empty to use the package route. |
| `errorUrl`      | No       | auto    | Where to send the customer on failure (MyFatoorah).             |
| `locale`        | No       | `en`    | Payment page language: `en` or `ar`.                            |
| `metadata`      | No       | `[]`    | Extra data. Some gateways send it back to you.                  |

### What `charge()` returns

A `PaymentResponseData` object:

| Property / method    | Description                                              |
|----------------------|----------------------------------------------------------|
| `status`             | A `PaymentStatus` enum. Usually `Pending` at this point. |
| `reference`          | Your reference.                                          |
| `transactionId`      | The gateway's ID, if it gives one.                       |
| `redirectUrl`        | The payment page URL.                                    |
| `payload`            | The raw gateway response (useful for logging).           |
| `requiresRedirect()` | `true` when you should send the customer to `redirectUrl`. |

---

## Step 2: Handle the result

### The callback route

The package registers one route for all gateways:

```
GET|POST  /payment-gateways/{gateway}/callback
```

Route name: `payment-gateways.callback`.

It is used automatically. You do **not** need to pass a callback URL in `PaymentRequestData`. Just make sure this URL is reachable from the internet (gateways cannot call `localhost`).

When a callback arrives, the package:

1. Verifies it with the gateway (so fake requests cannot mark an order as paid).
2. Fires an event: `PaymentSucceeded` or `PaymentFailed`.
3. Redirects the customer to your `redirects.success` or `redirects.failed` page.

### Listen to the events

This is where you update your order:

```php
use Amzad\PaymentGateways\Events\PaymentFailed;
use Amzad\PaymentGateways\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Event;

// In AppServiceProvider::boot()
Event::listen(function (PaymentSucceeded $event) {
    $order = Order::where('number', $event->result->reference)->first();

    // Gateways may call back more than once, so check first.
    if ($order && ! $order->isPaid()) {
        $order->markAsPaid($event->result->transactionId);
    }
});

Event::listen(function (PaymentFailed $event) {
    Order::where('number', $event->result->reference)
        ->update(['status' => 'failed']);
});
```

> **Important:** A gateway can notify you more than once for the same payment (for example, a customer redirect *and* a webhook). Always make your listener safe to run twice.

Each event has:

- `$event->gateway`: the gateway name, for example `myfatoorah`
- `$event->result`: a `WebhookResult` object

| `WebhookResult` property / method | Description                               |
|-----------------------------------|-------------------------------------------|
| `status`                          | A `PaymentStatus` enum.                   |
| `reference`                       | Your order reference.                     |
| `transactionId`                   | The gateway's transaction ID.             |
| `amount`                          | Amount paid, when the gateway returns it. |
| `currency`                        | Currency, when the gateway returns it.    |
| `payload`                         | Raw data from the gateway.                |
| `succeeded()`                     | `true` if the payment is paid.            |

> **Tip:** Compare `$event->result->amount` with your order total before marking it as paid.

### Payment statuses

`Amzad\PaymentGateways\Enums\PaymentStatus`:

| Status      | Value       | Event fired        |
|-------------|-------------|--------------------|
| `Pending`   | `pending`   | none               |
| `Paid`      | `paid`      | `PaymentSucceeded` |
| `Failed`    | `failed`    | `PaymentFailed`    |
| `Cancelled` | `cancelled` | `PaymentFailed`    |

### The success and failed pages

The customer is sent to your redirect page with two query parameters added:

```
/payment/success?payment_reference=ORD-1001&payment_status=paid
```

Use them to show a message:

```php
Route::get('/payment/success', function (Request $request) {
    $order = Order::where('number', $request->query('payment_reference'))->firstOrFail();

    return view('payment.success', compact('order'));
});
```

> **Note:** Do not mark the order as paid in this page. Anyone can open this URL. Use the event listener instead.

If you leave `redirects` as `null`, the callback returns JSON instead:

```json
{ "reference": "ORD-1001", "status": "paid" }
```

---

## Using more than one gateway

Add each gateway to the config, then pick one by name with `gateway()`:

```php
use Amzad\PaymentGateways\Facades\PaymentGateways;

// Uses the default gateway
PaymentGateways::charge($request);

// Uses a specific gateway
PaymentGateways::gateway('knet')->charge($request);
PaymentGateways::gateway('tap')->charge($request);
```

A nice pattern is to let the customer choose:

```php
$payment = PaymentGateways::gateway($request->input('method'))->charge($data);
```

### Same gateway, different accounts

The config key is the name; `driver` picks the gateway type. So you can have two accounts of the same gateway:

```php
'gateways' => [
    'tap_kuwait' => [
        'driver' => 'tap',
        'secret_key' => env('TAP_KW_SECRET_KEY'),
    ],
    'tap_saudi' => [
        'driver' => 'tap',
        'secret_key' => env('TAP_SA_SECRET_KEY'),
    ],
],
```

```php
PaymentGateways::gateway('tap_saudi')->charge($request);
```

The callback URL uses the config key, for example `/payment-gateways/tap_saudi/callback`.

### Without the facade

You can inject the manager instead:

```php
use Amzad\PaymentGateways\PaymentGateways;

public function checkout(PaymentGateways $gateways)
{
    $payment = $gateways->gateway('myfatoorah')->charge($data);
}
```

---

## Gateway setup reference

### MyFatoorah

```php
'myfatoorah' => [
    'driver' => 'myfatoorah',
    'api_key' => env('MYFATOORAH_API_KEY'),           // required
    'vc_code' => env('MYFATOORAH_VC_CODE', 'KWT'),    // KWT, SAU, ARE, QAT, BHR, OMN, EGY, JOD
    'test_mode' => env('MYFATOORAH_TEST_MODE', true),
],
```

- Get your API key from the MyFatoorah portal.
- Supports `errorUrl` in `PaymentRequestData` for a separate failure page.

### Tap

```php
'tap' => [
    'driver' => 'tap',
    'secret_key' => env('TAP_SECRET_KEY'),            // required: sk_test_... or sk_live_...
    'source' => env('TAP_SOURCE', 'src_all'),         // src_all, src_card or src_kw.knet
    'merchant_id' => env('TAP_MERCHANT_ID'),          // optional
],
```

- There is no `test_mode`. A `sk_test_` key means test mode; a `sk_live_` key means live.
- `source` controls which payment methods are shown:
  - `src_all`: all methods
  - `src_card`: cards only
  - `src_kw.knet`: KNET only

### Hesabe

```php
'hesabe' => [
    'driver' => 'hesabe',
    'merchant_code' => env('HESABE_MERCHANT_CODE'),   // required
    'access_code' => env('HESABE_ACCESS_CODE'),       // required
    'secret_key' => env('HESABE_SECRET_KEY'),         // required, 32 characters
    'iv_key' => env('HESABE_IV_KEY'),                 // required, 16 characters
    'test_mode' => env('HESABE_TEST_MODE', true),
    'payment_type' => env('HESABE_PAYMENT_TYPE', 0),  // 0 = customer chooses, 1 = KNET, 2 = cards
],
```

### KNET

```php
'knet' => [
    'driver' => 'knet',
    'tranportal_id' => env('KNET_TRANPORTAL_ID'),             // required
    'tranportal_password' => env('KNET_TRANPORTAL_PASSWORD'), // required
    'resource_key' => env('KNET_RESOURCE_KEY'),               // required, 16 characters
    'test_mode' => env('KNET_TEST_MODE', true),
],
```

Good to know about KNET:

- Only **KWD** is accepted. Other currencies throw an exception.
- Your `reference` must contain letters or digits. KNET removes other characters from its track ID, but you still get your original reference back in the result.
- KNET's server calls your callback URL directly and expects a special reply. The package handles this for you. Just keep the callback route publicly reachable.
- If KNET cannot reach your callback URL, it cancels the payment.

---

## Error handling

All exceptions extend `Amzad\PaymentGateways\Exceptions\PaymentGatewayException`:

| Exception                              | When                                                         |
|----------------------------------------|--------------------------------------------------------------|
| `PaymentGatewayException`              | The gateway rejected the request or could not be reached.    |
| `GatewayNotFoundException`             | The gateway name is not in the config.                       |
| `InvalidGatewayConfigurationException` | A required config key is missing, or no default is set.      |
| `InvalidWebhookException`              | A callback is invalid or cannot be verified.                 |

```php
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;

try {
    $payment = PaymentGateways::charge($data);
} catch (PaymentGatewayException $e) {
    report($e);

    return back()->with('error', 'Payment is not available right now. Please try again.');
}
```

On the callback route:

- Unknown gateway name: `404`
- Invalid callback: `400` with a JSON message

---

## Testing with the fake gateway

The `fake` gateway makes no HTTP calls. Use it in local development and in your tests.

```php
// config/payment-gateways.php
'gateways' => [
    'fake' => ['driver' => 'fake'],
],
```

```dotenv
PAYMENT_GATEWAY=fake
```

`charge()` returns a `Pending` payment with a fake redirect URL.

To simulate a callback, call the callback route with `reference` and `status` (`paid`, `failed`, `cancelled` or `pending`):

```php
use Amzad\PaymentGateways\Events\PaymentSucceeded;
use Illuminate\Support\Facades\Event;

it('marks the order as paid', function () {
    config()->set('payment-gateways.gateways.fake', ['driver' => 'fake']);

    $order = Order::factory()->create(['number' => 'ORD-1001']);

    $this->post('/payment-gateways/fake/callback', [
        'reference' => 'ORD-1001',
        'status' => 'paid',
    ])->assertOk();

    expect($order->fresh()->isPaid())->toBeTrue();
});
```

Or just check that the event was fired:

```php
Event::fake();

$this->post('/payment-gateways/fake/callback', ['reference' => 'ORD-1001', 'status' => 'paid']);

Event::assertDispatched(PaymentSucceeded::class);
```

---

## Adding your own gateway

### 1. Create the gateway class

Extend `AbstractGateway` and write two methods: `charge()` and `handleCallback()`.

```php
namespace App\Payments;

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Gateways\AbstractGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AcmePayGateway extends AbstractGateway
{
    public function charge(PaymentRequestData $request): PaymentResponseData
    {
        $response = Http::withToken($this->requireConfig('api_key'))
            ->post($this->environmentUrl('https://sandbox.acme.test', 'https://api.acme.test').'/payments', [
                'amount' => $request->amount,
                'currency' => $request->currency,
                'order_id' => $request->reference,
                'return_url' => $this->callbackUrl($request),
            ])
            ->json();

        return new PaymentResponseData(
            status: PaymentStatus::Pending,
            reference: $request->reference,
            transactionId: $response['id'],
            redirectUrl: $response['payment_url'],
            payload: $response,
        );
    }

    public function handleCallback(Request $request): WebhookResult
    {
        // Always verify the payment with the gateway's API.
        // Never trust the values in the request alone.
        $payment = Http::withToken($this->requireConfig('api_key'))
            ->get('https://api.acme.test/payments/'.$request->input('id'))
            ->json();

        return new WebhookResult(
            status: $this->mapStatus([
                'SUCCESS' => PaymentStatus::Paid,
                'FAILED' => PaymentStatus::Failed,
                'CANCELLED' => PaymentStatus::Cancelled,
            ], $payment['status']),
            reference: $payment['order_id'],
            transactionId: $payment['id'],
            amount: $payment['amount'],
            payload: $payment,
        );
    }
}
```

Useful helpers from `AbstractGateway`:

| Helper                               | What it does                                                      |
|--------------------------------------|-------------------------------------------------------------------|
| `config('key', $default)`            | Read a value from this gateway's config.                          |
| `requireConfig('key')`               | Same, but throws if the value is missing.                         |
| `callbackUrl($request)`              | The callback URL for this gateway.                                |
| `errorUrl($request)`                 | The failure URL (falls back to the callback URL).                 |
| `isTestMode()`                       | Reads `test_mode` (default `true`).                               |
| `environmentUrl($sandbox, $live)`    | Returns the sandbox or live URL.                                  |
| `mapStatus($map, $status)`           | Converts the gateway's status to a `PaymentStatus` (ignores case). |
| `splitName($fullName)`               | `"Amzad Khan"` becomes `['Amzad', 'Khan']`.                       |
| `language($request)`                 | The page language (`en` or `ar`).                                 |

### 2. Register it

In `AppServiceProvider::boot()`:

```php
use Amzad\PaymentGateways\Facades\PaymentGateways;

PaymentGateways::extend('acmepay', \App\Payments\AcmePayGateway::class);
```

### 3. Configure it

```php
'gateways' => [
    'acmepay' => [
        'driver' => 'acmepay',
        'api_key' => env('ACMEPAY_API_KEY'),
        'test_mode' => env('ACMEPAY_TEST_MODE', true),
    ],
],
```

That's it. The callback route, events and redirects work for your gateway too.

> **Advanced:** If a gateway needs a special reply on its callback (like KNET), implement `Amzad\PaymentGateways\Contracts\CustomizesCallbackResponse` and return your own response from `callbackResponse()`.

---

## Running the package tests

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
