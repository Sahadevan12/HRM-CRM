<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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

        $all = array_column(self::PERMISSIONS, 'name');
        Role::findByName('superadmin')->syncPermissions(Permission::whereIn('name', $all)->get());
        Role::findByName('company')->syncPermissions(Permission::whereIn('name', array_diff($all, self::ADMIN_ONLY))->get());
        Role::findByName('staff')->syncPermissions(['manage-dashboard']);

        // Default accounts (DEV ONLY – change before production)
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'type' => 'superadmin',
                'total_user' => -1,
            ]
        );
        $superAdmin->syncRoles(['superadmin']);

        $company = User::firstOrCreate(
            ['email' => 'company@example.com'],
            [
                'name' => 'Demo Company',
                'password' => Hash::make('password'),
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
