<?php

namespace Workdo\Hrm\Database\Seeders;

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
            ['name' => 'manage-branches', 'module' => 'branches', 'label' => 'Manage Branches'],
            ['name' => 'create-branches', 'module' => 'branches', 'label' => 'Create Branches'],
            ['name' => 'edit-branches', 'module' => 'branches', 'label' => 'Edit Branches'],
            ['name' => 'delete-branches', 'module' => 'branches', 'label' => 'Delete Branches'],
            ['name' => 'manage-departments', 'module' => 'departments', 'label' => 'Manage Departments'],
            ['name' => 'create-departments', 'module' => 'departments', 'label' => 'Create Departments'],
            ['name' => 'edit-departments', 'module' => 'departments', 'label' => 'Edit Departments'],
            ['name' => 'delete-departments', 'module' => 'departments', 'label' => 'Delete Departments'],
            ['name' => 'manage-designations', 'module' => 'designations', 'label' => 'Manage Designations'],
            ['name' => 'create-designations', 'module' => 'designations', 'label' => 'Create Designations'],
            ['name' => 'edit-designations', 'module' => 'designations', 'label' => 'Edit Designations'],
            ['name' => 'delete-designations', 'module' => 'designations', 'label' => 'Delete Designations'],
            ['name' => 'manage-employee-document-types', 'module' => 'employee-document-types', 'label' => 'Manage Employee Document Types'],
            ['name' => 'create-employee-document-types', 'module' => 'employee-document-types', 'label' => 'Create Employee Document Types'],
            ['name' => 'edit-employee-document-types', 'module' => 'employee-document-types', 'label' => 'Edit Employee Document Types'],
            ['name' => 'delete-employee-document-types', 'module' => 'employee-document-types', 'label' => 'Delete Employee Document Types'],
            ['name' => 'manage-employees', 'module' => 'employees', 'label' => 'Manage Employees'],
            ['name' => 'create-employees', 'module' => 'employees', 'label' => 'Create Employees'],
            ['name' => 'edit-employees', 'module' => 'employees', 'label' => 'Edit Employees'],
            ['name' => 'delete-employees', 'module' => 'employees', 'label' => 'Delete Employees'],
            ['name' => 'manage-employee-documents', 'module' => 'employees', 'label' => 'Manage Employee Documents'],
            // <permissions>
        ];

        $company = Role::where('name', 'company')->first();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                ['module' => $perm['module'], 'label' => $perm['label'], 'add_on' => 'Hrm']
            );
        }

        // ONE call for all of them: givePermissionTo flushes spatie's permission cache every time,
        // so granting one by one gets very slow as the number of permissions grows
        $company?->givePermissionTo(array_column($permissions, 'name'));
    }
}
