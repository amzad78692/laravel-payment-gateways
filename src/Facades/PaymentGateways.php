<?php

namespace Amzad\PaymentGateways\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Amzad\PaymentGateways\PaymentGateways
 */
class PaymentGateways extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Amzad\PaymentGateways\PaymentGateways::class;
    }
}
