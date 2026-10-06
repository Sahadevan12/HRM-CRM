<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Database\Seeders\PermissionRoleSeeder;
use Database\Seeders\PlanSeeder;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    private User $admin;
    private Plan $free;
    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('type', 'superadmin')->firstOrFail();
        $this->free = Plan::where('free_plan', true)->firstOrFail();
        $this->pro = Plan::where('name', 'Pro')->firstOrFail();
    }

    private function company(string $email = 'c@test.com'): User
    {
        $c = User::create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company',
            'email_verified_at' => now(), 'creator_id' => $this->admin->id, 'created_by' => $this->admin->id,
        ]);
        $c->assignRole('company');
        app(PlanService::class)->assign($c, $this->free);

        return $c->refresh();
    }

    public function test_only_superadmin_can_use_the_companies_page(): void
    {
        $company = $this->company();

        $this->actingAs($company)->get('/companies')->assertRedirect(route('dashboard'));
        $this->actingAs($company)->post('/companies', ['name' => 'X', 'email' => 'x@test.com', 'password' => 'secret-pass-1'])->assertSessionHas('error');
        $this->actingAs($company)->post("/companies/{$company->id}/toggle-login")->assertSessionHas('error');
        $this->actingAs($company)->delete("/companies/{$company->id}")->assertSessionHas('error');
        $this->assertDatabaseMissing('users', ['email' => 'x@test.com']);

        $this->actingAs($this->admin)->get('/companies')->assertOk();
    }

    public function test_list_shows_companies_with_plan_and_member_counts(): void
    {
        $a = $this->company('a@test.com');
        User::create(['name' => 's', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'creator_id' => $a->id, 'created_by' => $a->id]);

        $this->actingAs($this->admin)->get('/companies?search=a@test')
            ->assertInertia(fn ($page) => $page
                ->has('companies.data', 1)
                ->where('companies.data.0.email', 'a@test.com')
                ->where('companies.data.0.plan_name', 'Free')
                ->where('companies.data.0.members_count', 1)
                ->where('companies.data.0.expired', false));
    }

    public function test_superadmin_creates_a_company_on_the_free_plan(): void
    {
        $this->actingAs($this->admin)->post('/companies', [
            'name' => 'Acme', 'email' => 'acme@test.com', 'password' => 'secret-pass-1',
        ])->assertSessionHas('success');

        $acme = User::where('email', 'acme@test.com')->firstOrFail();
        $this->assertSame('company', $acme->type);
        $this->assertTrue($acme->hasRole('company'));
        $this->assertSame($this->admin->id, $acme->created_by);
        $this->assertSame($this->free->id, (int) $acme->active_plan);

        $this->actingAs($this->admin)->post('/companies', ['name' => 'Dup', 'email' => 'acme@test.com', 'password' => 'secret-pass-1'])
            ->assertSessionHasErrors('email');
        $this->actingAs($this->admin)->post('/companies', ['name' => 'Weak', 'email' => 'w@test.com', 'password' => '1'])
            ->assertSessionHasErrors('password');
    }

    public function test_only_company_accounts_can_be_managed_through_this_page(): void
    {
        $company = $this->company();
        $staff = User::create(['name' => 's', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'creator_id' => $company->id, 'created_by' => $company->id]);

        $this->actingAs($this->admin)->delete("/companies/{$staff->id}")->assertSessionHas('error');
        $this->actingAs($this->admin)->post("/companies/{$staff->id}/toggle-login")->assertSessionHas('error');
        $this->actingAs($this->admin)->post("/companies/{$staff->id}/plan", ['plan_id' => $this->pro->id, 'duration' => 'month'])->assertSessionHas('error');
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_assign_plan_with_durations(): void
    {
        $company = $this->company();

        $this->actingAs($this->admin)->post("/companies/{$company->id}/plan", ['plan_id' => $this->pro->id, 'duration' => 'year'])->assertSessionHas('success');
        $company->refresh();
        $this->assertSame($this->pro->id, (int) $company->active_plan);
        $this->assertSame(now()->addYear()->toDateString(), $company->plan_expire_date->toDateString());

        $this->actingAs($this->admin)->post("/companies/{$company->id}/plan", ['plan_id' => $this->pro->id, 'duration' => 'lifetime']);
        $company->refresh();
        $this->assertNull($company->plan_expire_date);
        $this->assertNull($company->trial_expire_date);

        $this->actingAs($this->admin)->post("/companies/{$company->id}/plan", ['plan_id' => $this->pro->id, 'duration' => 'trial']);
        $this->assertSame(now()->addDays(14)->toDateString(), $company->fresh()->trial_expire_date->toDateString());

        // free plan never expires, whatever duration was sent
        $this->actingAs($this->admin)->post("/companies/{$company->id}/plan", ['plan_id' => $this->free->id, 'duration' => 'month']);
        $this->assertNull($company->fresh()->plan_expire_date);
    }

    public function test_trial_is_refused_for_plans_without_trial(): void
    {
        $company = $this->company();

        $this->actingAs($this->admin)->post("/companies/{$company->id}/plan", ['plan_id' => $this->free->id, 'duration' => 'trial'])
            ->assertSessionHasErrors('duration');
        $this->actingAs($this->admin)->post("/companies/{$company->id}/plan", ['plan_id' => 9999, 'duration' => 'month'])
            ->assertSessionHasErrors('plan_id');
        $this->actingAs($this->admin)->post("/companies/{$company->id}/plan", ['plan_id' => $this->pro->id, 'duration' => 'forever'])
            ->assertSessionHasErrors('duration');
    }

    public function test_disabling_login_blocks_the_company_and_its_staff(): void
    {
        $company = $this->company();
        $staff = User::create([
            'name' => 's', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff',
            'email_verified_at' => now(), 'creator_id' => $company->id, 'created_by' => $company->id,
        ]);
        $staff->assignRole('staff');

        $this->actingAs($company)->get('/dashboard')->assertOk();
        $this->actingAs($staff)->get('/dashboard')->assertOk();

        $this->actingAs($this->admin)->post("/companies/{$company->id}/toggle-login")->assertSessionHas('success');
        $this->assertFalse($company->fresh()->is_enable_login);

        $this->actingAs($company->fresh())->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($staff)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        // and back on again
        $this->actingAs($this->admin)->post("/companies/{$company->id}/toggle-login");
        $this->actingAs($company->fresh())->get('/dashboard')->assertOk();
    }

    public function test_a_single_staff_member_can_be_blocked_without_affecting_the_company(): void
    {
        $company = $this->company();
        $staff = User::create([
            'name' => 's', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff',
            'email_verified_at' => now(), 'is_enable_login' => false, 'creator_id' => $company->id, 'created_by' => $company->id,
        ]);
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/dashboard')->assertRedirect(route('login'));
        $this->actingAs($company)->get('/dashboard')->assertOk();
    }

    public function test_update_and_delete_a_company(): void
    {
        $company = $this->company();
        $staff = User::create(['name' => 's', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'creator_id' => $company->id, 'created_by' => $company->id]);

        $this->actingAs($this->admin)->put("/companies/{$company->id}", ['name' => 'Renamed', 'email' => 'new@test.com'])->assertSessionHas('success');
        $this->assertSame('Renamed', $company->fresh()->name);

        $other = $this->company('other@test.com');
        $this->actingAs($this->admin)->put("/companies/{$company->id}", ['name' => 'X', 'email' => 'other@test.com'])->assertSessionHasErrors('email');

        $this->actingAs($this->admin)->delete("/companies/{$company->id}")->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $company->id]);
        $this->assertDatabaseMissing('users', ['id' => $staff->id]); // staff cascade with their company
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }
}
