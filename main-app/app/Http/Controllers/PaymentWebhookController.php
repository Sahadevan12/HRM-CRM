<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Payments\PaymentGateways;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gateways call these addresses themselves when a payment finishes (also when the customer closed the browser). Public, no login, no CSRF:
 * the SIGNATURE is the only credential. A wrong signature is refused with 400; anything we do not care about is acknowledged with 200 so the
 * gateway stops retrying.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(private PaymentGateways $gateways)
    {
    }

    public function stripe(Request $request): JsonResponse
    {
        $event = $this->gateways->stripe()->verifyWebhook($request->getContent(), $request->header('Stripe-Signature'));
        if (!$event) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        if (in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $session = $event['data']['object'] ?? [];
            $order = Order::where('order_number', $session['client_reference_id'] ?? '')->where('payment_type', 'stripe')->first();
            $currency = $this->gateways->currency();

            if ($order && $order->payment_status !== 'paid' && $this->gateways->stripe()->isPaidFor($session, $order, $currency, $this->gateways->minor($order->final_price, $currency))) {
                $this->gateways->settle($order, (string) ($session['payment_intent'] ?? $session['id'] ?? ''));
            }
        }

        return response()->json(['ok' => true]);
    }

    public function razorpay(Request $request): JsonResponse
    {
        $event = $this->gateways->razorpay()->verifyWebhook($request->getContent(), $request->header('X-Razorpay-Signature'));
        if (!$event) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        if (in_array($event['event'] ?? '', ['payment.captured', 'order.paid'], true)) {
            $payment = $event['payload']['payment']['entity'] ?? [];
            $order = Order::where('gateway_reference', $payment['order_id'] ?? '')->where('payment_type', 'razorpay')->first();
            $currency = $this->gateways->currency();

            if ($order && $order->payment_status !== 'paid'
                && ($payment['status'] ?? null) === 'captured'
                && (int) ($payment['amount'] ?? -1) === $this->gateways->minor($order->final_price, $currency)
                && strtoupper((string) ($payment['currency'] ?? '')) === $currency) {
                $this->gateways->settle($order, (string) ($payment['id'] ?? ''));
            }
        }

        return response()->json(['ok' => true]);
    }
}
