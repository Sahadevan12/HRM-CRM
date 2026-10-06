<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Database\Seeders\PermissionRoleSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Workdo\ProductService\Database\Seeders\PermissionTableSeeder;
use Workdo\ProductService\Events\CreateStockTransfer;
use Workdo\ProductService\Events\DestroyStockTransfer;
use Workdo\ProductService\Exceptions\InsufficientStockException;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\ProductCategory;
use Workdo\ProductService\Models\ProductStock;
use Workdo\ProductService\Models\ProductTax;
use Workdo\ProductService\Models\ProductUnit;
use Workdo\ProductService\Models\StockTransfer;
use Workdo\ProductService\Models\Warehouse;
use Workdo\ProductService\Services\StockService;

class ProductServiceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'product-service';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionRoleSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(PermissionTableSeeder::class);
    }

    private function company(string $email = 'a@test.com'): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company',
            'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id,
        ]);
        $c->assignRole('company');
        app(PlanService::class)->assign($c, Plan::where('free_plan', true)->first()); // free plan: ProductService is always active

        return $c->refresh();
    }

    private function warehouse(User $tenant, string $name = 'Main', array $extra = []): Warehouse
    {
        return Warehouse::create($extra + ['name' => $name, 'is_active' => true, 'creator_id' => $tenant->id, 'created_by' => $tenant->id]);
    }

    private function product(User $tenant, string $sku = 'SKU-1', array $extra = []): Product
    {
        return Product::create($extra + [
            'name' => "Item {$sku}", 'sku' => $sku, 'type' => 'product', 'sale_price' => 10, 'purchase_price' => 5,
            'is_active' => true, 'creator_id' => $tenant->id, 'created_by' => $tenant->id,
        ]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['name' => 'Widget', 'sku' => 'W-1', 'type' => 'product', 'sale_price' => 9.5, 'purchase_price' => 4, 'is_active' => true];
    }

    // ───────────── access ─────────────

    public function test_always_active_module_is_available_on_the_free_plan_and_hidden_from_plan_editor(): void
    {
        $a = $this->company();

        $this->actingAs($a)->get('/' . self::BASE . '/products')->assertOk();
        $this->assertTrue(Module_is_active('ProductService', $a->id));

        $admin = User::where('type', 'superadmin')->first();
        $this->actingAs($admin)->get('/plans')->assertInertia(fn ($page) => $page
            ->where('modules', fn ($modules) => collect($modules)->pluck('module')->doesntContain('ProductService')));
    }

    public function test_staff_without_permissions_are_refused(): void
    {
        $a = $this->company();
        $staff = User::create(['name' => 's', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $a->id, 'created_by' => $a->id]);
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/' . self::BASE . '/products')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->post('/' . self::BASE . '/products', $this->payload())->assertSessionHas('error');
        $this->assertSame(0, Product::count());
    }

    public function test_reseeding_core_permissions_keeps_module_permissions_and_full_seed_covers_every_module(): void
    {
        $company = \Spatie\Permission\Models\Role::findByName('company');
        $this->assertTrue($company->hasPermissionTo('manage-products'));

        $this->seed(PermissionRoleSeeder::class); // core re-run must not wipe add-on permissions
        $this->assertTrue($company->fresh()->hasPermissionTo('manage-products'));
        $this->assertFalse($company->fresh()->hasPermissionTo('manage-companies')); // admin-only stays admin-only

        \Spatie\Permission\Models\Permission::where('name', 'manage-transfers')->delete();
        $this->seed(\Database\Seeders\DatabaseSeeder::class); // the full seed re-registers every installed module
        $this->assertTrue(\Spatie\Permission\Models\Role::findByName('company')->hasPermissionTo('manage-transfers'));
    }

    // ───────────── products ─────────────

    public function test_product_crud_with_category_unit_and_taxes(): void
    {
        $a = $this->company();
        $category = ProductCategory::create(['name' => 'Drinks', 'created_by' => $a->id, 'creator_id' => $a->id]);
        $unit = ProductUnit::create(['name' => 'Bottle', 'created_by' => $a->id, 'creator_id' => $a->id]);
        $tax = ProductTax::create(['name' => 'VAT', 'rate' => 18, 'created_by' => $a->id, 'creator_id' => $a->id]);

        $this->actingAs($a)->post('/' . self::BASE . '/products', $this->payload([
            'category_id' => $category->id, 'unit_id' => $unit->id, 'tax_ids' => [$tax->id],
        ]))->assertSessionHas('success');

        $product = Product::firstOrFail();
        $this->assertSame($a->id, $product->created_by);
        $this->assertSame([$tax->id], $product->tax_ids);
        $this->assertSame($category->id, $product->category_id);

        $this->actingAs($a)->put('/' . self::BASE . "/products/{$product->id}", $this->payload(['name' => 'Renamed']))->assertSessionHas('success');
        $this->assertSame('Renamed', $product->fresh()->name);

        $this->actingAs($a)->get('/' . self::BASE . '/products?search=W-1')->assertInertia(fn ($p) => $p
            ->has('products.data', 1)->where('products.data.0.category.name', 'Drinks')->has('taxes', 1));

        $this->actingAs($a)->delete('/' . self::BASE . "/products/{$product->id}")->assertSessionHas('success');
        $this->assertSame(0, Product::count());
    }

    public function test_sku_is_unique_per_company_only(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');

        $this->actingAs($a)->post('/' . self::BASE . '/products', $this->payload())->assertSessionHas('success');
        $this->actingAs($a)->post('/' . self::BASE . '/products', $this->payload(['name' => 'Dup']))->assertSessionHasErrors('sku');
        $this->actingAs($b)->post('/' . self::BASE . '/products', $this->payload())->assertSessionHas('success');

        // updating a product keeps its own sku valid
        $own = Product::where('created_by', $a->id)->firstOrFail();
        $this->actingAs($a)->put('/' . self::BASE . "/products/{$own->id}", $this->payload(['name' => 'Same sku']))->assertSessionHasNoErrors();
        $this->assertSame(2, Product::count());
    }

    public function test_relations_of_other_tenants_cannot_be_linked(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');
        $foreignCategory = ProductCategory::create(['name' => 'B-cat', 'created_by' => $b->id, 'creator_id' => $b->id]);
        $foreignUnit = ProductUnit::create(['name' => 'B-unit', 'created_by' => $b->id, 'creator_id' => $b->id]);
        $foreignTax = ProductTax::create(['name' => 'B-tax', 'rate' => 5, 'created_by' => $b->id, 'creator_id' => $b->id]);

        $this->actingAs($a)->post('/' . self::BASE . '/products', $this->payload([
            'category_id' => $foreignCategory->id, 'unit_id' => $foreignUnit->id, 'tax_ids' => [$foreignTax->id],
        ]))->assertSessionHasErrors(['category_id', 'unit_id', 'tax_ids.0']);

        $this->assertSame(0, Product::count());
    }

    public function test_product_validation(): void
    {
        $a = $this->company();

        $this->actingAs($a)->post('/' . self::BASE . '/products', ['name' => '', 'sku' => '', 'type' => 'gadget', 'sale_price' => -1, 'purchase_price' => 'x'])
            ->assertSessionHasErrors(['name', 'sku', 'type', 'sale_price', 'purchase_price']);
        $this->actingAs($a)->post('/' . self::BASE . '/products', $this->payload(['tax_ids' => [1, 1]]))->assertSessionHasErrors();
    }

    public function test_other_tenants_products_are_invisible_and_untouchable(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');
        $bProduct = $this->product($b, 'B-1');

        $this->actingAs($a)->get('/' . self::BASE . '/products')->assertInertia(fn ($p) => $p->has('products.data', 0));
        $this->actingAs($a)->put('/' . self::BASE . "/products/{$bProduct->id}", $this->payload(['sku' => 'B-1']))->assertSessionHas('error');
        $this->actingAs($a)->delete('/' . self::BASE . "/products/{$bProduct->id}")->assertSessionHas('error');
        $this->actingAs($a)->getJson('/' . self::BASE . "/products/{$bProduct->id}/stock")->assertForbidden();
        $this->assertSame('Item B-1', $bProduct->fresh()->name);
    }

    public function test_sort_and_filter_whitelists(): void
    {
        $a = $this->company();
        $this->product($a, 'B', ['name' => 'Banana']);
        $this->product($a, 'A', ['name' => 'Apple']);
        $this->product($a, 'S', ['name' => 'Support', 'type' => 'service']);

        $this->actingAs($a)->get('/' . self::BASE . '/products?sort=name&direction=asc')->assertInertia(fn ($p) => $p->where('products.data.0.name', 'Apple'));
        $this->actingAs($a)->get('/' . self::BASE . '/products?type=service')->assertInertia(fn ($p) => $p->has('products.data', 1));
        $this->actingAs($a)->get('/' . self::BASE . '/products?sort=password&type=evil')->assertOk();
    }

    // ───────────── master data rules ─────────────

    public function test_master_data_validation_rules(): void
    {
        $a = $this->company();

        $this->actingAs($a)->post('/' . self::BASE . '/product-taxes', ['name' => 'VAT', 'rate' => 150])->assertSessionHasErrors('rate');
        $this->actingAs($a)->post('/' . self::BASE . '/product-taxes', ['name' => 'VAT', 'rate' => 18])->assertSessionHas('success');
        $this->actingAs($a)->post('/' . self::BASE . '/product-categories', ['name' => 'X', 'color' => 'red'])->assertSessionHasErrors('color');
        $this->actingAs($a)->post('/' . self::BASE . '/product-categories', ['name' => 'X', 'color' => '#a1B2c3'])->assertSessionHas('success');
        $this->actingAs($a)->post('/' . self::BASE . '/warehouses', ['name' => 'W', 'email' => 'not-an-email', 'is_active' => true])->assertSessionHasErrors('email');
        $this->actingAs($a)->post('/' . self::BASE . '/warehouses', ['name' => 'W', 'email' => 'w@test.com', 'is_active' => true])->assertSessionHas('success');
    }

    // ───────────── stock service ─────────────

    public function test_stock_can_never_go_negative(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $p = $this->product($a);
        $svc = app(StockService::class);

        $this->assertSame(10.5, $svc->adjust($p, $w->id, 10.5));
        $this->assertSame(7.25, $svc->adjust($p, $w->id, -3.25));

        try {
            $svc->adjust($p, $w->id, -8);
            $this->fail('Overdraw accepted');
        } catch (InsufficientStockException $e) {
            $this->assertSame(7.25, $e->available);
        }

        $this->assertSame(7.25, $svc->quantity($p->id, $w->id));
        $this->assertSame(0.0, $svc->set($p, $w->id, -5)); // set clamps at zero
        $this->assertSame(1, ProductStock::count());      // one row per product+warehouse
    }

    public function test_manual_stock_count_via_endpoint(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $p = $this->product($a);

        $this->actingAs($a)->post('/' . self::BASE . "/products/{$p->id}/stock", ['warehouse_id' => $w->id, 'quantity' => 40])->assertSessionHas('success');
        $this->assertSame(40.0, app(StockService::class)->quantity($p->id, $w->id));

        $this->actingAs($a)->getJson('/' . self::BASE . "/products/{$p->id}/stock")->assertOk()->assertJson([['warehouse_id' => $w->id, 'quantity' => 40]]);

        $this->actingAs($a)->post('/' . self::BASE . "/products/{$p->id}/stock", ['warehouse_id' => $w->id, 'quantity' => -1])->assertSessionHasErrors('quantity');

        $service = $this->product($a, 'SRV', ['type' => 'service']);
        $this->actingAs($a)->post('/' . self::BASE . "/products/{$service->id}/stock", ['warehouse_id' => $w->id, 'quantity' => 5])->assertSessionHas('error');

        $b = $this->company('b@test.com');
        $foreign = $this->warehouse($b, 'B-wh');
        $this->actingAs($a)->post('/' . self::BASE . "/products/{$p->id}/stock", ['warehouse_id' => $foreign->id, 'quantity' => 5])->assertSessionHasErrors('warehouse_id');
    }

    // ───────────── transfers ─────────────

    private function stocked(User $tenant, float $qty = 10): array
    {
        $from = $this->warehouse($tenant, 'From');
        $to = $this->warehouse($tenant, 'To');
        $product = $this->product($tenant);
        app(StockService::class)->set($product, $from->id, $qty);

        return [$product, $from, $to];
    }

    private function transferPayload(Product $p, Warehouse $from, Warehouse $to, float $qty): array
    {
        return ['product_id' => $p->id, 'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'quantity' => $qty, 'transfer_date' => '2026-01-15'];
    }

    public function test_transfer_moves_stock_and_can_be_reversed(): void
    {
        Event::fake([CreateStockTransfer::class, DestroyStockTransfer::class]);
        $a = $this->company();
        [$p, $from, $to] = $this->stocked($a, 10);
        $svc = app(StockService::class);

        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($p, $from, $to, 4))->assertSessionHas('success');

        $this->assertSame(6.0, $svc->quantity($p->id, $from->id));
        $this->assertSame(4.0, $svc->quantity($p->id, $to->id));
        $transfer = StockTransfer::firstOrFail();
        $this->assertSame($a->id, $transfer->created_by);
        Event::assertDispatched(CreateStockTransfer::class);

        $this->actingAs($a)->get('/' . self::BASE . '/stock-transfers')->assertInertia(fn ($page) => $page
            ->has('transfers.data', 1)->where('transfers.data.0.product.sku', 'SKU-1')->has('warehouses', 2));

        $this->actingAs($a)->delete('/' . self::BASE . "/stock-transfers/{$transfer->id}")->assertSessionHas('success');
        $this->assertSame(10.0, $svc->quantity($p->id, $from->id));
        $this->assertSame(0.0, $svc->quantity($p->id, $to->id));
        $this->assertSame(0, StockTransfer::count());
        Event::assertDispatched(DestroyStockTransfer::class);
    }

    public function test_insufficient_stock_rejects_the_transfer_and_changes_nothing(): void
    {
        $a = $this->company();
        [$p, $from, $to] = $this->stocked($a, 3);

        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($p, $from, $to, 5))->assertSessionHasErrors('quantity');

        $svc = app(StockService::class);
        $this->assertSame(3.0, $svc->quantity($p->id, $from->id));
        $this->assertSame(0.0, $svc->quantity($p->id, $to->id));
        $this->assertSame(0, StockTransfer::count());
    }

    public function test_transfer_input_rules(): void
    {
        $a = $this->company();
        [$p, $from, $to] = $this->stocked($a, 10);

        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($p, $from, $from, 1))->assertSessionHasErrors('to_warehouse_id');
        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($p, $from, $to, 0))->assertSessionHasErrors('quantity');
        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($p, $from, $to, -2))->assertSessionHasErrors('quantity');
        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', ['quantity' => 1])->assertSessionHasErrors(['product_id', 'from_warehouse_id', 'to_warehouse_id', 'transfer_date']);

        $service = $this->product($a, 'SRV', ['type' => 'service']);
        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($service, $from, $to, 1))->assertSessionHasErrors('product_id');

        $this->assertSame(0, StockTransfer::count());
    }

    public function test_transfers_cannot_use_another_tenants_records(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');
        [$bProduct, $bFrom, $bTo] = $this->stocked($b, 10);
        [$aProduct, $aFrom, $aTo] = $this->stocked($a, 10);

        // A tries to move B's stock / use B's warehouses
        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($bProduct, $bFrom, $bTo, 5))
            ->assertSessionHasErrors(['product_id', 'from_warehouse_id', 'to_warehouse_id']);
        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($aProduct, $aFrom, $bTo, 1))
            ->assertSessionHasErrors('to_warehouse_id');
        $this->assertSame(10.0, app(StockService::class)->quantity($bProduct->id, $bFrom->id));

        // and cannot reverse B's transfer
        $this->actingAs($b)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($bProduct, $bFrom, $bTo, 5));
        $bTransfer = StockTransfer::firstOrFail();
        $this->actingAs($a)->delete('/' . self::BASE . "/stock-transfers/{$bTransfer->id}")->assertSessionHas('error');
        $this->assertSame(1, StockTransfer::count());
        $this->actingAs($a)->get('/' . self::BASE . '/stock-transfers')->assertInertia(fn ($p) => $p->has('transfers.data', 0));
    }

    public function test_a_transfer_cannot_be_reversed_when_the_stock_is_already_gone(): void
    {
        $a = $this->company();
        [$p, $from, $to] = $this->stocked($a, 10);
        $svc = app(StockService::class);

        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($p, $from, $to, 6));
        $svc->adjust($p, $to->id, -5); // 5 of the 6 moved units were sold meanwhile

        $this->actingAs($a)->delete('/' . self::BASE . '/stock-transfers/' . StockTransfer::firstOrFail()->id)->assertSessionHas('error');

        $this->assertSame(1, StockTransfer::count());
        $this->assertSame(4.0, $svc->quantity($p->id, $from->id));
        $this->assertSame(1.0, $svc->quantity($p->id, $to->id));
    }

    // ───────────── warehouses / cascades ─────────────

    public function test_warehouse_with_stock_or_history_cannot_be_deleted(): void
    {
        $a = $this->company();
        [$p, $from, $to] = $this->stocked($a, 10);

        $this->actingAs($a)->delete('/' . self::BASE . "/warehouses/{$from->id}")->assertSessionHas('error'); // holds stock
        $this->assertDatabaseHas('warehouses', ['id' => $from->id]);

        $this->actingAs($a)->post('/' . self::BASE . '/stock-transfers', $this->transferPayload($p, $from, $to, 10));
        // `from` is empty now but has transfer history
        $this->actingAs($a)->delete('/' . self::BASE . "/warehouses/{$from->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('warehouses', ['id' => $from->id]);

        $empty = $this->warehouse($a, 'Empty');
        $this->actingAs($a)->delete('/' . self::BASE . "/warehouses/{$empty->id}")->assertSessionHas('success');
    }

    public function test_deleting_a_product_removes_its_stock_rows(): void
    {
        $a = $this->company();
        [$p, $from] = $this->stocked($a, 10);
        $this->assertSame(1, ProductStock::count());

        $this->actingAs($a)->delete('/' . self::BASE . "/products/{$p->id}")->assertSessionHas('success');
        $this->assertSame(0, ProductStock::count());
    }
}
