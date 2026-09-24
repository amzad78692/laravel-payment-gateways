<?php

namespace Amzad\PaymentGateways\Data;

/**
 * Provider-agnostic description of a payment to be created.
 */
final readonly class PaymentRequestData
{
    /**
     * @param  float  $amount  Amount in major units (e.g. 10.500 KWD).
     * @param  string  $reference  Your own unique order / invoice reference.
     * @param  array<string, mixed>  $metadata  Free-form data some gateways echo back.
     */
    public function __construct(
        public float $amount,
        public string $reference,
        public string $currency = 'KWD',
        public ?string $description = null,
        public ?string $customerName = null,
        public ?string $customerEmail = null,
        public ?string $customerPhone = null,
        public ?string $callbackUrl = null,
        public ?string $errorUrl = null,
        public string $locale = 'en',
        public array $metadata = [],
    ) {}
}
