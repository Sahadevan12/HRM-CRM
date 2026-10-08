<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The first logins: a convenient demo in local / testing, a locked-down start in production. */
class SeededAccountsTest extends TestCase
{
    private function wipeAccounts(): void
    {
        User::query()->delete();
    }

    public function test_local_gets_the_demo_accounts_with_the_demo_password(): void
    {
        $this->wipeAccounts();
        $this->app->detectEnvironment(fn () => 'local');
        (new PermissionRoleSeeder())->setContainer($this->app)->__invoke();

        $this->assertTrue(Hash::check('password', User::where('email', 'superadmin@example.com')->value('password')));
        $this->assertTrue(Hash::check('password', User::where('email', 'company@example.com')->value('password')));
    }

    public function test_production_gets_only_a_super_admin_with_a_random_password(): void
    {
        $this->wipeAccounts();
        $this->app->detectEnvironment(fn () => 'production');

        (new PermissionRoleSeeder())->setContainer($this->app)->__invoke();

        $admin = User::where('type', 'superadmin')->firstOrFail();
        $this->assertSame(1, User::count());                                                 // no demo company
        $this->assertNull(User::where('email', 'company@example.com')->first());
        $this->assertFalse(Hash::check('password', $admin->password));                      // never the well known one
        $this->assertTrue($admin->hasRole('superadmin'));
    }

    public function test_the_password_and_e_mail_can_be_chosen_in_the_environment_file(): void
    {
        $this->wipeAccounts();
        config(['app.seed_password' => 'a-long-chosen-password-1', 'app.seed_admin_email' => 'boss@my-company.test']);
        $this->app->detectEnvironment(fn () => 'production');

        (new PermissionRoleSeeder())->setContainer($this->app)->__invoke();

        $admin = User::where('email', 'boss@my-company.test')->firstOrFail();
        $this->assertTrue(Hash::check('a-long-chosen-password-1', $admin->password));
        $this->assertSame(1, User::count());
    }

    public function test_seeding_again_never_resets_an_existing_password(): void
    {
        $this->wipeAccounts();
        config(['app.seed_password' => 'first-password-123456']);
        $this->app->detectEnvironment(fn () => 'production');
        (new PermissionRoleSeeder())->setContainer($this->app)->__invoke();
        $hash = User::where('type', 'superadmin')->value('password');

        config(['app.seed_password' => 'second-password-654321']);
        (new PermissionRoleSeeder())->setContainer($this->app)->__invoke();

        $this->assertSame($hash, User::where('type', 'superadmin')->value('password'));
        $this->assertSame(1, User::count());
    }
}
