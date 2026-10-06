<?php

namespace Workdo\Notes\Database\Seeders;

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
            ['name' => 'manage-notes', 'module' => 'notes', 'label' => 'Manage Notes'],
            ['name' => 'create-notes', 'module' => 'notes', 'label' => 'Create Notes'],
            ['name' => 'edit-notes', 'module' => 'notes', 'label' => 'Edit Notes'],
            ['name' => 'delete-notes', 'module' => 'notes', 'label' => 'Delete Notes'],
            ['name' => 'manage-notebooks', 'module' => 'notebooks', 'label' => 'Manage Notebooks'],
            ['name' => 'create-notebooks', 'module' => 'notebooks', 'label' => 'Create Notebooks'],
            ['name' => 'edit-notebooks', 'module' => 'notebooks', 'label' => 'Edit Notebooks'],
            ['name' => 'delete-notebooks', 'module' => 'notebooks', 'label' => 'Delete Notebooks'],
            // <permissions>
        ];

        $company = Role::where('name', 'company')->first();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                ['module' => $perm['module'], 'label' => $perm['label'], 'add_on' => 'Notes']
            );
        }

        // ONE call for all of them: givePermissionTo flushes spatie's permission cache every time,
        // so granting one by one gets very slow as the number of permissions grows
        $company?->givePermissionTo(array_column($permissions, 'name'));
    }
}
