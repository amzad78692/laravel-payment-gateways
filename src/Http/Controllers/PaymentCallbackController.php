<?php

namespace Amzad\PaymentGateways\Http\Controllers;

use Amzad\PaymentGateways\Contracts\CustomizesCallbackResponse;
use Amzad\PaymentGateways\Data\WebhookResult;
use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Events\PaymentFailed;
use Amzad\PaymentGateways\Events\PaymentSucceeded;
use Amzad\PaymentGateways\Exceptions\GatewayNotFoundException;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\PaymentGateways;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single entry point for both customer redirects (GET) and server-to-server webhooks (POST).
 * Gateways implementing CustomizesCallbackResponse (e.g. KNET) decide the response themselves.
 */
final class PaymentCallbackController
{
    public function __invoke(Request $request, PaymentGateways $gateways, string $gateway): Response
    {
        try {
            $driver = $gateways->gateway($gateway);
            $result = $driver->handleCallback($request);
        } catch (GatewayNotFoundException) {
            abort(404);
        } catch (InvalidWebhookException $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        match ($result->status) {
            PaymentStatus::Paid => event(new PaymentSucceeded($gateway, $result)),
            PaymentStatus::Failed, PaymentStatus::Cancelled => event(new PaymentFailed($gateway, $result)),
            PaymentStatus::Pending => null,
        };

        $redirectUrl = $this->redirectUrl($result);

        if ($driver instanceof CustomizesCallbackResponse
            && ($response = $driver->callbackResponse($request, $result, $redirectUrl)) !== null) {
            return $response;
        }

        // Customers returning from the provider's page are sent back into the app.
        if ($request->isMethod('GET') && $redirectUrl !== null) {
            return redirect()->to($redirectUrl);
        }

        return response()->json([
            'reference' => $result->reference,
            'status' => $result->status->value,
        ]);
    }

    /**
     * The configured success / failed page with the payment reference and status appended.
     */
    private function redirectUrl(WebhookResult $result): ?string
    {
        $target = config('payment-gateways.redirects.'.($result->succeeded() ? 'success' : 'failed'));

        if (empty($target)) {
            return null;
        }

        $query = http_build_query([
            'payment_reference' => $result->reference,
            'payment_status' => $result->status->value,
        ]);

        return url($target.(str_contains($target, '?') ? '&' : '?').$query);
    }
}
