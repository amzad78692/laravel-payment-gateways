<?php

namespace Amzad\PaymentGateways\Data;

use Amzad\PaymentGateways\Enums\PaymentStatus;
use Illuminate\Contracts\Support\Arrayable;

/**
 * Normalised result of creating a payment, identical for every gateway.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class PaymentResponseData implements Arrayable
{
    /**
     * @param  array<string, mixed>  $payload  Raw gateway response, for debugging / auditing.
     */
    public function __construct(
        public PaymentStatus $status,
        public string $reference,
        public ?string $transactionId = null,
        public ?string $redirectUrl = null,
        public array $payload = [],
    ) {}

    /**
     * Whether the customer must be sent to $redirectUrl to complete the payment.
     */
    public function requiresRedirect(): bool
    {
        return !empty($this->redirectUrl) && $this->status === PaymentStatus::Pending;
    }

    /**
     * @return array{status: string, reference: string, transactionId: ?string, redirectUrl: ?string, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'reference' => $this->reference,
            'transactionId' => $this->transactionId,
            'redirectUrl' => $this->redirectUrl,
            'payload' => $this->payload,
        ];
    }
}
