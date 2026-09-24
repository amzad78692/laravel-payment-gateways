<?php

use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\Gateways\MyFatoorahGateway;
use Illuminate\Http\Request;

function myFatoorahGateway(array $config = []): MyFatoorahGateway
{
    return new MyFatoorahGateway('myfatoorah', $config);
}

it('requires an api_key before charging', function () {
    myFatoorahGateway()->charge(new PaymentRequestData(amount: 10.5, reference: 'order-1'));
})->throws(InvalidGatewayConfigurationException::class);

it('rejects a callback with no paymentId', function () {
    myFatoorahGateway(['api_key' => 'test-key'])->handleCallback(Request::create('/callback', 'GET'));
})->throws(InvalidWebhookException::class);

it('hits the real MyFatoorah sandbox when test credentials are provided', function () {
    $apiKey = getenv('MYFATOORAH_TEST_API_KEY');

    if (! $apiKey) {
        $this->markTestSkipped('Set MYFATOORAH_TEST_API_KEY to run the MyFatoorah sandbox integration test.');
    }

    $response = myFatoorahGateway(['api_key' => $apiKey, 'test_mode' => true])
        ->charge(new PaymentRequestData(amount: 1.0, reference: 'test-'.uniqid(), callbackUrl: 'https://example.com/callback'));

    expect($response->requiresRedirect())->toBeTrue();
});
