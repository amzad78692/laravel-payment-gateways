<?php

namespace Amzad\PaymentGateways\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Whether the payment can no longer change state.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
