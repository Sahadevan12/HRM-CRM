<?php

namespace Tests\Feature;

use App\Events\ApprovePurchaseReturn;
use App\Events\ApproveSalesReturn;
use App\Events\CompleteSalesReturn;
use App\Events\ConvertSalesProposal;
use App\Events\PostPurchaseInvoice;
use App\Events\PostSalesInvoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\ProductTax;
use Workdo\ProductService\Models\Warehouse;
use Workdo\ProductService\Services\StockService;
use Workdo\SalesPurchase\Models\Document;
use Workdo\SalesPurchase\Services\DocumentCalculator;
use Workdo\SalesPurchase\Support\DocumentType;

class SalesPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'sales-purchase';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    // ───────────── fixtures ─────────────

    private function company(string $email = 'a@test.com'): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company',
            'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id,
        ]);
        $c->assignRole('company');
        app(PlanService::class)->assign($c, Plan::where('free_plan', true)->first());

        return $c->refresh();
    }

    private function party(User $tenant, string $type, string $name = null): User
    {
        $u = User::create([
            'name' => $name ?? ucfirst($type) . ' ' . uniqid(), 'email' => uniqid() . '@test.com', 'password' => 'secret-pass-1',
            'type' => $type, 'email_verified_at' => now(), 'creator_id' => $tenant->id, 'created_by' => $tenant->id,
        ]);
        $u->assignRole($type);

        return $u;
    }

    private function warehouse(User $tenant, string $name = 'Main'): Warehouse
    {
        return Warehouse::create(['name' => $name, 'is_active' => true, 'creator_id' => $tenant->id, 'created_by' => $tenant->id]);
    }

    private function product(User $tenant, string $sku, float $price = 10, array $extra = []): Product
    {
        return Product::create($extra + [
            'name' => "Item {$sku}", 'sku' => $sku, 'type' => 'product', 'sale_price' => $price, 'purchase_price' => $price / 2,
            'is_active' => true, 'creator_id' => $tenant->id, 'created_by' => $tenant->id,
        ]);
    }

    private function tax(User $tenant, string $name, float $rate): ProductTax
    {
        return ProductTax::create(['name' => $name, 'rate' => $rate, 'creator_id' => $tenant->id, 'created_by' => $tenant->id]);
    }

    private function stock(Product $p, Warehouse $w, float $qty): void
    {
        app(StockService::class)->set($p, $w->id, $qty);
    }

    private function qty(Product $p, Warehouse $w): float
    {
        return app(StockService::class)->quantity($p->id, $w->id);
    }

    private function line(Product $p, float $qty, float $price = null, float $discount = 0, array $taxIds = []): array
    {
        return ['product_id' => $p->id, 'quantity' => $qty, 'unit_price' => $price ?? $p->sale_price, 'discount_amount' => $discount, 'tax_ids' => $taxIds];
    }

    private function payload(User $party, Warehouse $w, array $items, array $extra = []): array
    {
        return $extra + ['party_id' => $party->id, 'warehouse_id' => $w->id, 'doc_date' => '2026-03-01', 'items' => $items];
    }

    private function url(string $slug, string $suffix = ''): string
    {
        return '/' . self::BASE . "/{$slug}" . $suffix;
    }

    /** Create a document through the real endpoint and return it. */
    private function make(User $actor, string $slug, array $payload): Document
    {
        $this->actingAs($actor)->post($this->url($slug), $payload)->assertSessionHasNoErrors();

        return Document::latest('id')->firstOrFail();
    }

    // ───────────── calculation ─────────────

    public function test_line_and_document_maths(): void
    {
        $a = $this->company();
        $p1 = $this->product($a, 'P1');
        $p2 = $this->product($a, 'P2');
        $gst = $this->tax($a, 'GST', 18);
        $cess = $this->tax($a, 'Cess', 5);

        $result = app(DocumentCalculator::class)->forItems([
            $this->line($p1, 3, 10, 5, [$gst->id, $cess->id]),   // gross 30, net 25, tax 4.50 + 1.25
            $this->line($p2, 3, 3.33, 0, [$gst->id]),            // gross 9.99, tax 1.7982 -> 1.80
        ], $a->id);

        $first = $result['lines'][0];
        $this->assertSame(30.0, $first['gross']);
        $this->assertSame(5.75, $first['tax_amount']);
        $this->assertSame(30.75, $first['total_amount']);
        $this->assertSame([4.5, 1.25], array_column($first['taxes'], 'amount'));

        $second = $result['lines'][1];
        $this->assertSame(9.99, $second['gross']);
        $this->assertSame(1.8, $second['tax_amount']);

        $this->assertSame(39.99, $result['totals']['subtotal']);
        $this->assertSame(5.0, $result['totals']['discount_amount']);
        $this->assertSame(7.55, $result['totals']['tax_amount']);
        $this->assertSame(42.54, $result['totals']['total_amount']); // 39.99 - 5 + 7.55
    }

    public function test_discount_cannot_exceed_the_line_amount(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $p = $this->product($a, 'P1');

        $this->actingAs($a)->post($this->url('sales-invoices'), $this->payload($this->party($a, 'client'), $w, [$this->line($p, 2, 10, 25)]))
            ->assertSessionHasErrors('items.0.discount_amount');
        $this->assertSame(0, Document::count());
    }

    // ───────────── creating ─────────────

    public function test_totals_come_from_the_server_not_from_the_browser(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $customer = $this->party($a, 'client');
        $p = $this->product($a, 'P1');
        $gst = $this->tax($a, 'GST', 10);

        $this->actingAs($a)->post($this->url('sales-invoices'), $this->payload($customer, $w, [$this->line($p, 2, 50, 0, [$gst->id])], [
            'total_amount' => 1, 'subtotal' => 1, 'tax_amount' => 0, 'status' => 'posted', 'paid_amount' => 999, 'number' => 'HACK-1',
        ]))->assertSessionHasNoErrors();

        $doc = Document::firstOrFail();
        $this->assertSame(110.0, $doc->total_amount);
        $this->assertSame(100.0, $doc->subtotal);
        $this->assertSame(10.0, $doc->tax_amount);
        $this->assertSame('draft', $doc->status);
        $this->assertSame(0.0, $doc->paid_amount);
        $this->assertSame('SI-00001', $doc->number);
        $this->assertSame($a->id, $doc->created_by);
        $this->assertSame('GST', $doc->items->first()->taxes->first()->name);
    }

    public function test_numbers_run_per_company_and_per_type(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');
        $aw = $this->warehouse($a);
        $bw = $this->warehouse($b);
        $ap = $this->product($a, 'P1');
        $bp = $this->product($b, 'P1');

        $first = $this->make($a, 'sales-invoices', $this->payload($this->party($a, 'client'), $aw, [$this->line($ap, 1)]));
        $second = $this->make($a, 'sales-invoices', $this->payload($this->party($a, 'client'), $aw, [$this->line($ap, 1)]));
        $purchase = $this->make($a, 'purchase-invoices', $this->payload($this->party($a, 'vendor'), $aw, [$this->line($ap, 1)]));
        $proposal = $this->make($a, 'sales-proposals', $this->payload($this->party($a, 'client'), $aw, [$this->line($ap, 1)]));
        $other = $this->make($b, 'sales-invoices', $this->payload($this->party($b, 'client'), $bw, [$this->line($bp, 1)]));

        $this->assertSame(['SI-00001', 'SI-00002'], [$first->number, $second->number]);
        $this->assertSame('PI-00001', $purchase->number);
        $this->assertSame('SP-00001', $proposal->number);
        $this->assertSame('SI-00001', $other->number);
    }

    public function test_foreign_records_and_wrong_party_roles_are_rejected(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');
        $w = $this->warehouse($a);
        $p = $this->product($a, 'P1');
        $customer = $this->party($a, 'client');
        $vendor = $this->party($a, 'vendor');

        $bCustomer = $this->party($b, 'client');
        $bWarehouse = $this->warehouse($b);
        $bProduct = $this->product($b, 'B1');
        $bTax = $this->tax($b, 'BTax', 10);

        $post = fn (array $p) => $this->actingAs($a)->post($this->url('sales-invoices'), $p);

        $post($this->payload($bCustomer, $w, [$this->line($p, 1)]))->assertSessionHasErrors('party_id');
        $post($this->payload($customer, $bWarehouse, [$this->line($p, 1)]))->assertSessionHasErrors('warehouse_id');
        $post($this->payload($customer, $w, [$this->line($bProduct, 1)]))->assertSessionHasErrors('items.0.product_id');
        $post($this->payload($customer, $w, [$this->line($p, 1, null, 0, [$bTax->id])]))->assertSessionHasErrors('items.0.tax_ids.0');
        $post($this->payload($vendor, $w, [$this->line($p, 1)]))->assertSessionHasErrors('party_id'); // a vendor is not a customer

        $this->actingAs($a)->post($this->url('purchase-invoices'), $this->payload($customer, $w, [$this->line($p, 1)]))->assertSessionHasErrors('party_id');
        $this->assertSame(0, Document::count());
    }

    public function test_basic_validation(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $customer = $this->party($a, 'client');
        $p = $this->product($a, 'P1');

        $this->actingAs($a)->post($this->url('sales-invoices'), $this->payload($customer, $w, []))->assertSessionHasErrors('items');
        $this->actingAs($a)->post($this->url('sales-invoices'), $this->payload($customer, $w, [$this->line($p, 0)]))->assertSessionHasErrors('items.0.quantity');
        $this->actingAs($a)->post($this->url('sales-invoices'), $this->payload($customer, $w, [$this->line($p, 1, -5)]))->assertSessionHasErrors('items.0.unit_price');
        $this->actingAs($a)->post($this->url('sales-invoices'), $this->payload($customer, $w, [$this->line($p, 1)], ['due_date' => '2026-02-01']))->assertSessionHasErrors('due_date');
        $this->actingAs($a)->post($this->url('sales-invoices'), ['items' => [$this->line($p, 1)]])->assertSessionHasErrors(['party_id', 'warehouse_id', 'doc_date']);
        $this->assertSame(0, Document::count());
    }

    // ───────────── draft lifecycle ─────────────

    public function test_drafts_can_be_edited_and_deleted_but_posted_invoices_cannot(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $customer = $this->party($a, 'client');
        $p1 = $this->product($a, 'P1', 10);
        $p2 = $this->product($a, 'P2', 20);
        $this->stock($p1, $w, 100);
        $this->stock($p2, $w, 100);

        $doc = $this->make($a, 'sales-invoices', $this->payload($customer, $w, [$this->line($p1, 1)]));
        $this->assertSame(10.0, $doc->total_amount);

        $this->actingAs($a)->put($this->url('sales-invoices', "/{$doc->id}"), $this->payload($customer, $w, [$this->line($p2, 2), $this->line($p1, 1)]))
            ->assertSessionHasNoErrors();
        $doc->refresh();
        $this->assertSame(50.0, $doc->total_amount);
        $this->assertSame(2, $doc->items()->count());

        $this->actingAs($a)->post($this->url('sales-invoices', "/{$doc->id}/post"))->assertSessionHas('success');

        $this->actingAs($a)->put($this->url('sales-invoices', "/{$doc->id}"), $this->payload($customer, $w, [$this->line($p1, 99)]))->assertSessionHas('error');
        $this->actingAs($a)->delete($this->url('sales-invoices', "/{$doc->id}"))->assertSessionHas('error');
        $this->actingAs($a)->get($this->url('sales-invoices', "/{$doc->id}/edit"))->assertRedirect();
        $this->assertSame(50.0, $doc->fresh()->total_amount);

        $draft = $this->make($a, 'sales-invoices', $this->payload($customer, $w, [$this->line($p1, 1)]));
        $this->actingAs($a)->delete($this->url('sales-invoices', "/{$draft->id}"))->assertSessionHas('success');
        $this->assertNull(Document::find($draft->id));
    }

    // ───────────── posting & stock ─────────────

    public function test_posting_a_sales_invoice_takes_stock_out_and_skips_services(): void
    {
        Event::fake([PostSalesInvoice::class]);
        $a = $this->company();
        $w = $this->warehouse($a);
        $customer = $this->party($a, 'client');
        $p = $this->product($a, 'P1');
        $service = $this->product($a, 'SRV', 100, ['type' => 'service']);
        $this->stock($p, $w, 10);

        $doc = $this->make($a, 'sales-invoices', $this->payload($customer, $w, [$this->line($p, 4), $this->line($service, 1)]));
        $this->actingAs($a)->post($this->url('sales-invoices', "/{$doc->id}/post"))->assertSessionHas('success');

        $this->assertSame(6.0, $this->qty($p, $w));
        $this->assertSame('posted', $doc->fresh()->status);
        $this->assertNotNull($doc->fresh()->posted_at);
        Event::assertDispatched(PostSalesInvoice::class, fn ($e) => $e->document->is($doc));

        // posting twice must not take stock twice
        $this->actingAs($a)->post($this->url('sales-invoices', "/{$doc->id}/post"))->assertSessionHas('error');
        $this->assertSame(6.0, $this->qty($p, $w));
    }

    public function test_insufficient_stock_blocks_posting_and_leaves_everything_untouched(): void
    {
        Event::fake([PostSalesInvoice::class]);
        $a = $this->company();
        $w = $this->warehouse($a);
        $customer = $this->party($a, 'client');
        $plenty = $this->product($a, 'A');
        $scarce = $this->product($a, 'B');
        $this->stock($plenty, $w, 50);
        $this->stock($scarce, $w, 1);

        $doc = $this->make($a, 'sales-invoices', $this->payload($customer, $w, [$this->line($plenty, 10), $this->line($scarce, 5)]));
        $this->actingAs($a)->post($this->url('sales-invoices', "/{$doc->id}/post"))->assertSessionHas('error');

        $this->assertSame('draft', $doc->fresh()->status);
        $this->assertSame(50.0, $this->qty($plenty, $w), 'first line must be rolled back');
        $this->assertSame(1.0, $this->qty($scarce, $w));
        Event::assertNotDispatched(PostSalesInvoice::class);
    }

    public function test_posting_a_purchase_invoice_brings_stock_in(): void
    {
        Event::fake([PostPurchaseInvoice::class]);
        $a = $this->company();
        $w = $this->warehouse($a);
        $vendor = $this->party($a, 'vendor');
        $p = $this->product($a, 'P1');

        $doc = $this->make($a, 'purchase-invoices', $this->payload($vendor, $w, [$this->line($p, 25, 4)]));
        $this->actingAs($a)->post($this->url('purchase-invoices', "/{$doc->id}/post"))->assertSessionHas('success');

        $this->assertSame(25.0, $this->qty($p, $w));
        $this->assertSame(100.0, $doc->fresh()->total_amount);
        Event::assertDispatched(PostPurchaseInvoice::class);
    }

    public function test_a_document_without_lines_cannot_be_posted(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $doc = Document::create([
            'type' => 'sales_invoice', 'number' => 'SI-00001', 'party_id' => $this->party($a, 'client')->id, 'warehouse_id' => $w->id,
            'doc_date' => '2026-01-01', 'created_by' => $a->id,
        ]);

        $this->actingAs($a)->post($this->url('sales-invoices', "/{$doc->id}/post"))->assertSessionHas('error');
        $this->assertSame('draft', $doc->fresh()->status);
    }

    // ───────────── proposals ─────────────

    public function test_proposal_flow_ends_in_a_draft_invoice(): void
    {
        Event::fake([ConvertSalesProposal::class]);
        $a = $this->company();
        $w = $this->warehouse($a);
        $customer = $this->party($a, 'client');
        $p = $this->product($a, 'P1', 20);
        $tax = $this->tax($a, 'VAT', 10);

        $proposal = $this->make($a, 'sales-proposals', $this->payload($customer, $w, [$this->line($p, 3, 20, 10, [$tax->id])]));
        $this->assertSame(55.0, $proposal->total_amount); // 60 - 10 + 5

        $url = fn (string $action) => $this->url('sales-proposals', "/{$proposal->id}/{$action}");

        // order is enforced
        $this->actingAs($a)->post($url('accept'))->assertSessionHas('error');
        $this->actingAs($a)->post($url('convert'))->assertSessionHas('error');

        $this->actingAs($a)->post($url('send'))->assertSessionHas('success');
        $this->actingAs($a)->post($url('send'))->assertSessionHas('error');
        $this->actingAs($a)->post($url('accept'))->assertSessionHas('success');
        $response = $this->actingAs($a)->post($url('convert'));

        $invoice = Document::where('type', 'sales_invoice')->firstOrFail();
        $response->assertRedirect(route('salespurchase.sales-invoices.show', $invoice->id));
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($proposal->id, $invoice->parent_id);
        $this->assertSame(55.0, $invoice->total_amount);
        $this->assertSame('VAT', $invoice->items->first()->taxes->first()->name);
        $this->assertSame('converted', $proposal->fresh()->status);
        Event::assertDispatched(ConvertSalesProposal::class);

        // already converted -> cannot convert again
        $this->actingAs($a)->post($url('convert'))->assertSessionHas('error');
        $this->assertSame(1, Document::where('type', 'sales_invoice')->count());

        // deleting the draft invoice hands the proposal back
        $this->actingAs($a)->delete($this->url('sales-invoices', "/{$invoice->id}"))->assertSessionHas('success');
        $this->assertSame('accepted', $proposal->fresh()->status);
    }

    public function test_rejected_proposals_stay_rejected(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $proposal = $this->make($a, 'sales-proposals', $this->payload($this->party($a, 'client'), $w, [$this->line($this->product($a, 'P1'), 1)]));

        $this->actingAs($a)->post($this->url('sales-proposals', "/{$proposal->id}/send"));
        $this->actingAs($a)->post($this->url('sales-proposals', "/{$proposal->id}/reject"))->assertSessionHas('success');
        $this->actingAs($a)->post($this->url('sales-proposals', "/{$proposal->id}/accept"))->assertSessionHas('error');
        $this->actingAs($a)->post($this->url('sales-proposals', "/{$proposal->id}/convert"))->assertSessionHas('error');
        $this->assertSame('rejected', $proposal->fresh()->status);
    }

    // ───────────── returns ─────────────

    private function postedSalesInvoice(User $a, Warehouse $w, Product $p, float $qty = 10, float $price = 20, float $discount = 20, array $taxIds = []): Document
    {
        $doc = $this->make($a, 'sales-invoices', $this->payload($this->party($a, 'client'), $w, [$this->line($p, $qty, $price, $discount, $taxIds)]));
        $this->actingAs($a)->post($this->url('sales-invoices', "/{$doc->id}/post"))->assertSessionHas('success');

        return $doc->refresh();
    }

    public function test_sales_return_is_priced_pro_rata_and_brings_stock_back(): void
    {
        Event::fake([ApproveSalesReturn::class, CompleteSalesReturn::class]);
        $a = $this->company();
        $w = $this->warehouse($a);
        $p = $this->product($a, 'P1');
        $tax = $this->tax($a, 'GST', 10);
        $this->stock($p, $w, 100);
        $invoice = $this->postedSalesInvoice($a, $w, $p, 10, 20, 20, [$tax->id]); // gross 200, discount 20, net 180, tax 18, total 198
        $this->assertSame(198.0, $invoice->total_amount);
        $this->assertSame(90.0, $this->qty($p, $w));

        $sourceItem = $invoice->items->first();
        $this->actingAs($a)->post($this->url('sales-returns'), [
            'parent_id' => $invoice->id, 'doc_date' => '2026-03-05', 'reason' => 'Damaged', 'items' => [['source_item_id' => $sourceItem->id, 'quantity' => 4]],
        ])->assertSessionHasNoErrors();

        $return = Document::where('type', 'sales_return')->firstOrFail();
        $this->assertSame('SR-00001', $return->number);
        $this->assertSame($invoice->party_id, $return->party_id);       // taken from the invoice, not the request
        $this->assertSame($invoice->warehouse_id, $return->warehouse_id);
        $this->assertSame($invoice->id, $return->parent_id);
        $this->assertSame(80.0, $return->subtotal);                      // 4 * 20
        $this->assertSame(8.0, $return->discount_amount);                // 20 * 4/10
        $this->assertSame(7.2, $return->tax_amount);                     // 18 * 4/10
        $this->assertSame(79.2, $return->total_amount);                  // 80 - 8 + 7.2
        $this->assertSame(90.0, $this->qty($p, $w), 'a draft return does not move stock');

        $this->actingAs($a)->post($this->url('sales-returns', "/{$return->id}/complete"))->assertSessionHas('error'); // not approved yet
        $this->actingAs($a)->post($this->url('sales-returns', "/{$return->id}/approve"))->assertSessionHas('success');
        $this->assertSame(94.0, $this->qty($p, $w));
        $this->assertSame('approved', $return->fresh()->status);
        Event::assertDispatched(ApproveSalesReturn::class);

        $this->actingAs($a)->post($this->url('sales-returns', "/{$return->id}/approve"))->assertSessionHas('error');
        $this->assertSame(94.0, $this->qty($p, $w));

        $this->actingAs($a)->post($this->url('sales-returns', "/{$return->id}/complete"))->assertSessionHas('success');
        $this->assertSame('completed', $return->fresh()->status);
        Event::assertDispatched(CompleteSalesReturn::class);
    }

    public function test_returned_quantities_add_up_across_returns(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $p = $this->product($a, 'P1');
        $this->stock($p, $w, 100);
        $invoice = $this->postedSalesInvoice($a, $w, $p, 10);
        $item = $invoice->items->first();

        $ret = fn (float $qty) => $this->actingAs($a)->post($this->url('sales-returns'), [
            'parent_id' => $invoice->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $item->id, 'quantity' => $qty]],
        ]);

        $ret(6)->assertSessionHasNoErrors();
        $ret(5)->assertSessionHasErrors('items.0.quantity');   // only 4 left
        $ret(4)->assertSessionHasNoErrors();                   // exactly the rest
        $ret(0.01)->assertSessionHasErrors('items.0.quantity');
        $this->assertSame(2, Document::where('type', 'sales_return')->count());

        $this->assertSame(0.0, app(DocumentCalculator::class)->returnableQuantities($invoice->fresh()->load('items'))[$item->id]);
    }

    public function test_returns_need_a_posted_invoice_of_the_matching_kind_and_company(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');
        $w = $this->warehouse($a);
        $bw = $this->warehouse($b);
        $p = $this->product($a, 'P1');
        $bp = $this->product($b, 'P1');
        $this->stock($p, $w, 50);
        $this->stock($bp, $bw, 50);

        $draft = $this->make($a, 'sales-invoices', $this->payload($this->party($a, 'client'), $w, [$this->line($p, 5)]));
        $purchase = $this->make($a, 'purchase-invoices', $this->payload($this->party($a, 'vendor'), $w, [$this->line($p, 5)]));
        $this->actingAs($a)->post($this->url('purchase-invoices', "/{$purchase->id}/post"));
        $foreign = $this->postedSalesInvoice($b, $bw, $bp, 5);

        $attempt = fn (Document $invoice, string $slug = 'sales-returns') => $this->actingAs($a)->post($this->url($slug), [
            'parent_id' => $invoice->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $invoice->items->first()->id, 'quantity' => 1]],
        ]);

        $attempt($draft)->assertSessionHasErrors('parent_id');                    // not posted
        $attempt($purchase)->assertSessionHasErrors('parent_id');                 // a sales return of a purchase invoice
        $attempt($foreign)->assertSessionHasErrors('parent_id');                  // another company's invoice
        $this->assertSame(0, Document::whereIn('type', ['sales_return', 'purchase_return'])->count());

        // a line from a different invoice
        $mine = $this->postedSalesInvoice($a, $w, $p, 5);
        $this->actingAs($a)->post($this->url('sales-returns'), [
            'parent_id' => $mine->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $foreign->items->first()->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('items.0.source_item_id');
    }

    public function test_purchase_return_sends_stock_back_and_is_blocked_when_it_is_gone(): void
    {
        Event::fake([ApprovePurchaseReturn::class]);
        $a = $this->company();
        $w = $this->warehouse($a);
        $vendor = $this->party($a, 'vendor');
        $p = $this->product($a, 'P1');

        $invoice = $this->make($a, 'purchase-invoices', $this->payload($vendor, $w, [$this->line($p, 10, 4)]));
        $this->actingAs($a)->post($this->url('purchase-invoices', "/{$invoice->id}/post"));
        $this->assertSame(10.0, $this->qty($p, $w));
        $item = $invoice->fresh()->items->first();

        $ret = fn (float $qty) => $this->actingAs($a)->post($this->url('purchase-returns'), [
            'parent_id' => $invoice->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $item->id, 'quantity' => $qty]],
        ]);

        $ret(3)->assertSessionHasNoErrors();
        $return = Document::where('type', 'purchase_return')->firstOrFail();
        $this->assertSame('PR-00001', $return->number);
        $this->actingAs($a)->post($this->url('purchase-returns', "/{$return->id}/approve"))->assertSessionHas('success');
        $this->assertSame(7.0, $this->qty($p, $w));
        Event::assertDispatched(ApprovePurchaseReturn::class);

        // the rest was sold meanwhile -> the next purchase return cannot be approved
        app(StockService::class)->adjust($p, $w->id, -7);
        $ret(5)->assertSessionHasNoErrors();
        $second = Document::where('type', 'purchase_return')->latest('id')->firstOrFail();
        $this->actingAs($a)->post($this->url('purchase-returns', "/{$second->id}/approve"))->assertSessionHas('error');
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertSame(0.0, $this->qty($p, $w));
    }

    public function test_returns_cannot_be_edited_but_drafts_can_be_deleted_to_free_the_quantity(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $p = $this->product($a, 'P1');
        $this->stock($p, $w, 50);
        $invoice = $this->postedSalesInvoice($a, $w, $p, 10);
        $item = $invoice->items->first();

        $this->actingAs($a)->post($this->url('sales-returns'), ['parent_id' => $invoice->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $item->id, 'quantity' => 10]]]);
        $return = Document::where('type', 'sales_return')->firstOrFail();

        $this->actingAs($a)->put($this->url('sales-returns', "/{$return->id}"), ['parent_id' => $invoice->id, 'doc_date' => '2026-03-06', 'items' => [['source_item_id' => $item->id, 'quantity' => 1]]])
            ->assertSessionHas('error');
        $this->assertSame(10.0, $return->fresh()->items->first()->quantity);

        $this->actingAs($a)->delete($this->url('sales-returns', "/{$return->id}"))->assertSessionHas('success');
        $this->actingAs($a)->post($this->url('sales-returns'), ['parent_id' => $invoice->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $item->id, 'quantity' => 10]]])
            ->assertSessionHasNoErrors();
    }

    // ───────────── permissions & tenant isolation ─────────────

    public function test_staff_need_the_right_permissions(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $customer = $this->party($a, 'client');
        $p = $this->product($a, 'P1');
        $this->stock($p, $w, 10);
        $doc = $this->make($a, 'sales-invoices', $this->payload($customer, $w, [$this->line($p, 1)]));

        $staff = $this->party($a, 'staff');
        $this->actingAs($staff)->get($this->url('sales-invoices'))->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->post($this->url('sales-invoices'), $this->payload($customer, $w, [$this->line($p, 1)]))->assertSessionHas('error');
        $this->assertSame(1, Document::count());

        $staff->givePermissionTo(['manage-sales-invoices', 'create-sales-invoices']);
        $this->actingAs($staff)->get($this->url('sales-invoices'))->assertOk();
        $this->actingAs($staff)->post($this->url('sales-invoices', "/{$doc->id}/post"))->assertSessionHas('error'); // no post-permission
        $this->assertSame('draft', $doc->fresh()->status);

        $staff->givePermissionTo('post-sales-invoices');
        $this->actingAs($staff)->post($this->url('sales-invoices', "/{$doc->id}/post"))->assertSessionHas('success');
        $this->assertSame($a->id, $doc->fresh()->created_by);
    }

    public function test_other_companies_documents_are_invisible_and_untouchable(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');
        $bw = $this->warehouse($b);
        $bp = $this->product($b, 'P1');
        $this->stock($bp, $bw, 10);
        $secret = $this->make($b, 'sales-invoices', $this->payload($this->party($b, 'client', 'Secret Customer'), $bw, [$this->line($bp, 1)]));

        $this->actingAs($a)->get($this->url('sales-invoices'))->assertInertia(fn ($p) => $p->has('documents.data', 0));
        $this->actingAs($a)->get($this->url('sales-invoices', "/{$secret->id}"))->assertRedirect(route('dashboard'));
        $this->actingAs($a)->post($this->url('sales-invoices', "/{$secret->id}/post"))->assertSessionHas('error');
        $this->actingAs($a)->delete($this->url('sales-invoices', "/{$secret->id}"))->assertSessionHas('error');
        $this->actingAs($a)->put($this->url('sales-invoices', "/{$secret->id}"), $this->payload($this->party($a, 'client'), $this->warehouse($a), [$this->line($this->product($a, 'X'), 1)]))
            ->assertSessionHas('error');
        $this->assertSame('draft', $secret->fresh()->status);
        $this->assertSame(10.0, $this->qty($bp, $bw));
    }

    public function test_a_document_cannot_be_reached_through_another_types_url(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $p = $this->product($a, 'P1');
        $this->stock($p, $w, 10);
        $purchase = $this->make($a, 'purchase-invoices', $this->payload($this->party($a, 'vendor'), $w, [$this->line($p, 1)]));

        // a purchase invoice id inside the sales-invoices routes
        $this->actingAs($a)->get($this->url('sales-invoices', "/{$purchase->id}"))->assertRedirect(route('dashboard'));
        $this->actingAs($a)->post($this->url('sales-invoices', "/{$purchase->id}/post"))->assertSessionHas('error');
        $this->actingAs($a)->delete($this->url('sales-invoices', "/{$purchase->id}"))->assertSessionHas('error');
        $this->assertSame('draft', $purchase->fresh()->status);
        $this->assertSame(10.0, $this->qty($p, $w));
    }

    // ───────────── pages ─────────────

    public function test_index_search_status_filter_and_show_abilities(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $p = $this->product($a, 'P1');
        $this->stock($p, $w, 100);
        $alice = $this->party($a, 'client', 'Alice Corp');
        $bob = $this->party($a, 'client', 'Bob Ltd');

        $one = $this->make($a, 'sales-invoices', $this->payload($alice, $w, [$this->line($p, 1)]));
        $this->make($a, 'sales-invoices', $this->payload($bob, $w, [$this->line($p, 2)]));
        $this->actingAs($a)->post($this->url('sales-invoices', "/{$one->id}/post"));

        $this->actingAs($a)->get($this->url('sales-invoices', '?search=Alice'))->assertInertia(fn ($page) => $page
            ->component('SalesPurchase/Documents/Index', false)->has('documents.data', 1)->where('documents.data.0.party.name', 'Alice Corp'));
        $this->actingAs($a)->get($this->url('sales-invoices', '?status=draft'))->assertInertia(fn ($page) => $page->has('documents.data', 1)->where('documents.data.0.status', 'draft'));
        $this->actingAs($a)->get($this->url('sales-invoices', '?search=SI-00002'))->assertInertia(fn ($page) => $page->has('documents.data', 1));
        $this->actingAs($a)->get($this->url('sales-invoices', '?status=evil'))->assertOk();

        $this->actingAs($a)->get($this->url('sales-invoices', "/{$one->id}"))->assertInertia(fn ($page) => $page
            ->component('SalesPurchase/Documents/Show', false)
            ->where('can.post', false)->where('can.edit', false)->where('can.delete', false)
            ->where('can.createReturn', true)->where('can.returnType', 'sales_return')
            ->has('document.items', 1));

        $this->actingAs($a)->get($this->url('sales-returns', "/create?invoice={$one->id}"))->assertInertia(fn ($page) => $page
            ->component('SalesPurchase/Documents/Form', false)->where('invoice.id', $one->id)->has('remaining'));
        $this->actingAs($a)->get($this->url('sales-returns', '/create'))->assertRedirect(route('salespurchase.sales-invoices.index'));
        $this->actingAs($a)->get($this->url('sales-invoices', '/create'))->assertInertia(fn ($page) => $page
            ->has('parties', 2)->has('warehouses', 1)->has('products', 1));
    }

    // ───────────── deletion guards ─────────────

    public function test_records_used_by_documents_cannot_be_deleted(): void
    {
        $a = $this->company();
        $w = $this->warehouse($a);
        $customer = $this->party($a, 'client');
        $p = $this->product($a, 'P1');
        $this->make($a, 'sales-invoices', $this->payload($customer, $w, [$this->line($p, 1)]));

        $this->actingAs($a)->delete("/product-service/products/{$p->id}")->assertSessionHas('error');
        $this->actingAs($a)->delete("/product-service/warehouses/{$w->id}")->assertSessionHas('error');
        $this->actingAs($a)->delete("/users/{$customer->id}")->assertSessionHas('error');

        $this->assertDatabaseHas('products', ['id' => $p->id]);
        $this->assertDatabaseHas('warehouses', ['id' => $w->id]);
        $this->assertDatabaseHas('users', ['id' => $customer->id]);
    }

    public function test_deleting_a_company_removes_its_documents_too(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');
        $w = $this->warehouse($a);
        $p = $this->product($a, 'P1');
        $this->stock($p, $w, 10);
        $invoice = $this->postedSalesInvoice($a, $w, $p, 5);
        $this->actingAs($a)->post($this->url('sales-returns'), ['parent_id' => $invoice->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $invoice->items->first()->id, 'quantity' => 1]]]);
        $this->make($b, 'sales-proposals', $this->payload($this->party($b, 'client'), $this->warehouse($b), [$this->line($this->product($b, 'B1'), 1)]));

        $admin = User::where('type', 'superadmin')->first();
        $this->actingAs($admin)->delete("/companies/{$a->id}")->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $a->id]);
        $this->assertSame(0, Document::where('created_by', $a->id)->count());
        $this->assertSame(1, Document::where('created_by', $b->id)->count());
        $this->assertSame(0, \DB::table('document_items')->whereNotIn('document_id', Document::pluck('id'))->count());
    }

    public function test_every_document_type_is_registered_with_routes_and_permissions(): void
    {
        foreach (DocumentType::all() as $meta) {
            foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'] as $action) {
                $this->assertTrue(\Route::has("salespurchase.{$meta['slug']}.{$action}"), "salespurchase.{$meta['slug']}.{$action}");
            }
            $this->assertDatabaseHas('permissions', ['name' => "manage-{$meta['slug']}", 'add_on' => 'SalesPurchase']);
        }

        $this->assertDatabaseMissing('permissions', ['name' => 'edit-sales-returns']); // returns are not editable
        $this->assertDatabaseHas('permissions', ['name' => 'approve-sales-returns']);
        $this->assertDatabaseHas('permissions', ['name' => 'post-purchase-invoices']);
    }
}
