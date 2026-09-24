<?php

namespace Amzad\PaymentGateways\Exceptions;

class InvalidGatewayConfigurationException extends PaymentGatewayException
{
    public static function missingKey(string $gateway, string $key): self
    {
        return new self("Payment gateway [{$gateway}] is missing required config key [{$key}].");
    }
}
