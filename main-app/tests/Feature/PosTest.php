<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Tests\TestCase;
use Workdo\Account\Models\ChartOfAccount;
use Workdo\Account\Models\JournalEntry;
use Workdo\Account\Models\Payment;
use Workdo\Account\Services\AccountService;
use Workdo\Pos\Models\PosSale;
use Workdo\Pos\Support\WalkInCustomer;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\ProductCategory;
use Workdo\ProductService\Models\ProductTax;
use Workdo\ProductService\Models\Warehouse;
use Workdo\ProductService\Services\StockService;
use Workdo\SalesPurchase\Models\Document;

class PosTest extends TestCase
{
    private User $a;
    private Warehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->company('a@test.com');
        $this->wh = $this->warehouse($this->a);
    }

    // ───────────── fixtures ─────────────

    private function company(string $email, string $plan = 'Pro'): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create(['name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company', 'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id]);
        $c->assignRole('company');
        $p = Plan::where('name', $plan)->first();
        app(PlanService::class)->assign($c, $p, $p->free_plan ? null : 'year');

        return $c->refresh();
    }

    /** A plan that has the POS but not Accounting. */
    private function posOnlyCompany(string $email): User
    {
        $plan = Plan::create(['name' => 'POS only', 'monthly_price' => 5, 'yearly_price' => 50, 'max_users' => 5, 'modules' => ['Pos']]);
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create(['name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company', 'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id]);
        $c->assignRole('company');
        app(PlanService::class)->assign($c, $plan, 'year');

        return $c->refresh();
    }

    private function warehouse(User $t, string $name = 'Shop'): Warehouse
    {
        return Warehouse::create(['name' => $name, 'is_active' => true, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function product(User $t, string $sku, float $price = 20, array $extra = []): Product
    {
        return Product::create($extra + ['name' => "Item {$sku}", 'sku' => $sku, 'type' => 'product', 'sale_price' => $price, 'purchase_price' => $price / 2, 'is_active' => true, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function stock(Product $p, float $qty, ?Warehouse $w = null): void
    {
        app(StockService::class)->set($p, ($w ?? $this->wh)->id, $qty);
    }

    private function qty(Product $p, ?Warehouse $w = null): float
    {
        return app(StockService::class)->quantity($p->id, ($w ?? $this->wh)->id);
    }

    private function customer(User $t): User
    {
        $u = User::create(['name' => 'Ravi', 'email' => uniqid() . '@t.com', 'password' => 'secret-pass-1', 'type' => 'client', 'email_verified_at' => now(), 'creator_id' => $t->id, 'created_by' => $t->id]);
        $u->assignRole('client');

        return $u;
    }

    private function cart(array $lines, array $extra = []): array
    {
        return $extra + [
            'warehouse_id' => $this->wh->id,
            'payment_method' => 'cash',
            'amount_tendered' => 1000,
            'items' => array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]] + (isset($l[2]) ? ['discount_amount' => $l[2]] : []), $lines),
        ];
    }

    private function sell(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->a)->post('/pos/checkout', $payload);
    }

    private function account(User $t, string $code): ChartOfAccount
    {
        return app(AccountService::class)->account($t->id, $code);
    }

    // ───────────── terminal & walk-in customer ─────────────

    public function test_terminal_opens_and_creates_one_walk_in_customer(): void
    {
        $this->customer($this->a);

        $this->actingAs($this->a)->get('/pos')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Pos/Terminal', false)->has('warehouses', 1)->has('customers', 1)->where('canSell', true)->has('methods', 4));
        $this->actingAs($this->a)->get('/pos')->assertOk();

        $walkIn = User::where('created_by', $this->a->id)->where('name', 'Walk-in Customer')->get();
        $this->assertCount(1, $walkIn);
        $this->assertFalse($walkIn->first()->is_enable_login);
        $this->assertSame($walkIn->first()->id, WalkInCustomer::id($this->a->id));

        // the walk-in is not offered as a named customer, is hidden from Users and does not use a seat
        $this->actingAs($this->a)->get('/pos')->assertInertia(fn ($p) => $p->where('customers.0.name', 'Ravi')->has('customers', 1));
        $this->actingAs($this->a)->get('/users')->assertInertia(fn ($p) => $p->where('users.data', fn ($rows) => collect($rows)->pluck('name')->doesntContain('Walk-in Customer')));
    }

    public function test_walk_in_customer_does_not_use_a_plan_seat(): void
    {
        $this->a->update(['total_user' => 1]);
        WalkInCustomer::id($this->a->id);

        $this->actingAs($this->a)->post('/users', ['name' => 'Cashier', 'email' => 'cashier@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'role' => 'staff'])
            ->assertSessionHas('success');
        $this->actingAs($this->a)->post('/users', ['name' => 'Second', 'email' => 'second@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'role' => 'staff'])
            ->assertSessionHas('error'); // the limit still works
    }

    public function test_each_company_gets_its_own_walk_in_customer(): void
    {
        $b = $this->company('b@test.com');
        $this->assertNotSame(WalkInCustomer::id($this->a->id), WalkInCustomer::id($b->id));
        $this->assertSame(WalkInCustomer::id($b->id), WalkInCustomer::id($b->id));
    }

    // ───────────── cash sale ─────────────

    public function test_cash_sale_creates_a_posted_invoice_moves_stock_and_books_cash(): void
    {
        $p = $this->product($this->a, 'P1', 20);
        $this->stock($p, 10);
        $tax = ProductTax::create(['name' => 'GST', 'rate' => 18, 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $p->update(['tax_ids' => [$tax->id]]);

        $response = $this->sell($this->cart([[$p, 3, 6]], ['amount_tendered' => 100])); // 60 - 6 = 54, tax 9.72, total 63.72

        $sale = PosSale::firstOrFail();
        $response->assertRedirect(route('pos.receipts.show', $sale->id));
        $doc = $sale->document;

        $this->assertSame('sales_invoice', $doc->type);
        $this->assertSame('posted', $doc->status);
        $this->assertSame(63.72, $doc->total_amount);
        $this->assertSame(63.72, $doc->paid_amount);
        $this->assertSame(WalkInCustomer::id($this->a->id), $doc->party_id);
        $this->assertSame($this->a->id, $doc->created_by);
        $this->assertSame(7.0, $this->qty($p));

        $this->assertSame(63.72, $sale->amount_paid);
        $this->assertSame(100.0, $sale->amount_tendered);
        $this->assertSame(36.28, $sale->change_due);
        $this->assertSame($this->a->id, $sale->cashier_id);

        // books: invoice entry + a customer payment into Cash, receivable back to zero
        $this->assertSame(1, Payment::where('kind', 'customer')->count());
        $payment = Payment::firstOrFail();
        $this->assertSame($this->account($this->a, '1000')->id, $payment->account_id);
        $this->assertSame(63.72, $payment->amount);
        $this->assertSame(2, JournalEntry::where('created_by', $this->a->id)->count());
        $balance = fn (string $code) => (float) \DB::table('journal_entry_items')->where('account_id', $this->account($this->a, $code)->id)->selectRaw('COALESCE(SUM(debit - credit),0) as b')->value('b');
        $this->assertSame(0.0, round($balance('1100'), 2));       // receivable settled
        $this->assertSame(63.72, round($balance('1000'), 2));     // cash in the drawer
        $this->assertSame(-54.0, round($balance('4100'), 2));     // sales (credit)
    }

    public function test_card_and_bank_transfer_go_to_the_bank_account(): void
    {
        $p = $this->product($this->a, 'P1', 20);
        $this->stock($p, 10);

        $this->sell($this->cart([[$p, 1]], ['payment_method' => 'card', 'amount_tendered' => null]))->assertSessionHasNoErrors();
        $this->sell($this->cart([[$p, 1]], ['payment_method' => 'bank_transfer']))->assertSessionHasNoErrors();

        $this->assertSame([$this->account($this->a, '1010')->id], Payment::pluck('account_id')->unique()->values()->all());
        $this->assertSame([0.0, 0.0], PosSale::orderBy('id')->pluck('change_due')->all()); // no change on cards, whatever was typed
        $this->assertSame(8.0, $this->qty($p));
    }

    public function test_cash_must_cover_the_total(): void
    {
        $p = $this->product($this->a, 'P1', 20);
        $this->stock($p, 10);

        $this->sell($this->cart([[$p, 2]], ['amount_tendered' => 39.99]))->assertSessionHasErrors('amount_tendered');
        $this->sell($this->cart([[$p, 2]], ['amount_tendered' => null]))->assertSessionHasErrors('amount_tendered');

        $this->assertSame(0, Document::count());
        $this->assertSame(10.0, $this->qty($p));

        $this->sell($this->cart([[$p, 2]], ['amount_tendered' => 40]))->assertSessionHasNoErrors(); // exact cash is fine
        $this->assertSame(0.0, PosSale::firstOrFail()->change_due);
    }

    // ───────────── price integrity ─────────────

    public function test_prices_and_taxes_come_from_the_database_not_the_browser(): void
    {
        $p = $this->product($this->a, 'P1', 50);
        $this->stock($p, 10);

        $this->sell($this->cart([[$p, 2]]) + ['total_amount' => 1]);
        $payload = $this->cart([[$p, 2]]);
        $payload['items'][0] += ['unit_price' => 1, 'tax_ids' => [], 'total' => 2, 'name' => 'hacked'];
        $this->sell($payload)->assertSessionHasNoErrors();

        foreach (Document::all() as $doc) {
            $this->assertSame(100.0, $doc->total_amount);
            $this->assertSame(50.0, $doc->items->first()->unit_price);
            $this->assertSame('Item P1', $doc->items->first()->name);
        }
    }

    public function test_discounts_are_capped_by_the_line_amount(): void
    {
        $p = $this->product($this->a, 'P1', 10);
        $this->stock($p, 10);

        $this->sell($this->cart([[$p, 1, 10.01]]))->assertSessionHasErrors('items.0.discount_amount');
        $this->sell($this->cart([[$p, 1, -1]]))->assertSessionHasErrors('items.0.discount_amount');
        $this->assertSame(0, Document::count());

        $this->sell($this->cart([[$p, 1, 10]]))->assertSessionHasNoErrors(); // a free item is allowed
        $this->assertSame(0.0, Document::firstOrFail()->total_amount);
        $this->assertSame(0, Payment::count()); // nothing to pay
        $this->assertSame(9.0, $this->qty($p));
    }

    public function test_services_are_sold_without_stock(): void
    {
        $service = $this->product($this->a, 'SRV', 100, ['type' => 'service']);

        $this->sell($this->cart([[$service, 2]]))->assertSessionHasNoErrors();

        $this->assertSame(200.0, Document::firstOrFail()->total_amount);
        $this->assertSame(0.0, $this->qty($service));
    }

    // ───────────── atomicity ─────────────

    public function test_not_enough_stock_saves_nothing(): void
    {
        $plenty = $this->product($this->a, 'A', 10);
        $scarce = $this->product($this->a, 'B', 10);
        $this->stock($plenty, 50);
        $this->stock($scarce, 1);

        $this->sell($this->cart([[$plenty, 10], [$scarce, 5]]))->assertSessionHas('error');

        $this->assertSame(0, Document::count());
        $this->assertSame(0, PosSale::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(50.0, $this->qty($plenty));
        $this->assertSame(1.0, $this->qty($scarce));
    }

    public function test_a_ledger_failure_rolls_the_whole_sale_back(): void
    {
        $p = $this->product($this->a, 'P1', 20);
        $this->stock($p, 10);
        $this->actingAs($this->a)->get('/account/chart-of-accounts');
        $this->account($this->a, '4100')->forceFill(['is_active' => false])->save();

        $this->sell($this->cart([[$p, 2]]))->assertSessionHas('error');

        $this->assertSame(0, PosSale::count());
        $this->assertSame(0, Document::count());
        $this->assertSame(10.0, $this->qty($p));
    }

    // ───────────── sale on account ─────────────

    public function test_credit_sale_needs_a_real_customer_and_stays_receivable(): void
    {
        $p = $this->product($this->a, 'P1', 20);
        $this->stock($p, 10);
        $walkIn = WalkInCustomer::id($this->a->id);

        $this->sell($this->cart([[$p, 1]], ['payment_method' => 'credit']))->assertSessionHasErrors('customer_id');
        $this->sell($this->cart([[$p, 1]], ['payment_method' => 'credit', 'customer_id' => $walkIn]))->assertSessionHasErrors('customer_id');
        $this->assertSame(0, Document::count());

        $ravi = $this->customer($this->a);
        $this->sell($this->cart([[$p, 3]], ['payment_method' => 'credit', 'customer_id' => $ravi->id]))->assertSessionHasNoErrors();

        $doc = Document::firstOrFail();
        $this->assertSame($ravi->id, $doc->party_id);
        $this->assertSame(60.0, $doc->total_amount);
        $this->assertSame(0.0, $doc->paid_amount);
        $this->assertSame(0, Payment::count());
        $this->assertSame(7.0, $this->qty($p));
        $this->assertSame(0.0, PosSale::firstOrFail()->amount_paid);
    }

    public function test_without_accounting_the_invoice_is_simply_marked_paid(): void
    {
        $shop = $this->posOnlyCompany('shop@test.com');
        $wh = $this->warehouse($shop);
        $p = $this->product($shop, 'P1', 20);
        $this->stock($p, 5, $wh);

        $this->actingAs($shop)->post('/pos/checkout', ['warehouse_id' => $wh->id, 'payment_method' => 'cash', 'amount_tendered' => 50, 'items' => [['product_id' => $p->id, 'quantity' => 2]]])
            ->assertSessionHasNoErrors();

        $doc = Document::where('created_by', $shop->id)->firstOrFail();
        $this->assertSame(40.0, $doc->paid_amount);
        $this->assertSame(0, JournalEntry::where('created_by', $shop->id)->count());
        $this->assertSame(0, Payment::where('created_by', $shop->id)->count());
        $this->assertSame(3.0, $this->qty($p, $wh));
        $this->assertSame(10.0, PosSale::firstOrFail()->change_due);
    }

    // ───────────── validation & isolation ─────────────

    public function test_checkout_validation(): void
    {
        $p = $this->product($this->a, 'P1');
        $this->stock($p, 10);
        $inactive = $this->product($this->a, 'OFF', 5, ['is_active' => false]);
        $off = Warehouse::create(['name' => 'Closed', 'is_active' => false, 'created_by' => $this->a->id]);

        $this->sell($this->cart([]))->assertSessionHasErrors('items');
        $this->sell($this->cart([[$p, 0]]))->assertSessionHasErrors('items.0.quantity');
        $this->sell($this->cart([[$p, -1]]))->assertSessionHasErrors('items.0.quantity');
        $this->sell($this->cart([[$inactive, 1]]))->assertSessionHasErrors('items.0.product_id');
        $this->sell($this->cart([[$p, 1]], ['payment_method' => 'bitcoin']))->assertSessionHasErrors('payment_method');
        $this->sell($this->cart([[$p, 1]], ['warehouse_id' => $off->id]))->assertSessionHasErrors('warehouse_id');
        $this->sell(['payment_method' => 'cash'])->assertSessionHasErrors(['warehouse_id', 'items']);
        $this->assertSame(0, Document::count());
    }

    public function test_other_companies_records_are_rejected(): void
    {
        $b = $this->company('b@test.com');
        $bWh = $this->warehouse($b);
        $bProduct = $this->product($b, 'B1');
        $this->stock($bProduct, 10, $bWh);
        $bCustomer = $this->customer($b);
        $p = $this->product($this->a, 'P1');
        $this->stock($p, 10);

        $this->sell($this->cart([[$bProduct, 1]]))->assertSessionHasErrors('items.0.product_id');
        $this->sell($this->cart([[$p, 1]], ['warehouse_id' => $bWh->id]))->assertSessionHasErrors('warehouse_id');
        $this->sell($this->cart([[$p, 1]], ['customer_id' => $bCustomer->id]))->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Document::count());
        $this->assertSame(10.0, $this->qty($bProduct, $bWh));
    }

    // ───────────── product lookup ─────────────

    public function test_product_search(): void
    {
        $cola = $this->product($this->a, 'COLA-1', 25, ['name' => 'Cola']);
        $fanta = $this->product($this->a, 'FANTA-1', 22, ['name' => 'Fanta']);
        $empty = $this->product($this->a, 'EMPTY', 5, ['name' => 'Sold out']);
        $service = $this->product($this->a, 'SRV', 100, ['type' => 'service', 'name' => 'Delivery']);
        $hidden = $this->product($this->a, 'OLD', 5, ['name' => 'Retired', 'is_active' => false]);
        $other = $this->warehouse($this->a, 'Other');
        $category = ProductCategory::create(['name' => 'Drinks', 'created_by' => $this->a->id]);
        $cola->update(['category_id' => $category->id]);
        $this->stock($cola, 7);
        $this->stock($fanta, 3);
        $this->stock($hidden, 3);
        $this->stock($fanta, 9, $other);

        $search = fn (array $q) => $this->actingAs($this->a)->getJson('/pos/products?' . http_build_query(['warehouse_id' => $this->wh->id] + $q));

        $names = fn ($r) => collect($r->json())->pluck('name')->sort()->values()->all();
        $this->assertSame(['Cola', 'Delivery', 'Fanta'], $names($search([])));                 // sold out + inactive hidden, service shown
        $this->assertSame(['Cola'], $names($search(['q' => 'col'])));
        $this->assertSame(['Cola'], $names($search(['category' => $category->id])));
        $this->assertSame(['Fanta'], $names($search(['sku' => 'FANTA-1'])));
        $this->assertSame([], $names($search(['sku' => 'COLA'])));                              // sku is an exact match

        $row = collect($search(['sku' => 'COLA-1'])->json())->first();
        $this->assertSame(7.0, (float) $row['stock']);                                         // stock of the chosen warehouse
        $this->assertSame(25.0, (float) $row['sale_price']);
        $this->assertNull(collect($search(['q' => 'Deliv'])->json())->first()['stock']);
        $this->assertSame(9.0, (float) collect($this->actingAs($this->a)->getJson('/pos/products?warehouse_id=' . $other->id)->json())->firstWhere('name', 'Fanta')['stock']);
    }

    public function test_product_search_is_tenant_safe(): void
    {
        $b = $this->company('b@test.com');
        $bWh = $this->warehouse($b);
        $bProduct = $this->product($b, 'SECRET');
        $this->stock($bProduct, 5, $bWh);

        $this->actingAs($this->a)->getJson('/pos/products?warehouse_id=' . $bWh->id)->assertStatus(422);
        $this->actingAs($this->a)->getJson('/pos/products?warehouse_id=' . $this->wh->id)->assertOk()->assertJson([]);
        $this->actingAs($this->a)->getJson('/pos/products')->assertStatus(422);
    }

    // ───────────── receipt, orders, permissions ─────────────

    public function test_receipt_is_private_to_the_company(): void
    {
        $p = $this->product($this->a, 'P1');
        $this->stock($p, 5);
        $this->sell($this->cart([[$p, 1]]));
        $sale = PosSale::firstOrFail();
        $b = $this->company('b@test.com');

        $this->actingAs($this->a)->get("/pos/receipts/{$sale->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('Pos/Receipt', false)->where('sale.document.number', 'SI-00001')->where('companyName', 'a@test.com')->has('sale.document.items', 1));
        $this->actingAs($b)->get("/pos/receipts/{$sale->id}")->assertRedirect(route('dashboard'));
    }

    public function test_orders_list_filters_and_totals(): void
    {
        $p = $this->product($this->a, 'P1', 10);
        $this->stock($p, 50);
        $ravi = $this->customer($this->a);
        $this->sell($this->cart([[$p, 1]]));                                                                        // SI-00001 cash 10
        $this->sell($this->cart([[$p, 2]], ['payment_method' => 'card']));                                          // SI-00002 card 20
        $this->sell($this->cart([[$p, 3]], ['payment_method' => 'credit', 'customer_id' => $ravi->id]));            // SI-00003 credit 30
        $b = $this->company('b@test.com');

        $get = fn (string $q = '') => $this->actingAs($this->a)->get('/pos/orders' . $q);
        $get()->assertOk()->assertInertia(fn ($page) => $page->component('Pos/Orders/Index', false)->has('sales.data', 3)->where('total', 60));
        $get('?method=card')->assertInertia(fn ($page) => $page->has('sales.data', 1)->where('total', 20));
        $get('?search=SI-00003')->assertInertia(fn ($page) => $page->has('sales.data', 1));
        $get('?search=Ravi')->assertInertia(fn ($page) => $page->has('sales.data', 1)->where('sales.data.0.document.party.name', 'Ravi'));
        $get('?from=2099-01-01')->assertInertia(fn ($page) => $page->has('sales.data', 0)->where('total', 0));
        $get('?method=hack')->assertOk();
        $this->actingAs($b)->get('/pos/orders')->assertInertia(fn ($page) => $page->has('sales.data', 0));
    }

    public function test_reports_add_up_and_net_out_returns(): void
    {
        $p = $this->product($this->a, 'P1', 10);
        $this->stock($p, 50);
        $this->sell($this->cart([[$p, 4]]));                                               // 40 cash
        $this->sell($this->cart([[$p, 2, 5]], ['payment_method' => 'card']));              // 20 - 5 = 15 card
        $first = Document::where('number', 'SI-00001')->firstOrFail();

        // return one unit of the first sale
        $this->actingAs($this->a)->post('/sales-purchase/sales-returns', ['parent_id' => $first->id, 'doc_date' => now()->toDateString(), 'items' => [['source_item_id' => $first->items->first()->id, 'quantity' => 1]]]);
        $return = Document::where('type', 'sales_return')->firstOrFail();
        $this->actingAs($this->a)->post("/sales-purchase/sales-returns/{$return->id}/approve")->assertSessionHas('success');
        $this->assertSame(45.0, $this->qty($p)); // 50 - 4 - 2 + 1 returned

        $this->actingAs($this->a)->get('/pos/reports')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Pos/Reports/Index', false)
            ->where('summary.sales', 2)->where('summary.subtotal', 60)->where('summary.discount', 5)->where('summary.total', 55)
            ->where('summary.returns', 10)->where('summary.net', 45)->where('summary.average', 27.5)
            ->where('byMethod', fn ($rows) => collect($rows)->pluck('total', 'label')->map(fn ($v) => (float) $v)->all() == ['cash' => 40.0, 'card' => 15.0])
            ->where('topProducts.0.label', 'Item P1')->where('topProducts.0.quantity', 6)
            ->has('daily', 1));

        $this->actingAs($this->a)->get('/pos/reports?from=2099-01-01&to=2099-01-02')->assertInertia(fn ($page) => $page->where('summary.sales', 0)->where('summary.net', 0));
        $this->actingAs($this->a)->get('/pos/reports?from=bad&to=%27--')->assertOk();
        $this->actingAs($this->a)->get('/pos/reports?from=2099-02-01&to=2099-01-01')->assertInertia(fn ($page) => $page->where('params.from', '2099-01-01')); // swapped dates are fixed
    }

    public function test_reports_never_mix_companies(): void
    {
        $p = $this->product($this->a, 'P1', 10);
        $this->stock($p, 5);
        $this->sell($this->cart([[$p, 1]]));
        $b = $this->company('b@test.com');

        $this->actingAs($b)->get('/pos/reports')->assertInertia(fn ($page) => $page->where('summary.sales', 0)->where('summary.total', 0)->has('topProducts', 0));
    }

    public function test_permissions(): void
    {
        $p = $this->product($this->a, 'P1');
        $this->stock($p, 5);
        $staff = User::create(['name' => 's', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/pos')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->getJson('/pos/products?warehouse_id=' . $this->wh->id)->assertForbidden();
        $this->actingAs($staff)->get('/pos/orders')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->get('/pos/reports')->assertRedirect(route('dashboard'));
        $this->sell($this->cart([[$p, 1]]), $staff)->assertSessionHas('error');

        $staff->givePermissionTo('manage-pos'); // can look, cannot sell
        $this->actingAs($staff)->get('/pos')->assertOk()->assertInertia(fn ($page) => $page->where('canSell', false));
        $this->sell($this->cart([[$p, 1]]), $staff)->assertSessionHas('error');
        $this->assertSame(0, PosSale::count());

        $staff->givePermissionTo('create-pos');
        $this->sell($this->cart([[$p, 1]]), $staff)->assertSessionHasNoErrors();
        $sale = PosSale::firstOrFail();
        $this->assertSame($staff->id, $sale->cashier_id);        // the cashier is recorded
        $this->assertSame($this->a->id, $sale->created_by);      // the sale belongs to the company
        $this->actingAs($staff)->get("/pos/receipts/{$sale->id}")->assertOk();
    }

    public function test_free_plan_companies_cannot_use_the_pos(): void
    {
        $free = $this->company('free@test.com', 'Free');

        $this->actingAs($free)->get('/pos')->assertRedirect(route('dashboard'));
        $this->actingAs($free)->get('/pos/orders')->assertRedirect(route('dashboard'));
        $this->actingAs($free)->post('/pos/checkout', [])->assertRedirect(route('dashboard'));
    }

    public function test_deleting_a_company_removes_its_pos_data(): void
    {
        $p = $this->product($this->a, 'P1');
        $this->stock($p, 5);
        $this->sell($this->cart([[$p, 1]]));
        $this->assertSame(1, PosSale::count());

        $admin = User::where('type', 'superadmin')->first();
        $this->actingAs($admin)->delete("/companies/{$this->a->id}")->assertSessionHas('success');

        $this->assertSame(0, PosSale::count());
        $this->assertSame(0, Document::where('created_by', $this->a->id)->count());
    }
}
