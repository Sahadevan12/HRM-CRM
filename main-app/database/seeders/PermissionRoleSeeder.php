<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionRoleSeeder extends Seeder
{
    /** Core permissions. Add-on modules seed their own via PermissionTableSeeder. */
    public const PERMISSIONS = [
        ['name' => 'manage-dashboard', 'module' => 'dashboard', 'label' => 'Manage Dashboard'],

        ['name' => 'manage-users', 'module' => 'users', 'label' => 'Manage Users'],
        ['name' => 'create-users', 'module' => 'users', 'label' => 'Create Users'],
        ['name' => 'edit-users', 'module' => 'users', 'label' => 'Edit Users'],
        ['name' => 'delete-users', 'module' => 'users', 'label' => 'Delete Users'],
        ['name' => 'change-password-users', 'module' => 'users', 'label' => 'Change Password Users'],

        ['name' => 'manage-settings', 'module' => 'settings', 'label' => 'Manage Settings'],
        ['name' => 'edit-settings', 'module' => 'settings', 'label' => 'Edit Settings'],

        ['name' => 'manage-plans', 'module' => 'plans', 'label' => 'Manage Plans'],
        ['name' => 'create-plans', 'module' => 'plans', 'label' => 'Create Plans'],
        ['name' => 'edit-plans', 'module' => 'plans', 'label' => 'Edit Plans'],
        ['name' => 'delete-plans', 'module' => 'plans', 'label' => 'Delete Plans'],
        ['name' => 'subscribe-plans', 'module' => 'plans', 'label' => 'Subscribe Plans'],

        ['name' => 'manage-coupons', 'module' => 'coupons', 'label' => 'Manage Coupons'],
        ['name' => 'create-coupons', 'module' => 'coupons', 'label' => 'Create Coupons'],
        ['name' => 'edit-coupons', 'module' => 'coupons', 'label' => 'Edit Coupons'],
        ['name' => 'delete-coupons', 'module' => 'coupons', 'label' => 'Delete Coupons'],

        ['name' => 'manage-orders', 'module' => 'orders', 'label' => 'Manage Orders'],
        ['name' => 'manage-bank-transfers', 'module' => 'bank-transfers', 'label' => 'Manage Bank Transfers'],
        ['name' => 'manage-add-ons', 'module' => 'add-ons', 'label' => 'Manage Add-ons'],
        ['name' => 'edit-add-ons', 'module' => 'add-ons', 'label' => 'Edit Add-ons'],

        ['name' => 'manage-companies', 'module' => 'companies', 'label' => 'Manage Companies'],
        ['name' => 'create-companies', 'module' => 'companies', 'label' => 'Create Companies'],
        ['name' => 'edit-companies', 'module' => 'companies', 'label' => 'Edit Companies'],
        ['name' => 'delete-companies', 'module' => 'companies', 'label' => 'Delete Companies'],

        ['name' => 'manage-roles', 'module' => 'roles', 'label' => 'Manage Roles'],
        ['name' => 'create-roles', 'module' => 'roles', 'label' => 'Create Roles'],
        ['name' => 'edit-roles', 'module' => 'roles', 'label' => 'Edit Roles'],
        ['name' => 'delete-roles', 'module' => 'roles', 'label' => 'Delete Roles'],
    ];

    /** Platform-level permissions: only the superadmin holds them (companies never get them). */
    public const ADMIN_ONLY = [
        'create-plans', 'edit-plans', 'delete-plans',
        'manage-coupons', 'create-coupons', 'edit-coupons', 'delete-coupons',
        'manage-bank-transfers', 'manage-add-ons', 'edit-add-ons',
        'manage-companies', 'create-companies', 'edit-companies', 'delete-companies',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                ['module' => $perm['module'], 'label' => $perm['label']]
            );
        }

        // System roles (created_by = null => shared by every tenant)
        $roles = [
            'superadmin' => 'Super Admin',
            'company' => 'Company',
            'staff' => 'Staff',
            'client' => 'Client',
            'vendor' => 'Vendor',
        ];

        foreach ($roles as $name => $label) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['label' => $label]);
        }

        // give/revoke ONLY core permissions: add-on modules grant their own to the company role (package:seed),
        // and re-running this seeder must never wipe those.
        $all = array_column(self::PERMISSIONS, 'name');
        Role::findByName('superadmin')->givePermissionTo($all);
        Role::findByName('company')->givePermissionTo(array_diff($all, self::ADMIN_ONLY));
        Role::findByName('company')->revokePermissionTo(self::ADMIN_ONLY);
        Role::findByName('staff')->givePermissionTo('manage-dashboard');

        $this->seedAccounts();
    }

    /**
     * The first logins.
     *  - local / testing:  superadmin@example.com and a demo company, both with the password "password" (a convenience, never a secret)
     *  - production:       ONLY the super admin; the password is SEED_PASSWORD from .env, or a random one that is printed ONCE
     *                      (and the e-mail can be set with SEED_ADMIN_EMAIL). There is no demo company.
     * Accounts that already exist are left alone, so re-running the seeder never resets a password.
     */
    private function seedAccounts(): void
    {
        $production = app()->environment('production');
        $generated = false;

        $password = config('app.seed_password');
        if (!$password) {
            $password = $production ? Str::password(20, symbols: false) : 'password';
            $generated = $production;
        }

        $superAdmin = User::firstOrCreate(
            ['email' => config('app.seed_admin_email') ?: 'superadmin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'type' => 'superadmin',
                'total_user' => -1,
            ]
        );
        $superAdmin->syncRoles(['superadmin']);

        if ($production) {
            if ($superAdmin->wasRecentlyCreated && $generated) {
                $this->command?->warn("Super admin created: {$superAdmin->email} / {$password}  (shown once - change it after the first login)");
            }

            return;
        }

        $company = User::firstOrCreate(
            ['email' => 'company@example.com'],
            [
                'name' => 'Demo Company',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'type' => 'company',
                'total_user' => 10,
                'creator_id' => $superAdmin->id,
                'created_by' => $superAdmin->id,
            ]
        );
        $company->syncRoles(['company']);
    }
}
