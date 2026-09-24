<?php

namespace Amzad\PaymentGateways\Data;

use Amzad\PaymentGateways\Enums\PaymentStatus;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Normalised outcome of a gateway callback (browser redirect or server-to-server webhook).
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class WebhookResult implements Arrayable
{
    /**
     * @param  array<string, mixed>  $payload  Raw data received from / fetched from the gateway.
     */
    public function __construct(
        public PaymentStatus $status,
        public string $reference,
        public ?string $transactionId = null,
        public ?float $amount = null,
        public ?string $currency = null,
        public array $payload = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    /**
     * @return array{status: string, reference: string, transactionId: ?string, amount: ?float, currency: ?string, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reference' => $this->reference,
            'transactionId' => $this->transactionId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'payload' => $this->payload,
        ];
    }
}
