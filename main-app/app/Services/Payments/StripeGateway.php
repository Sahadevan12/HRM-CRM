<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Http;

/** Stripe Checkout over plain HTTP (no SDK): create a session, read it back, verify a webhook signature. */
class StripeGateway
{
    private const API = 'https://api.stripe.com/v1';

    /** a webhook older than this (seconds) is refused: it could be a replay */
    private const TOLERANCE = 300;

    public function __construct(private array $settings)
    {
    }

    /** @return array{id: string, url: string} */
    public function createSession(Order $order, string $currency, int $minorAmount, string $successUrl, string $cancelUrl, ?string $email): array
    {
        $response = Http::asForm()->withBasicAuth((string) $this->settings['stripeSecretKey'], '')->timeout(20)->post(self::API . '/checkout/sessions', array_filter([
            'mode' => 'payment',
            'client_reference_id' => $order->order_number,
            'customer_email' => $email,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => strtolower($currency),
            'line_items[0][price_data][unit_amount]' => $minorAmount,
            'line_items[0][price_data][product_data][name]' => $order->plan_name . ' (' . $order->duration . ')',
            'metadata[order_number]' => $order->order_number,
        ], fn ($v) => $v !== null));

        if (!$response->successful() || !$response->json('url')) {
            throw new \RuntimeException('Stripe refused the checkout session: ' . ($response->json('error.message') ?? $response->status()));
        }

        return ['id' => $response->json('id'), 'url' => $response->json('url')];
    }

    /** @return array<string, mixed>|null */
    public function session(string $id): ?array
    {
        $response = Http::withBasicAuth((string) $this->settings['stripeSecretKey'], '')->timeout(20)->get(self::API . '/checkout/sessions/' . urlencode($id));

        return $response->successful() ? $response->json() : null;
    }

    /** Was THIS order really paid, in the amount and currency we asked for? */
    public function isPaidFor(array $session, Order $order, string $currency, int $minorAmount): bool
    {
        return ($session['payment_status'] ?? null) === 'paid'
            && ($session['client_reference_id'] ?? null) === $order->order_number
            && (int) ($session['amount_total'] ?? -1) === $minorAmount
            && strtolower((string) ($session['currency'] ?? '')) === strtolower($currency);
    }

    /**
     * Stripe-Signature: t=<unix time>,v1=<hex hmac of "<t>.<payload>">  (more than one v1 may be present)
     *
     * @return array<string, mixed>|null the event, or null when the signature or the age is wrong
     */
    public function verifyWebhook(string $payload, ?string $header, ?int $now = null): ?array
    {
        $secret = (string) ($this->settings['stripeWebhookSecret'] ?? '');
        if ($secret === '' || !$header) {
            return null;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$k][] = $v;
        }

        $timestamp = (int) ($parts['t'][0] ?? 0);
        if ($timestamp === 0 || abs(($now ?? time()) - $timestamp) > self::TOLERANCE) {
            return null;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return json_decode($payload, true);
            }
        }

        return null;
    }
}
