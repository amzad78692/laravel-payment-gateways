<?php

namespace Amzad\PaymentGateways\Exceptions;

/**
 * Thrown when a callback cannot be trusted or understood (bad signature, unknown payment, ...).
 */
class InvalidWebhookException extends PaymentGatewayException {}
