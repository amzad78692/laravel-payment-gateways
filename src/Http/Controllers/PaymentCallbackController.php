<?php

namespace Amzad\PaymentGateways\Http\Controllers;

use Amzad\PaymentGateways\Enums\PaymentStatus;
use Amzad\PaymentGateways\Events\PaymentFailed;
use Amzad\PaymentGateways\Events\PaymentSucceeded;
use Amzad\PaymentGateways\Exceptions\GatewayNotFoundException;
use Amzad\PaymentGateways\Exceptions\InvalidWebhookException;
use Amzad\PaymentGateways\PaymentGateways;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Single entry point for both customer redirects (GET) and server-to-server webhooks (POST).
 */
final class PaymentCallbackController
{
    public function __invoke(Request $request, PaymentGateways $gateways, string $gateway): JsonResponse|RedirectResponse
    {
        try {
            $result = $gateways->gateway($gateway)->handleCallback($request);
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

        // Customers returning from the provider's page are sent back into the app.
        if ($request->isMethod('GET')) {
            $target = config('payment-gateways.redirects.'.($result->succeeded() ? 'success' : 'failed'));

            if (!empty($target)) {
                $query = http_build_query([
                    'payment_reference' => $result->reference,
                    'payment_status' => $result->status->value,
                ]);

                return redirect()->to($target.(str_contains($target, '?') ? '&' : '?').$query);
            }
        }

        return response()->json([
            'reference' => $result->reference,
            'status' => $result->status->value,
        ]);
    }
}
