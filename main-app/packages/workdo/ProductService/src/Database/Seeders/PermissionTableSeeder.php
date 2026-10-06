<?php

namespace Workdo\ProductService\Database\Seeders;

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
            ['name' => 'manage-product-categories', 'module' => 'product-categories', 'label' => 'Manage Product Categories'],
            ['name' => 'create-product-categories', 'module' => 'product-categories', 'label' => 'Create Product Categories'],
            ['name' => 'edit-product-categories', 'module' => 'product-categories', 'label' => 'Edit Product Categories'],
            ['name' => 'delete-product-categories', 'module' => 'product-categories', 'label' => 'Delete Product Categories'],
            ['name' => 'manage-product-units', 'module' => 'product-units', 'label' => 'Manage Product Units'],
            ['name' => 'create-product-units', 'module' => 'product-units', 'label' => 'Create Product Units'],
            ['name' => 'edit-product-units', 'module' => 'product-units', 'label' => 'Edit Product Units'],
            ['name' => 'delete-product-units', 'module' => 'product-units', 'label' => 'Delete Product Units'],
            ['name' => 'manage-product-taxes', 'module' => 'product-taxes', 'label' => 'Manage Product Taxes'],
            ['name' => 'create-product-taxes', 'module' => 'product-taxes', 'label' => 'Create Product Taxes'],
            ['name' => 'edit-product-taxes', 'module' => 'product-taxes', 'label' => 'Edit Product Taxes'],
            ['name' => 'delete-product-taxes', 'module' => 'product-taxes', 'label' => 'Delete Product Taxes'],
            ['name' => 'manage-warehouses', 'module' => 'warehouses', 'label' => 'Manage Warehouses'],
            ['name' => 'create-warehouses', 'module' => 'warehouses', 'label' => 'Create Warehouses'],
            ['name' => 'edit-warehouses', 'module' => 'warehouses', 'label' => 'Edit Warehouses'],
            ['name' => 'delete-warehouses', 'module' => 'warehouses', 'label' => 'Delete Warehouses'],
            ['name' => 'manage-products', 'module' => 'products', 'label' => 'Manage Products'],
            ['name' => 'create-products', 'module' => 'products', 'label' => 'Create Products'],
            ['name' => 'edit-products', 'module' => 'products', 'label' => 'Edit Products'],
            ['name' => 'delete-products', 'module' => 'products', 'label' => 'Delete Products'],
            ['name' => 'manage-product-stock', 'module' => 'products', 'label' => 'Manage Product Stock'],
            ['name' => 'manage-transfers', 'module' => 'stock-transfers', 'label' => 'Manage Stock Transfers'],
            ['name' => 'create-transfers', 'module' => 'stock-transfers', 'label' => 'Create Stock Transfers'],
            ['name' => 'delete-transfers', 'module' => 'stock-transfers', 'label' => 'Delete Stock Transfers'],
            // <permissions>
        ];

        $company = Role::where('name', 'company')->first();

        foreach ($permissions as $perm) {
            $permission = Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                ['module' => $perm['module'], 'label' => $perm['label'], 'add_on' => 'ProductService']
            );

            if ($company && !$company->hasPermissionTo($permission)) {
                $company->givePermissionTo($permission);
            }
        }
    }
}
