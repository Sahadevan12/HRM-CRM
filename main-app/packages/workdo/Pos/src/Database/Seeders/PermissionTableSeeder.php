<?php

namespace Workdo\Pos\Database\Seeders;

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
            ['name' => 'manage-pos', 'module' => 'pos', 'label' => 'Open the POS Terminal'],
            ['name' => 'create-pos', 'module' => 'pos', 'label' => 'Make POS Sales'],
            ['name' => 'manage-pos-orders', 'module' => 'pos-orders', 'label' => 'View POS Orders'],
            ['name' => 'manage-pos-reports', 'module' => 'pos-reports', 'label' => 'View POS Reports'],
            // <permissions>
        ];

        $company = Role::where('name', 'company')->first();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                ['module' => $perm['module'], 'label' => $perm['label'], 'add_on' => 'Pos']
            );
        }

        // ONE call for all of them: givePermissionTo flushes spatie's permission cache every time,
        // so granting one by one gets very slow as the number of permissions grows
        $company?->givePermissionTo(array_column($permissions, 'name'));
    }
}
