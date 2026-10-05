<?php

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Events\PaymentFailed;
use Amzad\PaymentGateways\Events\PaymentSucceeded;
use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;
use Amzad\PaymentGateways\Facades\PaymentGateways;
use Amzad\PaymentGateways\Gateways\KnetGateway;
use Amzad\PaymentGateways\Support\AesCrypt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

// Dummy terminal for offline tests; the sandbox test reads real ones from the environment.
const KNET_TEST_CONFIG = [
    'tranportal_id' => '123456',
    'tranportal_password' => 'secret12',
    'resource_key' => 'ABCDEFGH12345678',
];

function knetGateway(array $config = []): KnetGateway
{
    return new KnetGateway('knet', $config + KNET_TEST_CONFIG);
}

function knetCrypt(): AesCrypt
{
    return new AesCrypt(KNET_TEST_CONFIG['resource_key'], KNET_TEST_CONFIG['resource_key'], 'AES-128-CBC');
}

function knetRequest(array $overrides = []): PaymentRequestData
{
    return new PaymentRequestData(...$overrides + [
        'amount' => 10.5,
        'reference' => 'ORD-2026-00001',
        'callbackUrl' => 'https://shop.test/payment-gateways/knet/callback',
    ]);
}

/**
 * The query parameters of the redirect URL, plus the decrypted trandata.
 *
 * @return array{0: array<string, string>, 1: array<string, string>}
 */
function knetRedirectParams(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    parse_str(knetCrypt()->decrypt($query['trandata']), $plain);

    return [$query, $plain];
}

/**
 * An encrypted trandata, as KNET posts it to responseURL.
 */
function knetTrandata(array $overrides = []): string
{
    return knetCrypt()->encrypt(http_build_query($overrides + [
        'paymentid' => '100202627512345678',
        'result' => 'CAPTURED',
        'auth' => '999554',
        'avr' => 'N',
        'ref' => '411857974849',
        'tranid' => '202627512345678',
        'postdate' => '1004',
        'trackid' => 'ORD202600001',
        'udf1' => 'ORD-2026-00001',
        'udf2' => '',
        'udf3' => '',
        'udf4' => '',
        'udf5' => '',
        'amt' => '10.500',
        'authRespCode' => '00',
    ]));
}

it('encrypts exactly like the KNET RAW PHP kit', function () {
    // encryptAES() from the RAW kit, minus its final urlencode() (a no-op on hex).
    $kitEncrypt = function (string $str, string $key): string {
        $pad = 16 - (strlen($str) % 16);
        $encrypted = openssl_encrypt($str.str_repeat(chr($pad), $pad), 'AES-128-CBC', $key, OPENSSL_ZERO_PADDING, $key);

        return bin2hex(implode(array_map('chr', unpack('C*', base64_decode($encrypted)))));
    };

    $plain = 'id=123456&password=secret12&action=1&langid=USA&currencycode=414&amt=10.500&trackid=ORD202600001';

    expect(knetCrypt()->encrypt($plain))->toBe($kitEncrypt($plain, KNET_TEST_CONFIG['resource_key']))
        ->and(knetCrypt()->decrypt($kitEncrypt($plain, KNET_TEST_CONFIG['resource_key'])))->toBe($plain);
});

it('returns the hosted payment page url with the encrypted parameters', function () {
    Http::fake();

    $response = knetGateway()->charge(knetRequest());

    [$query, $plain] = knetRedirectParams($response->redirectUrl);

    expect($response->status)->toBe(PaymentStatus::Pending)
        ->and($response->requiresRedirect())->toBeTrue()
        ->and($response->reference)->toBe('ORD-2026-00001')
        ->and($response->redirectUrl)->toStartWith('https://www.kpaytest.com.kw/kpg/PaymentHTTP.htm?param=paymentInit&trandata=')
        ->and($query['tranportalId'])->toBe('123456')
        ->and($query['responseURL'])->toBe('https://shop.test/payment-gateways/knet/callback')
        ->and($query['errorURL'])->toBe('https://shop.test/payment-gateways/knet/callback?reference=ORD-2026-00001')
        ->and($plain)->toBe([
            'id' => '123456',
            'password' => 'secret12',
            'action' => '1',
            'langid' => 'USA',
            'currencycode' => '414',
            'amt' => '10.500',
            'responseURL' => 'https://shop.test/payment-gateways/knet/callback',
            'errorURL' => 'https://shop.test/payment-gateways/knet/callback?reference=ORD-2026-00001',
            'trackid' => 'ORD202600001',
            'udf1' => 'ORD-2026-00001',
        ]);

    Http::assertNothingSent();
});

it('uses the live url, Arabic page, error url and udf metadata', function () {
    $response = knetGateway(['test_mode' => false])->charge(knetRequest([
        'locale' => 'ar',
        'errorUrl' => 'https://shop.test/payment-failed',
        'metadata' => ['udf3' => '12345678', 'other' => 'ignored'],
    ]));

    [$query, $plain] = knetRedirectParams($response->redirectUrl);

    expect($response->redirectUrl)->toStartWith('https://www.kpay.com.kw/kpg/PaymentHTTP.htm?')
        ->and($plain['langid'])->toBe('AR')
        ->and($plain['errorURL'])->toBe('https://shop.test/payment-failed?reference=ORD-2026-00001')
        ->and($query['errorURL'])->toBe('https://shop.test/payment-failed?reference=ORD-2026-00001')
        ->and($plain['udf3'])->toBe('12345678')
        ->and($plain)->not->toHaveKey('other');
});

it('leaves an error url that already has a query alone', function () {
    [, $plain] = knetRedirectParams(knetGateway()->charge(knetRequest(['errorUrl' => 'https://shop.test/failed?order=1']))->redirectUrl);

    expect($plain['errorURL'])->toBe('https://shop.test/failed?order=1');
});

it('always sends the amount with three decimals', function (float $amount, string $expected) {
    [, $plain] = knetRedirectParams(knetGateway()->charge(knetRequest(['amount' => $amount]))->redirectUrl);

    expect($plain['amt'])->toBe($expected);
})->with([
    [10, '10.000'],
    [15.75, '15.750'],
    [0.1234, '0.123'],
]);

it('truncates the track id to 40 characters', function () {
    [, $plain] = knetRedirectParams(knetGateway()->charge(knetRequest(['reference' => str_repeat('a-', 30)]))->redirectUrl);

    expect($plain['trackid'])->toBe(str_repeat('a', 30))
        ->and(strlen(knetGateway()->charge(knetRequest(['reference' => str_repeat('ab', 30)]))->payload['trackid']))->toBe(40);
});

it('rejects currencies other than KWD', function () {
    knetGateway()->charge(knetRequest(['currency' => 'USD']));
})->throws(PaymentGatewayException::class, 'KNET only supports KWD');

it('rejects a reference without letters or digits', function () {
    knetGateway()->charge(knetRequest(['reference' => '---']));
})->throws(PaymentGatewayException::class, 'letters or digits');

it('rejects parameters that would break the raw query string', function () {
    knetGateway()->charge(knetRequest(['metadata' => ['udf2' => 'a&b=c']]));
})->throws(PaymentGatewayException::class, 'udf2');

it('requires the credentials', function (string $key) {
    $config = KNET_TEST_CONFIG;
    unset($config[$key]);

    (new KnetGateway('knet', $config))->charge(knetRequest());
})->with(['tranportal_id', 'tranportal_password', 'resource_key'])
    ->throws(InvalidGatewayConfigurationException::class);

it('rejects a resource key of the wrong length', function () {
    knetGateway(['resource_key' => 'short'])->charge(knetRequest());
})->throws(InvalidGatewayConfigurationException::class, 'must be 16 characters');

it('reads the encrypted transaction response', function (string $result, PaymentStatus $expected) {
    $trandata = knetTrandata(['result' => $result]);

    $result = knetGateway()->handleCallback(Request::create('/callback', 'POST', ['trandata' => $trandata]));

    expect($result->status)->toBe($expected)
        ->and($result->reference)->toBe('ORD-2026-00001')
        ->and($result->transactionId)->toBe('202627512345678')
        ->and($result->amount)->toBe(10.5)
        ->and($result->currency)->toBe('KWD')
        ->and($result->payload['trandata'])->toBe($trandata)
        ->and($result->payload['paymentid'])->toBe('100202627512345678');
})->with([
    ['CAPTURED', PaymentStatus::Paid],
    ['NOT CAPTURED', PaymentStatus::Failed],
    ['HOST TIMEOUT', PaymentStatus::Failed],
    ['CANCELED', PaymentStatus::Cancelled],
]);

it('falls back to the payment id when a cancelled payment has no transaction id', function () {
    $result = knetGateway()->handleCallback(Request::create('/callback', 'POST', [
        'trandata' => knetTrandata(['result' => 'CANCELED', 'tranid' => '']),
    ]));

    expect($result->transactionId)->toBe('100202627512345678');
});

it('rejects trandata that was not encrypted with our resource key', function () {
    $forged = (new AesCrypt('ZZZZZZZZ99999999', 'ZZZZZZZZ99999999', 'AES-128-CBC'))
        ->encrypt('result=CAPTURED&udf1=ORD-2026-00001&amt=10.500');

    knetGateway()->handleCallback(Request::create('/callback', 'POST', ['trandata' => $forged]));
})->throws(InvalidWebhookException::class, 'invalid trandata');

it('rejects trandata that is not hex', function () {
    knetGateway()->handleCallback(Request::create('/callback', 'POST', ['trandata' => 'result=CAPTURED']));
})->throws(InvalidWebhookException::class, 'invalid trandata');

it('rejects an unsupported result', function () {
    knetGateway()->handleCallback(Request::create('/callback', 'POST', ['trandata' => knetTrandata(['result' => 'VOIDED'])]));
})->throws(InvalidWebhookException::class, 'unsupported payment status');

it('treats an error redirect as a failure', function () {
    $result = knetGateway()->handleCallback(Request::create('/callback', 'GET', [
        'Error' => 'IPAY0100055',
        'ErrorText' => 'Invalid Order Status',
        'paymentid' => '100202627512345678',
        'trackid' => 'ORD202600001',
        'udf1' => 'ORD-2026-00001',
        'amt' => '10.500',
    ]));

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->reference)->toBe('ORD-2026-00001')
        ->and($result->transactionId)->toBe('100202627512345678')
        ->and($result->amount)->toBeNull()
        ->and($result->payload['ErrorText'])->toBe('Invalid Order Status');
});

it('never trusts a plain-text success, even with an error-free trandata alongside an error', function () {
    // Error parameters are unencrypted; whatever else is sent, they only ever mean failure.
    $result = knetGateway()->handleCallback(Request::create('/callback', 'POST', [
        'trandata' => knetTrandata(),
        'Error' => 'IPAY0100055',
        'trackid' => 'ORD202600001',
        'result' => 'CAPTURED',
    ]));

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->reference)->toBe('ORD202600001');
});

it('treats the Cancel button post as a cancellation of the reference in the error url', function () {
    // Exactly what the KNET test page posts to errorURL when the customer presses Cancel.
    $result = knetGateway()->handleCallback(Request::create('/callback?reference=ORD-2026-00001', 'POST', [
        'rspcode' => '',
        'Error' => '',
        'ErrorText' => '',
    ]));

    expect($result->status)->toBe(PaymentStatus::Cancelled)
        ->and($result->reference)->toBe('ORD-2026-00001')
        ->and($result->transactionId)->toBeNull();
});

it('never marks a plain-text success as paid', function () {
    $result = knetGateway()->handleCallback(Request::create('/callback', 'POST', [
        'result' => 'CAPTURED',
        'trackid' => 'ORD202600001',
        'udf1' => 'ORD-2026-00001',
    ]));

    expect($result->status)->toBe(PaymentStatus::Cancelled);
});

it('rejects a callback without trandata or a reference', function () {
    knetGateway()->handleCallback(Request::create('/callback', 'POST', ['rspcode' => '', 'Error' => 'IPAY0100055']));
})->throws(InvalidWebhookException::class, 'missing trandata or a payment reference');

it('cancels through the package route and redirects to the failed page', function () {
    config()->set('payment-gateways.gateways.knet', ['driver' => 'knet'] + KNET_TEST_CONFIG);
    config()->set('payment-gateways.redirects', ['success' => 'https://shop.test/paid', 'failed' => 'https://shop.test/failed']);
    Event::fake([PaymentFailed::class]);

    $this->post('/payment-gateways/knet/callback?reference=ORD-2026-00001', ['rspcode' => '', 'Error' => '', 'ErrorText' => ''])
        ->assertRedirect('https://shop.test/failed?payment_reference=ORD-2026-00001&payment_status=cancelled');

    Event::assertDispatched(PaymentFailed::class, fn ($e) => $e->result->status === PaymentStatus::Cancelled);
});

it('answers the server notification with a plain REDIRECT line and fires events', function () {
    config()->set('payment-gateways.gateways.knet', ['driver' => 'knet'] + KNET_TEST_CONFIG);
    config()->set('payment-gateways.redirects', ['success' => 'https://shop.test/paid', 'failed' => '/failed']);
    Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

    // KNET voids the payment unless the answer is exactly this line: no redirect, no HTML.
    $this->post('/payment-gateways/knet/callback', ['trandata' => knetTrandata()])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertContent('REDIRECT=https://shop.test/paid?payment_reference=ORD-2026-00001&payment_status=paid');

    // Relative targets are made absolute, since KNET redirects the browser to them.
    $this->post('/payment-gateways/knet/callback', ['trandata' => knetTrandata(['result' => 'NOT CAPTURED'])])
        ->assertContent('REDIRECT=http://localhost/failed?payment_reference=ORD-2026-00001&payment_status=failed');

    Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->gateway === 'knet' && $e->result->transactionId === '202627512345678');
    Event::assertDispatched(PaymentFailed::class, fn ($e) => $e->gateway === 'knet' && $e->result->reference === 'ORD-2026-00001');
});

it('reads the server notification sent as a bare hex body', function () {
    config()->set('payment-gateways.gateways.knet', ['driver' => 'knet'] + KNET_TEST_CONFIG);
    config()->set('payment-gateways.redirects', ['success' => 'https://shop.test/paid', 'failed' => 'https://shop.test/failed']);
    Event::fake([PaymentSucceeded::class]);

    // Exactly how KNET's server posts it: uppercase hex as the whole body, labelled text/html.
    $this->call('POST', '/payment-gateways/knet/callback', server: ['CONTENT_TYPE' => 'text/html', 'HTTP_USER_AGENT' => 'Java/23'], content: strtoupper(knetTrandata()))
        ->assertOk()
        ->assertContent('REDIRECT=https://shop.test/paid?payment_reference=ORD-2026-00001&payment_status=paid');

    Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->result->reference === 'ORD-2026-00001' && $e->result->amount === 10.5);
});

it('redirects the notification to the home page when no redirect target is configured', function () {
    config()->set('payment-gateways.gateways.knet', ['driver' => 'knet'] + KNET_TEST_CONFIG);

    $this->post('/payment-gateways/knet/callback', ['trandata' => knetTrandata()])
        ->assertOk()
        ->assertContent('REDIRECT=http://localhost');
});

it('answers a browser cancel without a redirect target with JSON', function () {
    config()->set('payment-gateways.gateways.knet', ['driver' => 'knet'] + KNET_TEST_CONFIG);

    $this->post('/payment-gateways/knet/callback?reference=ORD-2026-00001', ['Error' => '', 'ErrorText' => ''])
        ->assertOk()
        ->assertJson(['reference' => 'ORD-2026-00001', 'status' => 'cancelled']);
});

it('is registered as the knet driver', function () {
    config()->set('payment-gateways.gateways.knet', ['driver' => 'knet'] + KNET_TEST_CONFIG);

    expect(PaymentGateways::gateway('knet'))->toBeInstanceOf(KnetGateway::class);
});

it('opens a real payment page in the KNET sandbox', function () {
    $config = [
        'tranportal_id' => env('KNET_TRANPORTAL_ID'),
        'tranportal_password' => env('KNET_TRANPORTAL_PASSWORD'),
        'resource_key' => env('KNET_RESOURCE_KEY'),
    ];

    if (in_array(null, $config, true)) {
        $this->markTestSkipped('Set KNET_TRANPORTAL_ID, KNET_TRANPORTAL_PASSWORD and KNET_RESOURCE_KEY to run.');
    }

    $url = (new KnetGateway('knet', $config))->charge(knetRequest(['amount' => 1.0, 'reference' => 'test'.uniqid()]))->redirectUrl;
    $response = Http::withoutRedirecting()->get($url);

    // KNET redirects a valid request to its payment page; an invalid one goes to errorURL.
    expect($response->status())->toBe(302)
        ->and($response->header('Location'))->toContain('/kpg/paymentpage.htm?PaymentID=');
})->group('sandbox');
