<?php

namespace Amzad\PaymentGateways\Tests;

use Amzad\PaymentGateways\PaymentGatewaysServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            PaymentGatewaysServiceProvider::class,
        ];
    }
}
