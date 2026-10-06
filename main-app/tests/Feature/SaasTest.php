<?php

namespace Tests\Feature;

use App\Classes\Module;
use App\Models\AddOn;
use App\Models\BankTransferPayment;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\PlanService;
use Database\Seeders\PermissionRoleSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SaasTest extends TestCase
{
    use RefreshDatabase;

    private Plan $free;
    private Plan $pro;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionRoleSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->free = Plan::where('free_plan', true)->firstOrFail();
        $this->pro = Plan::where('name', 'Pro')->firstOrFail();
        $this->admin = User::where('type', 'superadmin')->firstOrFail();
    }

    private function company(string $email = 'c@test.com', ?Plan $plan = null, array $attrs = []): User
    {
        $company = User::create($attrs + [
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company',
            'email_verified_at' => now(), 'creator_id' => $this->admin->id, 'created_by' => $this->admin->id,
        ]);
        $company->assignRole('company');

        if ($plan) {
            app(PlanService::class)->assign($company, $plan, $plan->free_plan ? null : 'month');
        }

        return $company->refresh();
    }

    // ───────────── plan expiry middleware ─────────────

    public function test_company_without_a_plan_is_sent_to_the_plans_page(): void
    {
        $company = $this->company('c@test.com');

        $this->actingAs($company)->get('/dashboard')->assertRedirect(route('plans.index'));
        $this->actingAs($company)->get('/users')->assertRedirect(route('plans.index'));
        $this->actingAs($company)->get('/plans')->assertOk();
    }

    public function test_expired_paid_plan_is_blocked_but_active_and_free_plans_are_not(): void
    {
        $expired = $this->company('e@test.com', $this->pro);
        $expired->update(['plan_expire_date' => now()->subDay()->toDateString()]);
        $this->actingAs($expired)->get('/dashboard')->assertRedirect(route('plans.index'));

        $active = $this->company('a@test.com', $this->pro);
        $this->actingAs($active)->get('/dashboard')->assertOk();

        $free = $this->company('f@test.com', $this->free);
        $this->actingAs($free)->get('/dashboard')->assertOk();
    }

    public function test_expired_trial_is_blocked(): void
    {
        $company = $this->company('t@test.com');
        app(PlanService::class)->assign($company, $this->pro, 'trial');
        $this->actingAs($company->fresh())->get('/dashboard')->assertOk();

        $company->update(['trial_expire_date' => now()->subDay()->toDateString()]);
        $this->actingAs($company->fresh())->get('/dashboard')->assertRedirect(route('plans.index'));
    }

    public function test_sub_user_is_logged_out_when_the_company_plan_expired(): void
    {
        $company = $this->company('c@test.com', $this->pro);
        $staff = User::create([
            'name' => 's', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff',
            'email_verified_at' => now(), 'creator_id' => $company->id, 'created_by' => $company->id,
        ]);
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/dashboard')->assertOk();

        $company->update(['plan_expire_date' => now()->subDay()->toDateString()]);
        $this->actingAs($staff)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_superadmin_is_never_blocked_by_plans(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertOk();
    }

    // ───────────── module gating ─────────────

    public function test_module_route_needs_platform_flag_and_plan_grant(): void
    {
        $free = $this->company('f@test.com', $this->free);
        $this->actingAs($free)->get('/hello-module')->assertRedirect(route('dashboard'));
        $this->assertFalse(Module_is_active('Hello', $free->id));

        $pro = $this->company('p@test.com', $this->pro);
        $this->actingAs($pro)->get('/hello-module')->assertOk();
        $this->assertTrue(Module_is_active('Hello', $pro->id));

        // switched off platform-wide => nobody (except nothing) gets it, even with the plan
        (new Module())->setEnabled('Hello', false);
        $this->actingAs($pro)->get('/hello-module')->assertRedirect(route('dashboard'));
        $this->actingAs($this->admin)->get('/hello-module')->assertRedirect(route('dashboard'));

        (new Module())->setEnabled('Hello', true);
        $this->actingAs($this->admin)->get('/hello-module')->assertOk();
    }

    public function test_activated_packages_are_shared_with_the_frontend(): void
    {
        $pro = $this->company('p@test.com', $this->pro);

        // whatever modules are installed, a Pro company gets exactly the ones its plan lists
        $expected = array_values(array_unique(array_merge(PlanService::ALWAYS_ACTIVE, array_intersect((new Module())->allEnabled(), $this->pro->modules))));
        $this->assertContains('Hello', $expected);

        $this->actingAs($pro)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.activatedPackages', $expected));

        $free = $this->company('f@test.com', $this->free);
        $this->actingAs($free)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('auth.user.activatedPackages', PlanService::ALWAYS_ACTIVE));
    }

    // ───────────── assigning plans ─────────────

    public function test_assign_sets_limits_expiry_and_replaces_modules(): void
    {
        $company = $this->company('c@test.com');

        app(PlanService::class)->assign($company, $this->pro, 'year');
        $company->refresh();
        $this->assertSame($this->pro->id, (int) $company->active_plan);
        $this->assertSame(25, $company->total_user);
        $this->assertSame(now()->addYear()->toDateString(), $company->plan_expire_date->toDateString());
        $this->assertDatabaseHas('user_active_modules', ['user_id' => $company->id, 'module' => 'Hello']);

        app(PlanService::class)->assign($company, $this->free);
        $company->refresh();
        $this->assertNull($company->plan_expire_date);
        $this->assertSame(3, $company->total_user);
        $this->assertDatabaseMissing('user_active_modules', ['user_id' => $company->id, 'module' => 'Hello']);
    }

    public function test_new_registrations_start_on_the_free_plan(): void
    {
        $this->post('/register', [
            'name' => 'New Co', 'email' => 'new@test.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame($this->free->id, (int) User::where('email', 'new@test.com')->value('active_plan'));
    }

    // ───────────── coupons ─────────────

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create($attrs + [
            'name' => 'Promo', 'code' => 'PROMO10', 'type' => 'percentage', 'discount' => 10,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);
    }

    public function test_coupon_discounts_and_validation_rules(): void
    {
        $company = $this->company('c@test.com', $this->free);
        $svc = app(PlanService::class);

        $this->coupon();
        $quote = $svc->quote($this->pro, 'month', 'PROMO10', $company);
        $this->assertSame(29.0, $quote['price']);
        $this->assertSame(2.9, $quote['discount']);
        $this->assertSame(26.1, $quote['final_price']);

        // flat discount never exceeds the price
        $this->coupon(['code' => 'BIG', 'type' => 'flat', 'discount' => 500]);
        $this->assertSame(0.0, $svc->quote($this->pro, 'month', 'BIG', $company)['final_price']);

        $this->assertIsString($svc->quote($this->pro, 'month', 'NOPE', $company));
        $this->coupon(['code' => 'OLD', 'expiry_date' => now()->subDay()->toDateString()]);
        $this->assertIsString($svc->quote($this->pro, 'month', 'OLD', $company));
        $this->coupon(['code' => 'OFF', 'is_active' => false]);
        $this->assertIsString($svc->quote($this->pro, 'month', 'OFF', $company));

        $limited = $this->coupon(['code' => 'ONE', 'usage_limit' => 1]);
        UserCoupon::create(['user_id' => $this->company('other@test.com')->id, 'coupon_id' => $limited->id]);
        $this->assertIsString($svc->quote($this->pro, 'month', 'ONE', $company));

        $mine = $this->coupon(['code' => 'MINE']);
        UserCoupon::create(['user_id' => $company->id, 'coupon_id' => $mine->id]);
        $this->assertIsString($svc->quote($this->pro, 'month', 'MINE', $company));
    }

    public function test_apply_coupon_endpoint(): void
    {
        $company = $this->company('c@test.com', $this->free);
        $this->coupon();

        $this->actingAs($company)->postJson('/plans/apply-coupon', ['plan_id' => $this->pro->id, 'duration' => 'month', 'coupon_code' => 'PROMO10'])
            ->assertOk()->assertJson(['price' => 29, 'discount' => 2.9, 'final_price' => 26.1]);

        $this->actingAs($company)->postJson('/plans/apply-coupon', ['plan_id' => $this->pro->id, 'duration' => 'month', 'coupon_code' => 'BAD'])
            ->assertStatus(422)->assertJsonStructure(['error']);
    }

    // ───────────── bank transfer flow ─────────────

    private function submitTransfer(User $company, array $extra = [])
    {
        return $this->actingAs($company)->post('/bank-transfers', $extra + [
            'plan_id' => $this->pro->id, 'duration' => 'month', 'notes' => 'ref 123',
            'attachment' => UploadedFile::fake()->image('proof.png'),
        ]);
    }

    public function test_bank_transfer_is_approved_by_superadmin_and_activates_the_plan(): void
    {
        Storage::fake('local');
        $company = $this->company('c@test.com', $this->free);
        $coupon = $this->coupon();

        $this->submitTransfer($company, ['coupon_code' => 'PROMO10'])->assertRedirect(route('orders.index'));

        $order = Order::where('user_id', $company->id)->firstOrFail();
        $payment = BankTransferPayment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame(26.1, $order->final_price);
        $this->assertSame('pending', $payment->status);
        Storage::disk('local')->assertExists($payment->attachment);
        // still on the free plan, coupon not burnt yet
        $this->assertSame($this->free->id, (int) $company->fresh()->active_plan);
        $this->assertDatabaseMissing('user_coupons', ['coupon_id' => $coupon->id]);

        // a second pending transfer is refused
        $this->submitTransfer($company)->assertSessionHas('error');
        $this->assertSame(1, Order::where('user_id', $company->id)->count());

        // company cannot approve its own payment
        $this->actingAs($company)->post("/bank-transfers/{$payment->id}/approve")->assertSessionHas('error');
        $this->assertSame('pending', $payment->fresh()->status);

        $this->actingAs($this->admin)->post("/bank-transfers/{$payment->id}/approve")->assertSessionHas('success');

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('approved', $payment->fresh()->status);
        $company->refresh();
        $this->assertSame($this->pro->id, (int) $company->active_plan);
        $this->assertSame(now()->addMonth()->toDateString(), $company->plan_expire_date->toDateString());
        $this->assertDatabaseHas('user_coupons', ['coupon_id' => $coupon->id, 'user_id' => $company->id]);

        // approving twice is a no-op
        $this->actingAs($this->admin)->post("/bank-transfers/{$payment->id}/approve")->assertSessionHas('error');
    }

    public function test_rejected_bank_transfer_keeps_the_old_plan(): void
    {
        Storage::fake('local');
        $company = $this->company('c@test.com', $this->free);
        $this->submitTransfer($company);
        $payment = BankTransferPayment::firstOrFail();

        $this->actingAs($this->admin)->post("/bank-transfers/{$payment->id}/reject", ['response_note' => 'Amount mismatch'])
            ->assertSessionHas('success');

        $this->assertSame('rejected', $payment->fresh()->status);
        $this->assertSame('rejected', $payment->order->fresh()->payment_status);
        $this->assertSame($this->free->id, (int) $company->fresh()->active_plan);

        // after a rejection the company may submit again
        $this->submitTransfer($company)->assertRedirect(route('orders.index'));
    }

    public function test_free_and_disabled_plans_cannot_be_bought(): void
    {
        Storage::fake('local');
        $company = $this->company('c@test.com', $this->free);

        $this->submitTransfer($company, ['plan_id' => $this->free->id])->assertSessionHas('error');

        $this->pro->update(['is_disable' => true]);
        $this->submitTransfer($company)->assertSessionHas('error');

        $this->assertSame(0, Order::count());
    }

    public function test_payment_proof_is_only_downloadable_by_superadmin(): void
    {
        Storage::fake('local');
        $company = $this->company('c@test.com', $this->free);
        $this->submitTransfer($company);
        $payment = BankTransferPayment::firstOrFail();

        $this->actingAs($this->admin)->get("/bank-transfers/{$payment->id}/attachment")->assertOk();
        $this->actingAs($company)->get("/bank-transfers/{$payment->id}/attachment")->assertNotFound();
    }

    // ───────────── trial / free ─────────────

    public function test_trial_can_only_be_started_once(): void
    {
        $company = $this->company('c@test.com', $this->free);

        $this->actingAs($company)->post("/plans/{$this->pro->id}/start-trial")->assertSessionHas('success');
        $company->refresh();
        $this->assertSame($this->pro->id, (int) $company->active_plan);
        $this->assertSame(now()->addDays(14)->toDateString(), $company->trial_expire_date->toDateString());

        $this->actingAs($company)->post("/plans/{$this->free->id}/assign-free")->assertSessionHas('success');
        $this->actingAs($company->fresh())->post("/plans/{$this->pro->id}/start-trial")->assertSessionHas('error');
        $this->assertSame($this->free->id, (int) $company->fresh()->active_plan);
    }

    public function test_expired_company_can_take_the_free_plan_again(): void
    {
        $company = $this->company('c@test.com', $this->pro);
        $company->update(['plan_expire_date' => now()->subDay()->toDateString()]);

        $this->actingAs($company)->post("/plans/{$this->free->id}/assign-free")->assertRedirect(route('dashboard'));
        $this->actingAs($company->fresh())->get('/dashboard')->assertOk();
    }

    // ───────────── permissions / admin CRUD ─────────────

    public function test_company_cannot_use_platform_admin_features(): void
    {
        $company = $this->company('c@test.com', $this->free);

        $this->actingAs($company)->post('/plans', ['name' => 'X', 'monthly_price' => 1, 'yearly_price' => 1, 'max_users' => 1])->assertSessionHas('error');
        $this->actingAs($company)->get('/coupons')->assertRedirect(route('dashboard'));
        $this->actingAs($company)->get('/add-ons')->assertRedirect(route('dashboard'));
        $this->actingAs($company)->get('/bank-transfers')->assertRedirect(route('dashboard'));
        $this->actingAs($company)->post('/add-ons/Hello/toggle', ['is_enable' => false])->assertSessionHas('error');

        $this->assertTrue(AddOn::where('module', 'Hello')->value('is_enable'));
        $this->assertFalse($company->can('create-plans'));
    }

    public function test_superadmin_manages_plans_and_modules_are_validated(): void
    {
        $this->actingAs($this->admin)->post('/plans', [
            'name' => 'Team', 'monthly_price' => 10, 'yearly_price' => 100, 'max_users' => 10, 'modules' => ['Hello'],
        ])->assertSessionHas('success');
        $team = Plan::where('name', 'Team')->firstOrFail();
        $this->assertSame(['Hello'], $team->modules);

        $this->actingAs($this->admin)->post('/plans', [
            'name' => 'Bad', 'monthly_price' => 10, 'yearly_price' => 100, 'max_users' => 10, 'modules' => ['NotAModule'],
        ])->assertSessionHasErrors('modules.0');

        $this->actingAs($this->admin)->post('/plans', ['name' => 'Neg', 'monthly_price' => -1, 'yearly_price' => 1, 'max_users' => 1])
            ->assertSessionHasErrors('monthly_price');

        $this->actingAs($this->admin)->delete("/plans/{$team->id}")->assertSessionHas('success');
    }

    public function test_plan_with_subscribers_cannot_be_deleted(): void
    {
        $this->company('c@test.com', $this->pro);

        $this->actingAs($this->admin)->delete("/plans/{$this->pro->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('plans', ['id' => $this->pro->id]);
    }

    public function test_superadmin_coupon_crud_and_validation(): void
    {
        $this->actingAs($this->admin)->post('/coupons', [
            'name' => 'Spring', 'code' => 'spring', 'type' => 'percentage', 'discount' => 20, 'is_active' => true,
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('coupons', ['code' => 'SPRING']);

        $this->actingAs($this->admin)->post('/coupons', ['name' => 'Dup', 'code' => 'SPRING', 'type' => 'flat', 'discount' => 5])
            ->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post('/coupons', ['name' => 'Huge', 'code' => 'HUGE', 'type' => 'percentage', 'discount' => 150])
            ->assertSessionHasErrors('discount');
    }

    public function test_superadmin_can_toggle_an_add_on(): void
    {
        $this->actingAs($this->admin)->post('/add-ons/Hello/toggle', ['is_enable' => false])->assertSessionHas('success');
        $this->assertFalse((new Module())->isEnabled('Hello'));

        $this->actingAs($this->admin)->post('/add-ons/Hello/toggle', ['is_enable' => true])->assertSessionHas('success');
        $this->assertTrue((new Module())->isEnabled('Hello'));

        $this->actingAs($this->admin)->post('/add-ons/Ghost/toggle', ['is_enable' => true])->assertSessionHas('error');
    }

    // ───────────── orders ─────────────

    public function test_orders_are_visible_only_to_their_company_and_superadmin(): void
    {
        $a = $this->company('a@test.com', $this->free);
        $b = $this->company('b@test.com', $this->free);

        $this->actingAs($a)->post("/plans/{$this->free->id}/assign-free");
        $this->actingAs($b)->post("/plans/{$this->free->id}/assign-free");
        $aOrder = Order::where('user_id', $a->id)->firstOrFail();
        $bOrder = Order::where('user_id', $b->id)->firstOrFail();

        $this->actingAs($a)->get('/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.order_number', $aOrder->order_number));

        $this->actingAs($this->admin)->get('/orders')
            ->assertInertia(fn ($page) => $page->has('orders.data', 2));

        $this->assertNotSame($aOrder->order_number, $bOrder->order_number);
    }

    // ───────────── commands ─────────────

    public function test_package_sync_registers_modules_and_keeps_switches(): void
    {
        AddOn::query()->delete();
        (new Module())->forgetCache();

        $this->artisan('package:sync')->assertSuccessful();
        $this->assertDatabaseHas('add_ons', ['module' => 'Hello', 'is_enable' => true]);

        (new Module())->setEnabled('Hello', false);
        $this->artisan('package:sync')->assertSuccessful();
        $this->assertDatabaseHas('add_ons', ['module' => 'Hello', 'is_enable' => false]);
    }
}
