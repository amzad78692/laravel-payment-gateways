<?php

namespace Amzad\PaymentGateways\Contracts;

use Amzad\PaymentGateways\Data\WebhookResult;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For gateways whose provider expects a specific answer from the callback route, e.g. KNET's
 * server notification, which must be answered with a plain-text `REDIRECT=<url>` line.
 */
interface CustomizesCallbackResponse
{
    /**
     * Build the response to a handled callback, or return null for the default behaviour
     * (redirect GET callbacks to the configured page, answer everything else with JSON).
     *
     * @param  string|null  $redirectUrl  The configured success / failed page with the payment
     *                                    reference and status appended, or null when none is configured.
     */
    public function callbackResponse(Request $request, WebhookResult $result, ?string $redirectUrl): ?Response;
}
