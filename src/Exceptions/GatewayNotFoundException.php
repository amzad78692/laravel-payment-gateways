<?php

namespace Amzad\PaymentGateways\Exceptions;

class GatewayNotFoundException extends PaymentGatewayException
{
    public static function named(string $name): self
    {
        return new self("Payment gateway [{$name}] is not configured.");
    }

    public static function driver(string $driver, string $name): self
    {
        return new self("Driver [{$driver}] for payment gateway [{$name}] is not registered.");
    }
}
