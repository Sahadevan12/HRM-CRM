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

        ['name' => 'manage-roles', 'module' => 'roles', 'label' => 'Manage Roles'],
        ['name' => 'create-roles', 'module' => 'roles', 'label' => 'Create Roles'],
        ['name' => 'edit-roles', 'module' => 'roles', 'label' => 'Edit Roles'],
        ['name' => 'delete-roles', 'module' => 'roles', 'label' => 'Delete Roles'],
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

        Role::findByName('company')->syncPermissions(Permission::whereIn('name', array_column(self::PERMISSIONS, 'name'))->get());
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
