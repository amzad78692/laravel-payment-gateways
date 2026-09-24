<?php

namespace Amzad\PaymentGateways\Facades;

use Amzad\PaymentGateways\Contracts\Gateway;
use Amzad\PaymentGateways\Data\PaymentRequestData;
use Amzad\PaymentGateways\Data\PaymentResponseData;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Gateway gateway(?string $name = null)
 * @method static string getDefaultGateway()
 * @method static \Amzad\PaymentGateways\PaymentGateways extend(string $driver, Closure|string $resolver)
 * @method static \Amzad\PaymentGateways\PaymentGateways forgetGateways()
 * @method static PaymentResponseData charge(PaymentRequestData $request)
 *
 * @see \Amzad\PaymentGateways\PaymentGateways
 */
class PaymentGateways extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Amzad\PaymentGateways\PaymentGateways::class;
    }
}
