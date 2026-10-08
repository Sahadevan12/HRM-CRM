<?php

namespace Tests\Feature;

use App\Classes\Module;
use App\Models\AddOn;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserActiveModule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Deleting a module folder uninstalls it: nothing keeps offering a module that no longer exists. */
class ModulePruneTest extends TestCase
{
    public function test_a_module_that_is_gone_from_disk_is_removed_everywhere_on_sync(): void
    {
        $pro = Plan::where('name', 'Pro')->firstOrFail();
        $before = (array) $pro->modules;
        $company = User::where('type', 'company')->firstOrFail();

        AddOn::create(['module' => 'Ghost', 'name' => 'Ghost', 'package_name' => 'ghost', 'monthly_price' => 0, 'yearly_price' => 0, 'is_enable' => true, 'for_admin' => false, 'priority' => 99]);
        $pro->update(['modules' => [...$before, 'Ghost']]);
        UserActiveModule::create(['user_id' => $company->id, 'module' => 'Ghost']);
        $permission = Permission::create(['name' => 'manage-ghosts', 'guard_name' => 'web', 'module' => 'ghosts', 'label' => 'Manage Ghosts', 'add_on' => 'Ghost']);
        Role::findByName('company')->givePermissionTo($permission);

        (new Module())->sync();

        $this->assertNull(AddOn::where('module', 'Ghost')->first());
        $this->assertSame(0, UserActiveModule::where('module', 'Ghost')->count());
        $this->assertSame($before, array_values((array) $pro->refresh()->modules));
        $this->assertNull(Permission::where('name', 'manage-ghosts')->first());
        $this->assertTrue(Role::findByName('company')->fresh()->hasPermissionTo('view-hrm-dashboard'));            // real permissions are untouched
        $this->assertTrue(AddOn::where('module', 'Hrm')->exists());
        $this->assertTrue(Permission::where('add_on', 'Lead')->exists());
    }

    public function test_the_shipped_modules_are_the_business_modules_only(): void
    {
        $this->assertEqualsCanonicalizing(['Account', 'Hrm', 'Lead', 'Pos', 'ProductService', 'SalesPurchase'], (new Module())->installed());
    }
}
