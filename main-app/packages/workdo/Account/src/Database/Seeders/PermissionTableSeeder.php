<?php

namespace Workdo\Account\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionTableSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            ['name' => 'manage-chart-of-accounts', 'module' => 'chart-of-accounts', 'label' => 'Manage Chart Of Accounts'],
            ['name' => 'create-chart-of-accounts', 'module' => 'chart-of-accounts', 'label' => 'Create Chart Of Accounts'],
            ['name' => 'edit-chart-of-accounts', 'module' => 'chart-of-accounts', 'label' => 'Edit Chart Of Accounts'],
            ['name' => 'delete-chart-of-accounts', 'module' => 'chart-of-accounts', 'label' => 'Delete Chart Of Accounts'],
            ['name' => 'manage-journal-entries', 'module' => 'journal-entries', 'label' => 'Manage Journal Entries'],
            ['name' => 'create-journal-entries', 'module' => 'journal-entries', 'label' => 'Create Journal Entries'],
            ['name' => 'delete-journal-entries', 'module' => 'journal-entries', 'label' => 'Delete Journal Entries'],
            ['name' => 'manage-customer-payments', 'module' => 'customer-payments', 'label' => 'Manage Customer Payments'],
            ['name' => 'create-customer-payments', 'module' => 'customer-payments', 'label' => 'Create Customer Payments'],
            ['name' => 'delete-customer-payments', 'module' => 'customer-payments', 'label' => 'Delete Customer Payments'],
            ['name' => 'manage-vendor-payments', 'module' => 'vendor-payments', 'label' => 'Manage Vendor Payments'],
            ['name' => 'create-vendor-payments', 'module' => 'vendor-payments', 'label' => 'Create Vendor Payments'],
            ['name' => 'delete-vendor-payments', 'module' => 'vendor-payments', 'label' => 'Delete Vendor Payments'],
            ['name' => 'manage-account-reports', 'module' => 'account-reports', 'label' => 'View Accounting Reports'],
            // <permissions>
        ];

        $company = Role::where('name', 'company')->first();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                ['module' => $perm['module'], 'label' => $perm['label'], 'add_on' => 'Account']
            );
        }

        // ONE call for all of them: givePermissionTo flushes spatie's permission cache every time,
        // so granting one by one gets very slow as the number of permissions grows
        $company?->givePermissionTo(array_column($permissions, 'name'));
    }
}
