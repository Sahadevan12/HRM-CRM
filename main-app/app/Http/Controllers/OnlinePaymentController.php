<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Plan;
use App\Services\Payments\PaymentGateways;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * A company buys a plan online. The ORDER is created here, on the server, with the price from the plan / coupon: the browser only chooses the plan,
 * the period, the coupon and the gateway. The plan is activated only after the gateway confirmed the payment (return page / signature / webhook).
 */
class OnlinePaymentController extends Controller
{
    public function __construct(private PaymentGateways $gateways, private PlanService $plans)
    {
    }

    /** Creates the pending order and the gateway's payment; answers `{redirect}` (Stripe) or `{razorpay: {...}}` (Razorpay checkout parameters). */
    public function checkout(Request $request): JsonResponse
    {
        if (!Auth::user()->can('subscribe-plans')) {
            return response()->json(['message' => __('Permission denied')], 403);
        }

        $data = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'duration' => ['required', Rule::in(['month', 'year'])],
            'coupon_code' => 'nullable|string|max:50',
            'gateway' => ['required', Rule::in(array_keys($this->gateways->available()))],
        ]);

        $plan = Plan::findOrFail($data['plan_id']);
        if ($plan->is_disable || $plan->free_plan) {
            return response()->json(['message' => __('This plan cannot be purchased.')], 422);
        }

        $company = companyOf(Auth::user());
        $quote = $this->plans->quote($plan, $data['duration'], $data['coupon_code'] ?? null, $company);
        if (is_string($quote)) {
            return response()->json(['message' => $quote, 'errors' => ['coupon_code' => [$quote]]], 422);
        }

        $free = $quote['final_price'] <= 0; // a 100% coupon: nothing to pay
        $order = Order::create([
            'order_number' => Order::generateNumber(), 'user_id' => $company->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name, 'duration' => $data['duration'],
            'price' => $quote['price'], 'discount' => $quote['discount'], 'final_price' => $quote['final_price'], 'coupon_code' => $quote['coupon']?->code,
            'payment_type' => $free ? 'free' : $data['gateway'], 'payment_status' => 'pending',
        ]);

        if ($free) {
            $this->plans->completeOrder($order);

            return response()->json(['redirect' => route('dashboard')]);
        }

        $currency = $this->gateways->currency();
        $minor = $this->gateways->minor($order->final_price, $currency);

        try {
            if ($data['gateway'] === 'stripe') {
                $session = $this->gateways->stripe()->createSession(
                    $order, $currency, $minor, route('payments.stripe.return', ['order' => $order->order_number]) . '&session_id={CHECKOUT_SESSION_ID}',
                    route('payments.stripe.cancel', ['order' => $order->order_number]), $company->email,
                );
                $order->update(['gateway_reference' => $session['id']]);

                return response()->json(['redirect' => $session['url']]);
            }

            $razorpay = $this->gateways->razorpay();
            $remote = $razorpay->createOrder($order, $currency, $minor);
            $order->update(['gateway_reference' => $remote['id']]);

            return response()->json(['razorpay' => [
                'key' => $razorpay->keyId(), 'order_id' => $remote['id'], 'amount' => $remote['amount'], 'currency' => $remote['currency'],
                'name' => admin_setting('titleText') ?: config('app.name'), 'description' => $order->plan_name, 'order_number' => $order->order_number,
                'prefill' => ['name' => $company->name, 'email' => $company->email], 'verify_url' => route('payments.razorpay.verify'),
            ]]);
        } catch (\Throwable $e) {
            report($e);
            $order->update(['payment_status' => 'rejected']);

            return response()->json(['message' => __('The payment service could not be reached. Please try again in a moment.')], 502);
        }
    }

    /** Stripe sends the customer back here: ask Stripe (never trust the address) whether this order was paid. */
    public function stripeReturn(Request $request): RedirectResponse
    {
        $order = $this->ownOrder((string) $request->get('order'), 'stripe');

        if ($order->payment_status !== 'paid') {
            $session = $order->gateway_reference ? $this->gateways->stripe()->session($order->gateway_reference) : null;
            $currency = $this->gateways->currency();

            if ($session && $this->gateways->stripe()->isPaidFor($session, $order, $currency, $this->gateways->minor($order->final_price, $currency))) {
                $this->gateways->settle($order, (string) ($session['payment_intent'] ?? $session['id']));
            }
        }

        return $order->refresh()->payment_status === 'paid'
            ? redirect()->route('dashboard')->with('success', __('Payment received. Your plan is active.'))
            : redirect()->route('orders.index')->with('success', __('We are waiting for the payment confirmation. Your plan is activated as soon as it arrives.'));
    }

    public function stripeCancel(Request $request): RedirectResponse
    {
        $order = $this->ownOrder((string) $request->get('order'), 'stripe');

        if ($order->payment_status === 'pending') {
            $order->update(['payment_status' => 'rejected']);
        }

        return redirect()->route('plans.index')->with('error', __('The payment was cancelled.'));
    }

    /** Razorpay's checkout finished in the browser: the signature proves it came from Razorpay for THIS order. */
    public function razorpayVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_number' => 'required|string|max:40', 'razorpay_order_id' => 'required|string|max:100',
            'razorpay_payment_id' => 'required|string|max:100', 'razorpay_signature' => 'required|string|max:200',
        ]);

        $order = $this->ownOrder($data['order_number'], 'razorpay');

        if ($order->gateway_reference !== $data['razorpay_order_id'] || !$this->gateways->razorpay()->verifyPayment($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            return response()->json(['message' => __('The payment could not be verified.')], 422);
        }

        $this->gateways->settle($order, $data['razorpay_payment_id']);

        return response()->json(['redirect' => route('dashboard')]);
    }

    /** The order of the logged-in company, made for this gateway (404 for anybody else's). */
    private function ownOrder(string $number, string $gateway): Order
    {
        return Order::where('order_number', $number)->where('user_id', companyOf(Auth::user())->id)->where('payment_type', $gateway)->firstOrFail();
    }
}
