<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Services\PlanService;
use Illuminate\Support\Facades\DB;

/**
 * Online payment of plans (Stripe Checkout, Razorpay). The platform owner switches a gateway on and enters its keys in Settings > Payment; the keys are
 * platform settings that are NOT public (never sent to a browser). A payment is only believed after the gateway itself confirmed it (a signed webhook, a
 * signature check, or asking the gateway) AND the amount / currency match the order that WE created: a customer cannot pay less for a bigger plan.
 */
class PaymentGateways
{
    public const NAMES = ['stripe' => 'Card (Stripe)', 'razorpay' => 'Razorpay (UPI, cards, netbanking)'];

    /** keys that must never leave the server */
    public const SECRET_KEYS = ['stripeSecretKey', 'stripeWebhookSecret', 'razorpayKeySecret', 'razorpayWebhookSecret'];

    public function __construct(private PlanService $plans)
    {
    }

    /** @return array<string, mixed> platform settings (the keys of the super admin) */
    public function settings(): array
    {
        return tenantSettings(superadminId());
    }

    public function isReady(string $gateway): bool
    {
        $s = $this->settings();

        return match ($gateway) {
            'stripe' => ($s['stripeEnabled'] ?? '0') === '1' && filled($s['stripeSecretKey'] ?? null),
            'razorpay' => ($s['razorpayEnabled'] ?? '0') === '1' && filled($s['razorpayKeyId'] ?? null) && filled($s['razorpayKeySecret'] ?? null),
            default => false,
        };
    }

    /** @return array<string, string> gateway => label, only the ones that are switched on AND have their keys */
    public function available(): array
    {
        return array_filter(self::NAMES, fn ($label, $key) => $this->isReady($key), ARRAY_FILTER_USE_BOTH);
    }

    /** The currency plans are sold in (Settings > Currency of the platform). */
    public function currency(): string
    {
        return strtoupper($this->settings()['currencyCode'] ?? 'USD');
    }

    /** Minor units (cents, paise). A few currencies have no minor unit. */
    public function minor(float $amount, ?string $currency = null): int
    {
        $zero = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

        return (int) round($amount * (in_array(strtoupper($currency ?? $this->currency()), $zero, true) ? 1 : 100));
    }

    /**
     * Mark an order paid exactly once, whichever of the several confirmations (return page, webhook, retry) arrives first.
     *
     * @return bool true when THIS call activated the plan
     */
    public function settle(Order $order, string $paymentId): bool
    {
        return DB::transaction(function () use ($order, $paymentId) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->payment_status === 'paid') {
                return false;
            }

            $locked->update(['gateway_payment_id' => $paymentId]);
            $this->plans->completeOrder($locked);

            return true;
        });
    }

    public function stripe(): StripeGateway
    {
        return new StripeGateway($this->settings());
    }

    public function razorpay(): RazorpayGateway
    {
        return new RazorpayGateway($this->settings());
    }
}
