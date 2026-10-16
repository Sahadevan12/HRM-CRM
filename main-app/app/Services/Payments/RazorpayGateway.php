<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Http;

/** Razorpay over plain HTTP: create an order, verify the signature of a finished payment, verify a webhook. */
class RazorpayGateway
{
    private const API = 'https://api.razorpay.com/v1';

    public function __construct(private array $settings)
    {
    }

    public function keyId(): string
    {
        return (string) $this->settings['razorpayKeyId'];
    }

    /** @return array{id: string, amount: int, currency: string} */
    public function createOrder(Order $order, string $currency, int $minorAmount): array
    {
        $response = Http::withBasicAuth($this->keyId(), (string) $this->settings['razorpayKeySecret'])->timeout(20)->post(self::API . '/orders', [
            'amount' => $minorAmount, 'currency' => strtoupper($currency), 'receipt' => $order->order_number, 'notes' => ['order_number' => $order->order_number],
        ]);

        if (!$response->successful() || !$response->json('id')) {
            throw new \RuntimeException('Razorpay refused the order: ' . ($response->json('error.description') ?? $response->status()));
        }

        return ['id' => $response->json('id'), 'amount' => (int) $response->json('amount'), 'currency' => (string) $response->json('currency')];
    }

    /** The browser says "paid": believe it only when the signature (made with OUR secret) matches the order and payment ids. */
    public function verifyPayment(string $orderId, string $paymentId, string $signature): bool
    {
        $secret = (string) ($this->settings['razorpayKeySecret'] ?? '');

        return $secret !== '' && hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
    }

    /** @return array<string, mixed>|null the event, or null when the signature is wrong */
    public function verifyWebhook(string $payload, ?string $signature): ?array
    {
        $secret = (string) ($this->settings['razorpayWebhookSecret'] ?? '');
        if ($secret === '' || !$signature || !hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            return null;
        }

        return json_decode($payload, true);
    }
}
