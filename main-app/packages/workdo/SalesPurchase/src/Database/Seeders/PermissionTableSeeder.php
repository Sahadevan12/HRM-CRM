<?php

namespace Workdo\SalesPurchase\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Workdo\SalesPurchase\Support\DocumentType;

class PermissionTableSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [];
        foreach (DocumentType::all() as $meta) {
            $slug = $meta['slug'];
            $plural = $meta['plural'];

            $verbs = ['manage' => 'Manage', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete'];
            if ($meta['is_return']) {
                $verbs['approve'] = 'Approve'; // approve + complete
            } elseif ($slug !== 'sales-proposals') {
                $verbs['post'] = 'Post';
            }

            // returns cannot be edited, so no edit permission for them
            if ($meta['is_return']) {
                unset($verbs['edit']);
            }

            foreach ($verbs as $verb => $label) {
                $permissions[] = ['name' => "{$verb}-{$slug}", 'module' => $slug, 'label' => "{$label} {$plural}"];
            }
        }

        $company = Role::where('name', 'company')->first();

        foreach ($permissions as $perm) {
            $permission = Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                ['module' => $perm['module'], 'label' => $perm['label'], 'add_on' => 'SalesPurchase']
            );

            if ($company && !$company->hasPermissionTo($permission)) {
                $company->givePermissionTo($permission);
            }
        }
    }
}
