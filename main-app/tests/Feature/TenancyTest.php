<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionRoleSeeder::class);
    }

    private function makeCompany(string $email, int $limit = 10): User
    {
        $admin = User::where('type', 'superadmin')->first();

        $company = User::create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1',
            'type' => 'company', 'total_user' => $limit, 'email_verified_at' => now(),
            'creator_id' => $admin->id, 'created_by' => $admin->id,
        ]);
        $company->assignRole('company');

        return $company;
    }

    private function makeStaff(User $company, string $email, string $role = 'staff'): User
    {
        $user = User::create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1',
            'type' => 'staff', 'email_verified_at' => now(),
            'creator_id' => $company->id, 'created_by' => $company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    public function test_company_only_sees_its_own_users(): void
    {
        $a = $this->makeCompany('a@test.com');
        $b = $this->makeCompany('b@test.com');
        $this->makeStaff($a, 'a-staff@test.com');
        $this->makeStaff($b, 'b-staff@test.com');

        $this->actingAs($a)->get('/users')
            ->assertOk()
            ->assertSee('a-staff@test.com', false)
            ->assertDontSee('b-staff@test.com', false);
    }

    public function test_company_cannot_update_or_delete_another_tenants_user(): void
    {
        $a = $this->makeCompany('a@test.com');
        $b = $this->makeCompany('b@test.com');
        $victim = $this->makeStaff($b, 'b-staff@test.com');

        $this->actingAs($a)->put("/users/{$victim->id}", [
            'name' => 'Hacked', 'email' => 'b-staff@test.com', 'type' => 'staff', 'role' => 'staff',
        ])->assertSessionHas('error');

        $this->actingAs($a)->delete("/users/{$victim->id}")->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $victim->id, 'name' => 'b-staff@test.com']);
    }

    public function test_staff_without_permission_cannot_manage_users(): void
    {
        $a = $this->makeCompany('a@test.com');
        $staff = $this->makeStaff($a, 'a-staff@test.com');

        $this->actingAs($staff)->get('/users')->assertRedirect(route('dashboard'));

        $this->actingAs($staff)->post('/users', [
            'name' => 'X', 'email' => 'x@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'role' => 'staff',
        ])->assertSessionHas('error');
        $this->assertDatabaseMissing('users', ['email' => 'x@test.com']);
    }

    public function test_company_can_create_user_and_new_user_belongs_to_its_tenant(): void
    {
        $a = $this->makeCompany('a@test.com');

        $this->actingAs($a)->post('/users', [
            'name' => 'New Staff', 'email' => 'new@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'role' => 'staff',
        ])->assertSessionHas('success');

        $new = User::where('email', 'new@test.com')->firstOrFail();
        $this->assertSame($a->id, $new->created_by);
        $this->assertTrue($new->hasRole('staff'));
    }

    public function test_user_limit_of_the_plan_is_enforced(): void
    {
        $a = $this->makeCompany('a@test.com', limit: 1);
        $this->makeStaff($a, 'first@test.com');

        $this->actingAs($a)->post('/users', [
            'name' => 'Second', 'email' => 'second@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'role' => 'staff',
        ])->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['email' => 'second@test.com']);
    }

    public function test_company_cannot_assign_another_tenants_role(): void
    {
        $a = $this->makeCompany('a@test.com');
        $b = $this->makeCompany('b@test.com');

        $this->actingAs($b)->post('/roles', ['label' => 'Secret', 'permissions' => ['manage-users']])->assertSessionHas('success');
        $bRole = Role::where('created_by', $b->id)->firstOrFail();

        $this->actingAs($a)->post('/users', [
            'name' => 'X', 'email' => 'x@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'role' => $bRole->name,
        ])->assertSessionHas('error');

        $this->actingAs($a)->put("/roles/{$bRole->id}", ['label' => 'Pwned'])->assertSessionHas('error');
        $this->assertSame('Secret', $bRole->fresh()->label);
    }

    public function test_role_cannot_grant_permissions_company_does_not_hold(): void
    {
        $a = $this->makeCompany('a@test.com');
        Role::findByName('company')->revokePermissionTo('delete-roles');

        $this->actingAs($a)->post('/roles', ['label' => 'Limited', 'permissions' => ['manage-users', 'delete-roles']]);

        $role = Role::where('created_by', $a->id)->firstOrFail();
        $this->assertTrue($role->hasPermissionTo('manage-users'));
        $this->assertFalse($role->hasPermissionTo('delete-roles'));
    }

    public function test_registration_creates_a_company_tenant(): void
    {
        $this->post('/register', [
            'name' => 'New Co', 'email' => 'newco@test.com', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'newco@test.com')->firstOrFail();
        $this->assertSame('company', $user->type);
        $this->assertTrue($user->hasRole('company'));
        $this->assertSame(User::where('type', 'superadmin')->value('id'), $user->created_by);
    }
}
