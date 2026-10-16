<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Stripe and Razorpay: the server creates the order, only the gateway's confirmation activates the plan. */
class OnlinePaymentTest extends TestCase
{
    private const STRIPE_WEBHOOK = 'whsec_testsecret123';
    private const RAZORPAY_SECRET = 'rzp-test-secret';
    private const RAZORPAY_WEBHOOK = 'rzp-webhook-secret';

    private User $admin;

    private User $a;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('type', 'superadmin')->firstOrFail();
        $this->pro = Plan::where('name', 'Pro')->firstOrFail();
        $this->pro->update(['monthly_price' => 29, 'yearly_price' => 290, 'is_disable' => false]);
        $this->a = $this->company('a@test.com');

        foreach (['currencyCode' => 'USD', 'stripeEnabled' => '1', 'stripeSecretKey' => 'sk_test_abc123', 'stripeWebhookSecret' => self::STRIPE_WEBHOOK,
            'razorpayEnabled' => '1', 'razorpayKeyId' => 'rzp_test_key1', 'razorpayKeySecret' => self::RAZORPAY_SECRET, 'razorpayWebhookSecret' => self::RAZORPAY_WEBHOOK] as $k => $v) {
            setSetting($k, $v, $this->admin->id, false);
        }
    }

    /** a company on the FREE plan that wants to buy Pro */
    private function company(string $email): User
    {
        $c = User::create(['name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company', 'email_verified_at' => now(), 'creator_id' => $this->admin->id, 'created_by' => $this->admin->id]);
        $c->assignRole('company');
        app(PlanService::class)->assign($c, Plan::where('free_plan', true)->firstOrFail());

        return $c->refresh();
    }

    private function checkout(User $as, array $extra = [])
    {
        return $this->actingAs($as)->postJson('/payments/checkout', $extra + ['plan_id' => $this->pro->id, 'duration' => 'month', 'gateway' => 'stripe']);
    }

    private function stripeOrder(User $as = null): Order
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/pay/cs_test_1'])]);
        $this->checkout($as ?? $this->a)->assertOk();

        return Order::latest('id')->firstOrFail();
    }

    private function stripeSession(Order $order, array $over = []): array
    {
        return $over + ['id' => 'cs_test_1', 'payment_status' => 'paid', 'client_reference_id' => $order->order_number, 'amount_total' => 2900, 'currency' => 'usd', 'payment_intent' => 'pi_123'];
    }

    private function signedStripe(array $event, ?int $time = null, string $secret = self::STRIPE_WEBHOOK): array
    {
        $payload = json_encode($event);
        $t = $time ?? time();

        return [$payload, ['Stripe-Signature' => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$payload}", $secret), 'Content-Type' => 'application/json']];
    }

    private function active(User $c): bool
    {
        return $c->refresh()->active_plan === $this->pro->id;
    }

    // ───────────── settings ─────────────

    public function test_the_super_admin_saves_keys_that_never_reach_a_browser(): void
    {
        $this->actingAs($this->admin)->post('/settings/gateways', [
            'stripeEnabled' => true, 'stripeSecretKey' => 'sk_live_newsecret99', 'stripeWebhookSecret' => 'whsec_new99', 'razorpayEnabled' => false,
        ])->assertSessionHas('success');

        $this->assertSame('sk_live_newsecret99', Setting::where('key', 'stripeSecretKey')->where('created_by', $this->admin->id)->value('value'));
        $this->assertFalse((bool) Setting::where('key', 'stripeSecretKey')->value('is_public'));
        $this->assertSame('0', Setting::where('key', 'razorpayEnabled')->value('value'));

        // the settings page: only the fact THAT a secret exists
        $props = $this->actingAs($this->admin)->get('/settings')->assertOk()->viewData('page')['props'];
        $this->assertArrayNotHasKey('stripeSecretKey', $props['settings']);
        $this->assertArrayNotHasKey('razorpayKeySecret', $props['settings']);
        $this->assertTrue($props['gateways']['stripe']['hasSecret']);
        $this->assertSame('rzp_test_key1', $props['gateways']['razorpay']['keyId']);
        $this->assertStringEndsWith('/payments/webhook/stripe', $props['gateways']['stripe']['webhookUrl']);

        // ... and not in the settings shared with every page of every user
        foreach ([$this->admin, $this->a] as $user) {
            $shared = $this->actingAs($user)->get('/dashboard')->viewData('page')['props']['adminAllSetting'];
            foreach (['stripeSecretKey', 'stripeWebhookSecret', 'razorpayKeySecret', 'razorpayWebhookSecret', 'razorpayKeyId'] as $secret) {
                $this->assertArrayNotHasKey($secret, $shared);
            }
            $this->assertStringNotContainsString('sk_live_newsecret99', json_encode($shared));
        }
    }

    public function test_an_empty_secret_keeps_the_saved_one_and_a_gateway_needs_its_keys_to_be_switched_on(): void
    {
        $this->actingAs($this->admin)->post('/settings/gateways', ['stripeEnabled' => true, 'stripeSecretKey' => '', 'razorpayEnabled' => true])->assertSessionHas('success');
        $this->assertSame('sk_test_abc123', Setting::where('key', 'stripeSecretKey')->value('value'));

        Setting::whereIn('key', ['stripeSecretKey', 'razorpayKeyId'])->delete();
        app('cache')->flush();
        $this->actingAs($this->admin)->post('/settings/gateways', ['stripeEnabled' => true, 'razorpayEnabled' => false])->assertSessionHasErrors('stripeSecretKey');
        $this->actingAs($this->admin)->post('/settings/gateways', ['stripeEnabled' => false, 'razorpayEnabled' => true, 'razorpayKeySecret' => 'x'])->assertSessionHasErrors('razorpayKeyId');
    }

    public function test_key_formats_and_who_may_save(): void
    {
        $this->actingAs($this->admin)->post('/settings/gateways', ['stripeEnabled' => false, 'razorpayEnabled' => false, 'stripeSecretKey' => 'not-a-key'])->assertSessionHasErrors('stripeSecretKey');
        $this->actingAs($this->admin)->post('/settings/gateways', ['stripeEnabled' => false, 'razorpayEnabled' => false, 'stripeWebhookSecret' => 'nope'])->assertSessionHasErrors('stripeWebhookSecret');
        $this->actingAs($this->admin)->post('/settings/gateways', ['stripeEnabled' => false, 'razorpayEnabled' => false, 'razorpayKeyId' => 'bad'])->assertSessionHasErrors('razorpayKeyId');

        $this->actingAs($this->a)->post('/settings/gateways', ['stripeEnabled' => false, 'razorpayEnabled' => false, 'stripeSecretKey' => 'sk_live_hack'])->assertSessionHas('error');
        $this->assertSame('sk_test_abc123', Setting::where('key', 'stripeSecretKey')->value('value'));
        $this->assertNull($this->actingAs($this->a)->get('/settings')->viewData('page')['props']['gateways']);                          // a company never sees the block
    }

    // ───────────── checkout ─────────────

    public function test_stripe_checkout_creates_a_pending_order_with_the_servers_price(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/pay/cs_test_1'])]);

        // the browser tries to dictate the price: it is simply ignored
        $this->checkout($this->a, ['price' => 1, 'final_price' => 1, 'amount' => 1])->assertOk()->assertJsonPath('redirect', 'https://checkout.stripe.com/pay/cs_test_1');

        $order = Order::firstOrFail();
        $this->assertSame(['stripe', 'pending', 29.0, $this->a->id, 'cs_test_1'], [$order->payment_type, $order->payment_status, $order->final_price, $order->user_id, $order->gateway_reference]);
        $this->assertFalse($this->active($this->a));                                                                           // paying is not paid

        Http::assertSent(function ($request) use ($order) {
            $d = $request->data();

            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions' && $request->hasHeader('Authorization')
                && $d['line_items[0][price_data][unit_amount]'] === 2900 && $d['line_items[0][price_data][currency]'] === 'usd'
                && $d['client_reference_id'] === $order->order_number && $d['mode'] === 'payment' && str_contains($d['success_url'], '{CHECKOUT_SESSION_ID}');
        });
    }

    public function test_a_coupon_lowers_the_amount_sent_to_the_gateway_and_a_full_coupon_needs_no_payment(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://x'])]);
        Coupon::create(['name' => 'Half', 'code' => 'HALF50', 'discount' => 50, 'type' => 'percentage', 'limit' => 10, 'is_active' => true, 'created_by' => $this->admin->id]);
        Coupon::create(['name' => 'Free', 'code' => 'FREE100', 'discount' => 100, 'type' => 'percentage', 'limit' => 10, 'is_active' => true, 'created_by' => $this->admin->id]);

        $this->checkout($this->a, ['coupon_code' => 'HALF50'])->assertOk();
        $this->assertSame(1450, Http::recorded()[0][0]->data()['line_items[0][price_data][unit_amount]']);
        $this->assertEquals(14.5, Order::firstOrFail()->final_price);

        $b = $this->company('b@test.com');
        $this->checkout($b, ['coupon_code' => 'FREE100'])->assertOk()->assertJsonPath('redirect', route('dashboard'));
        $this->assertTrue($this->active($b));                                                                                  // nothing to pay: active at once
        $this->assertSame('free', Order::where('user_id', $b->id)->value('payment_type'));
    }

    public function test_bad_checkout_requests_are_refused(): void
    {
        Http::fake();
        $this->checkout($this->a, ['gateway' => 'paypal'])->assertStatus(422)->assertJsonValidationErrors('gateway');
        $this->checkout($this->a, ['duration' => 'week'])->assertStatus(422);
        $this->checkout($this->a, ['plan_id' => 99999])->assertStatus(422);
        $this->checkout($this->a, ['coupon_code' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        $free = Plan::where('free_plan', true)->firstOrFail();
        $this->checkout($this->a, ['plan_id' => $free->id])->assertStatus(422);
        $this->pro->update(['is_disable' => true]);
        $this->checkout($this->a)->assertStatus(422);
        $this->pro->update(['is_disable' => false]);

        // a gateway that is switched off, or has no keys, is not offered
        setSetting('stripeEnabled', '0', $this->admin->id, false);
        $this->checkout($this->a)->assertStatus(422)->assertJsonValidationErrors('gateway');
        setSetting('stripeEnabled', '1', $this->admin->id, false);
        Setting::where('key', 'stripeSecretKey')->delete();
        app('cache')->flush();
        $this->checkout($this->a)->assertStatus(422);

        $staff = User::create(['name' => 'S', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $staff->assignRole('staff');
        $this->checkout($staff)->assertForbidden();
        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public function test_a_gateway_that_is_down_leaves_no_open_order_and_no_leak(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'sk_test_abc123 is wrong']], 401)]);

        $res = $this->checkout($this->a)->assertStatus(502);

        $this->assertStringNotContainsString('sk_test', $res->getContent());
        $this->assertSame('rejected', Order::firstOrFail()->payment_status);
        $this->assertFalse($this->active($this->a));
    }

    // ───────────── Stripe: the customer comes back ─────────────

    public function test_returning_from_stripe_activates_the_plan_only_when_stripe_says_paid(): void
    {
        $order = $this->stripeOrder();
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_1' => Http::response($this->stripeSession($order))]);

        $this->actingAs($this->a)->get('/payments/stripe/return?order=' . $order->order_number . '&session_id=cs_test_1')->assertRedirect(route('dashboard'));

        $this->assertTrue($this->active($this->a));
        $this->assertSame(['paid', 'pi_123'], [$order->refresh()->payment_status, $order->gateway_payment_id]);
        $this->assertNotNull($this->a->plan_expire_date);

        // coming back again changes nothing
        $this->actingAs($this->a)->get('/payments/stripe/return?order=' . $order->order_number)->assertRedirect(route('dashboard'));
        $this->assertSame(1, Order::where('payment_status', 'paid')->count());
    }

    public function test_an_unpaid_wrong_amount_or_foreign_session_never_activates(): void
    {
        $order = $this->stripeOrder();

        foreach ([['payment_status' => 'unpaid'], ['amount_total' => 100], ['currency' => 'eur'], ['client_reference_id' => 'ORD-OTHER']] as $bad) {
            Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_1' => Http::response($this->stripeSession($order, $bad))]);
            $this->actingAs($this->a)->get('/payments/stripe/return?order=' . $order->order_number)->assertRedirect(route('orders.index'));
            $this->assertFalse($this->active($this->a), json_encode($bad));
            $this->assertSame('pending', $order->refresh()->payment_status);
        }

        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);
        $this->actingAs($this->a)->get('/payments/stripe/return?order=' . $order->order_number)->assertRedirect(route('orders.index'));
        $this->assertFalse($this->active($this->a));
    }

    public function test_nobody_can_touch_somebody_elses_order(): void
    {
        $order = $this->stripeOrder();
        $b = $this->company('b@test.com');
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_1' => Http::response($this->stripeSession($order))]);

        $this->actingAs($b)->get('/payments/stripe/return?order=' . $order->order_number)->assertNotFound();
        $this->actingAs($b)->get('/payments/stripe/cancel?order=' . $order->order_number)->assertNotFound();
        $this->actingAs($b)->postJson('/payments/razorpay/verify', ['order_number' => $order->order_number, 'razorpay_order_id' => 'o', 'razorpay_payment_id' => 'p', 'razorpay_signature' => 's'])->assertNotFound();
        $this->assertFalse($this->active($this->a));
        $this->assertFalse($this->active($b));
    }

    public function test_cancelling_marks_the_order_and_never_a_paid_one(): void
    {
        $order = $this->stripeOrder();

        $this->actingAs($this->a)->get('/payments/stripe/cancel?order=' . $order->order_number)->assertRedirect(route('plans.index'));
        $this->assertSame('rejected', $order->refresh()->payment_status);

        $paid = $this->stripeOrder();
        $paid->update(['payment_status' => 'paid']);
        $this->actingAs($this->a)->get('/payments/stripe/cancel?order=' . $paid->order_number);
        $this->assertSame('paid', $paid->refresh()->payment_status);
    }

    // ───────────── Stripe: the webhook ─────────────

    private function stripeEvent(Order $order, array $over = [], string $type = 'checkout.session.completed'): array
    {
        return ['type' => $type, 'data' => ['object' => $this->stripeSession($order, $over)]];
    }

    public function test_a_signed_stripe_webhook_activates_the_plan_even_if_the_customer_never_came_back(): void
    {
        $order = $this->stripeOrder();
        [$payload, $headers] = $this->signedStripe($this->stripeEvent($order));

        $this->call('POST', '/payments/webhook/stripe', [], [], [], $this->serverVars($headers), $payload)->assertOk();

        $this->assertTrue($this->active($this->a));
        $this->assertSame('paid', $order->refresh()->payment_status);

        // Stripe retries: still one paid order, still ok
        $this->call('POST', '/payments/webhook/stripe', [], [], [], $this->serverVars($headers), $payload)->assertOk();
        $this->assertSame(1, Order::where('payment_status', 'paid')->count());
    }

    /** @param array<string, string> $headers */
    private function serverVars(array $headers): array
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        return $server;
    }

    public function test_forged_old_or_mismatching_stripe_webhooks_do_nothing(): void
    {
        $order = $this->stripeOrder();
        $post = fn (array $event, ?int $time = null, string $secret = self::STRIPE_WEBHOOK) => (function () use ($event, $time, $secret) {
            [$payload, $headers] = $this->signedStripe($event, $time, $secret);

            return $this->call('POST', '/payments/webhook/stripe', [], [], [], $this->serverVars($headers), $payload);
        })();

        $post($this->stripeEvent($order), null, 'whsec_wrongsecret')->assertStatus(400);                              // forged
        $post($this->stripeEvent($order), time() - 3600)->assertStatus(400);                                          // a replay of an old one
        $this->call('POST', '/payments/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->stripeEvent($order)))->assertStatus(400);   // unsigned
        $this->assertFalse($this->active($this->a));

        $post($this->stripeEvent($order, ['amount_total' => 100]))->assertOk();                                       // genuine, but not the amount we asked for
        $post($this->stripeEvent($order, ['payment_status' => 'unpaid']))->assertOk();
        $post($this->stripeEvent($order, [], 'customer.created'))->assertOk();                                        // not our business: acknowledged
        $post($this->stripeEvent($order, ['client_reference_id' => 'ORD-UNKNOWN']))->assertOk();
        $this->assertFalse($this->active($this->a));

        setSetting('stripeWebhookSecret', '', $this->admin->id, false);                                               // no secret configured: nothing is believed
        $post($this->stripeEvent($order))->assertStatus(400);
        $this->assertFalse($this->active($this->a));
    }

    // ───────────── Razorpay ─────────────

    private function razorpayOrder(?User $as = null): array
    {
        Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_RZ1', 'amount' => 2900, 'currency' => 'USD'])]);
        $res = $this->checkout($as ?? $this->a, ['gateway' => 'razorpay'])->assertOk();

        return [Order::latest('id')->firstOrFail(), $res->json('razorpay')];
    }

    public function test_razorpay_checkout_gives_the_popup_parameters_for_the_servers_amount(): void
    {
        [$order, $r] = $this->razorpayOrder();

        $this->assertSame(['razorpay', 'pending', 'order_RZ1'], [$order->payment_type, $order->payment_status, $order->gateway_reference]);
        $this->assertSame(['rzp_test_key1', 'order_RZ1', 2900, 'USD', $order->order_number], [$r['key'], $r['order_id'], $r['amount'], $r['currency'], $r['order_number']]);
        $this->assertStringNotContainsString(self::RAZORPAY_SECRET, json_encode($r));                                  // the key id is public, the secret never

        Http::assertSent(fn ($req) => $req->url() === 'https://api.razorpay.com/v1/orders' && $req['amount'] === 2900 && $req['currency'] === 'USD' && $req['receipt'] === $order->order_number);
    }

    public function test_a_razorpay_payment_with_a_valid_signature_activates_the_plan(): void
    {
        [$order, $r] = $this->razorpayOrder();
        $sign = fn (string $o, string $p, string $secret = self::RAZORPAY_SECRET) => hash_hmac('sha256', $o . '|' . $p, $secret);
        $verify = fn (array $over = []) => $this->actingAs($this->a)->postJson('/payments/razorpay/verify', $over + [
            'order_number' => $order->order_number, 'razorpay_order_id' => 'order_RZ1', 'razorpay_payment_id' => 'pay_9', 'razorpay_signature' => $sign('order_RZ1', 'pay_9'),
        ]);

        $verify(['razorpay_signature' => $sign('order_RZ1', 'pay_9', 'a-forged-secret')])->assertStatus(422);        // made with the wrong secret
        $verify(['razorpay_payment_id' => 'pay_OTHER'])->assertStatus(422);                                         // signature of another payment
        $verify(['razorpay_order_id' => 'order_OTHER', 'razorpay_signature' => $sign('order_OTHER', 'pay_9')])->assertStatus(422);   // validly signed, but not OUR order
        $verify(['razorpay_signature' => null])->assertStatus(422);
        $this->assertFalse($this->active($this->a));

        $verify()->assertOk()->assertJsonPath('redirect', route('dashboard'));
        $this->assertTrue($this->active($this->a));
        $this->assertSame(['paid', 'pay_9'], [$order->refresh()->payment_status, $order->gateway_payment_id]);

        $verify()->assertOk();                                                                                      // a second click
        $this->assertSame(1, Order::where('payment_status', 'paid')->count());
    }

    public function test_razorpay_webhooks_are_verified_and_amount_checked(): void
    {
        [$order] = $this->razorpayOrder();
        $event = fn (array $payment, string $name = 'payment.captured') => json_encode(['event' => $name, 'payload' => ['payment' => ['entity' => $payment + ['id' => 'pay_9', 'order_id' => 'order_RZ1', 'status' => 'captured', 'amount' => 2900, 'currency' => 'USD']]]]);
        $post = fn (string $body, ?string $secret = self::RAZORPAY_WEBHOOK) => $this->call('POST', '/payments/webhook/razorpay', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RAZORPAY_SIGNATURE' => $secret === null ? '' : hash_hmac('sha256', $body, $secret)], $body);

        $post($event([]), 'forged')->assertStatus(400);
        $post($event([]), null)->assertStatus(400);
        $post($event(['amount' => 100]))->assertOk();
        $post($event(['status' => 'failed']))->assertOk();
        $post($event(['currency' => 'INR']))->assertOk();
        $post($event(['order_id' => 'order_UNKNOWN']))->assertOk();
        $post($event([], 'payment.failed'))->assertOk();
        $this->assertFalse($this->active($this->a));

        $post($event([]))->assertOk();
        $this->assertTrue($this->active($this->a));
        $this->assertSame('pay_9', $order->refresh()->gateway_payment_id);
        $post($event([]))->assertOk();                                                                              // retry
        $this->assertSame(1, Order::where('payment_status', 'paid')->count());
    }

    // ───────────── amounts ─────────────

    public function test_amounts_are_sent_in_minor_units_and_zero_decimal_currencies_are_respected(): void
    {
        $gateways = app(\App\Services\Payments\PaymentGateways::class);

        $this->assertSame(2900, $gateways->minor(29.0, 'USD'));
        $this->assertSame(1450, $gateways->minor(14.5, 'usd'));
        $this->assertSame(1999, $gateways->minor(19.99, 'INR'));                                                     // no float drift
        $this->assertSame(500, $gateways->minor(500.0, 'JPY'));                                                      // no minor unit
        $this->assertSame(7, $gateways->minor(7.0, 'KRW'));
    }

    public function test_the_company_orders_page_and_plan_page_work_with_the_new_payment_types(): void
    {
        $order = $this->stripeOrder();

        $this->actingAs($this->a)->get('/orders')->assertOk();
        $this->actingAs($this->a)->get("/plans/{$this->pro->id}/subscribe")->assertOk()->assertInertia(fn ($p) => $p->where('gateways.stripe', 'Card (Stripe)')->where('gateways.razorpay', fn ($v) => str_contains($v, 'Razorpay')));
        $this->assertSame('stripe', $order->payment_type);

        setSetting('razorpayEnabled', '0', $this->admin->id, false);
        $this->actingAs($this->a)->get("/plans/{$this->pro->id}/subscribe")->assertInertia(fn ($p) => $p->has('gateways', 1));
    }
}
