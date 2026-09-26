<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    | The key of an entry in "gateways" below, used when you call
    | PaymentGateways::charge() without picking a gateway explicitly.
    |
    */
    'default' => env('PAYMENT_GATEWAY'),

    /*
    |--------------------------------------------------------------------------
    | Gateways
    |--------------------------------------------------------------------------
    |
    | Each entry is a configured gateway. "driver" selects the implementation
    | (defaults to the entry's key) and the remaining keys are passed to it.
    | You can define several entries for the same driver, e.g. live and test.
    |
    | 'gateways' => [
    |     'fake' => ['driver' => 'fake'],
    | ],
    |
    */
    'gateways' => [
        // 'myfatoorah' => [
        //     'driver' => 'myfatoorah',
        //     'api_key' => env('MYFATOORAH_API_KEY'),
        //     'vc_code' => env('MYFATOORAH_VC_CODE', 'KWT'),
        //     'test_mode' => env('MYFATOORAH_TEST_MODE', true),
        // ],
        //
        // 'tap' => [
        //     'driver' => 'tap',
        //     'secret_key' => env('TAP_SECRET_KEY'), // sk_test_... or sk_live_...
        //     'source' => env('TAP_SOURCE', 'src_all'), // src_all | src_card | src_kw.knet
        //     'phone_country_code' => env('TAP_PHONE_COUNTRY_CODE', '965'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Callback routes
    |--------------------------------------------------------------------------
    |
    | One route handles both customer redirects and webhooks:
    | {prefix}/{gateway}/callback (route name: payment-gateways.callback).
    | The "api" middleware group is used by default so webhooks are not
    | subject to CSRF verification.
    |
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'payment-gateways',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer redirects
    |--------------------------------------------------------------------------
    |
    | Where to send customers after they return from a gateway's payment page
    | (GET callbacks). Leave null to respond with JSON instead.
    |
    */
    'redirects' => [
        'success' => null,
        'failed' => null,
    ],

];
