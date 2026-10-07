<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;
use Workdo\Account\Models\ChartOfAccount;
use Workdo\Account\Models\JournalEntry;
use Workdo\Account\Models\Payment;
use Workdo\Account\Services\AccountService;
use Workdo\Account\Services\JournalService;
use Workdo\Account\Services\PaymentService;
use Workdo\Account\Services\ReportService;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\ProductTax;
use Workdo\ProductService\Models\Warehouse;
use Workdo\ProductService\Services\StockService;
use Workdo\SalesPurchase\Models\Document;

class AccountingTest extends TestCase
{
    private Warehouse $wh;
    private User $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->company('a@test.com');
        $this->wh = $this->warehouse($this->a);
    }

    // ───────────── fixtures ─────────────

    private function company(string $email, bool $pro = true): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company',
            'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id,
        ]);
        $c->assignRole('company');
        $plan = $pro ? Plan::where('name', 'Pro')->first() : Plan::where('free_plan', true)->first();
        app(PlanService::class)->assign($c, $plan, $pro ? 'year' : null);

        return $c->refresh();
    }

    private function warehouse(User $t): Warehouse
    {
        return Warehouse::create(['name' => 'Main', 'is_active' => true, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function party(User $t, string $type): User
    {
        $u = User::create(['name' => ucfirst($type) . uniqid(), 'email' => uniqid() . '@t.com', 'password' => 'secret-pass-1', 'type' => $type, 'email_verified_at' => now(), 'creator_id' => $t->id, 'created_by' => $t->id]);
        $u->assignRole($type);

        return $u;
    }

    private function product(User $t, string $sku, float $sale = 25, float $cost = 12.5, string $type = 'product'): Product
    {
        return Product::create(['name' => "Item {$sku}", 'sku' => $sku, 'type' => $type, 'sale_price' => $sale, 'purchase_price' => $cost, 'is_active' => true, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function tax(User $t, float $rate = 18): ProductTax
    {
        return ProductTax::create(['name' => "GST {$rate}", 'rate' => $rate, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function stock(Product $p, float $qty): void
    {
        app(StockService::class)->set($p, $this->wh->id, $qty);
    }

    private function account(User $t, string $code): ChartOfAccount
    {
        return app(AccountService::class)->account($t->id, $code);
    }

    /** Create + post a document through the real endpoints. */
    private function posted(User $actor, string $slug, User $party, array $items, ?Warehouse $wh = null): Document
    {
        $base = '/sales-purchase/' . $slug;
        $this->actingAs($actor)->post($base, ['party_id' => $party->id, 'warehouse_id' => ($wh ?? $this->wh)->id, 'doc_date' => '2026-03-01', 'items' => $items])->assertSessionHasNoErrors();
        $doc = Document::latest('id')->firstOrFail();
        $this->actingAs($actor)->post("{$base}/{$doc->id}/post")->assertSessionHas('success');

        return $doc->refresh();
    }

    private function line(Product $p, float $qty, float $price, float $discount = 0, array $taxIds = []): array
    {
        return ['product_id' => $p->id, 'quantity' => $qty, 'unit_price' => $price, 'discount_amount' => $discount, 'tax_ids' => $taxIds];
    }

    /** [code => ['debit' => x, 'credit' => y]] of an entry */
    private function lines(JournalEntry $entry): array
    {
        $out = [];
        foreach ($entry->items()->with('account')->get() as $item) {
            $out[$item->account->code]['debit'] = ($out[$item->account->code]['debit'] ?? 0) + $item->debit;
            $out[$item->account->code]['credit'] = ($out[$item->account->code]['credit'] ?? 0) + $item->credit;
        }

        return $out;
    }

    private function entryFor(Document $doc): JournalEntry
    {
        return JournalEntry::where('reference_type', $doc->type)->where('reference_id', $doc->id)->firstOrFail();
    }

    // ───────────── chart of accounts ─────────────

    public function test_default_chart_is_created_lazily_once_per_company(): void
    {
        $this->assertSame(0, ChartOfAccount::where('created_by', $this->a->id)->count());

        $this->actingAs($this->a)->get('/account/chart-of-accounts')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Account/ChartOfAccounts/Index', false)->has('accounts.data', 15));
        $this->actingAs($this->a)->get('/account/chart-of-accounts')->assertOk();

        $this->assertSame(15, ChartOfAccount::where('created_by', $this->a->id)->count());
        $this->assertTrue(ChartOfAccount::where('created_by', $this->a->id)->where('code', '1100')->value('is_system'));
        $this->assertSame(['1000', '1010'], ChartOfAccount::where('created_by', $this->a->id)->where('is_bank', true)->orderBy('code')->pluck('code')->all());

        $b = $this->company('b@test.com');
        $this->assertSame(0, ChartOfAccount::where('created_by', $b->id)->count()); // other companies are untouched
    }

    public function test_account_rules(): void
    {
        $b = $this->company('b@test.com');
        $this->actingAs($this->a)->get('/account/chart-of-accounts');
        $this->actingAs($b)->get('/account/chart-of-accounts');

        $url = '/account/chart-of-accounts';
        $this->actingAs($this->a)->post($url, ['code' => '1100', 'name' => 'Dup', 'type' => 'asset'])->assertSessionHasErrors('code');
        $this->actingAs($this->a)->post($url, ['code' => '6000', 'name' => 'Rent', 'type' => 'expense'])->assertSessionHas('success');
        $this->actingAs($b)->post($url, ['code' => '6000', 'name' => 'Rent B', 'type' => 'expense'])->assertSessionHas('success'); // other company, same code
        $this->actingAs($this->a)->post($url, ['code' => '6100', 'name' => 'X', 'type' => 'banana'])->assertSessionHasErrors('type');
        $this->actingAs($this->a)->post($url, ['code' => 'bad code!', 'name' => 'X', 'type' => 'asset'])->assertSessionHasErrors('code');
        $this->actingAs($this->a)->post($url, ['code' => '1500', 'name' => 'Petty', 'type' => 'expense', 'is_bank' => true])->assertSessionHasErrors('is_bank');
        $this->actingAs($this->a)->post($url, ['code' => '1500', 'name' => 'Petty cash', 'type' => 'asset', 'is_bank' => true])->assertSessionHas('success');
    }

    public function test_system_accounts_are_protected_and_used_accounts_are_frozen(): void
    {
        $this->actingAs($this->a)->get('/account/chart-of-accounts');
        $ar = $this->account($this->a, '1100');

        $this->actingAs($this->a)->put("/account/chart-of-accounts/{$ar->id}", ['code' => '9999', 'name' => 'Debtors', 'type' => 'asset', 'is_active' => false, 'is_bank' => true])->assertSessionHas('success');
        $ar->refresh();
        $this->assertSame('Debtors', $ar->name);      // renamed...
        $this->assertSame('1100', $ar->code);         // ...but code, type, flags stay
        $this->assertSame('asset', $ar->type);
        $this->assertTrue($ar->is_active);
        $this->assertFalse($ar->is_bank);
        $this->actingAs($this->a)->delete("/account/chart-of-accounts/{$ar->id}")->assertSessionHas('error');

        // a custom account: free until it has bookings
        $this->actingAs($this->a)->post('/account/chart-of-accounts', ['code' => '6000', 'name' => 'Rent', 'type' => 'expense']);
        $rent = ChartOfAccount::where('code', '6000')->where('created_by', $this->a->id)->firstOrFail();
        $this->assertFalse((bool) $rent->is_system);
        $this->actingAs($this->a)->put("/account/chart-of-accounts/{$rent->id}", ['code' => '6001', 'name' => 'Rent', 'type' => 'expense', 'is_active' => true])->assertSessionHas('success');

        app(JournalService::class)->record($this->a->id, $this->a->id, '2026-03-01', 'Rent', [['account_id' => $rent->id, 'debit' => 100], ['code' => '1000', 'credit' => 100]], null, null, 'manual');
        $this->actingAs($this->a)->put("/account/chart-of-accounts/{$rent->id}", ['code' => '6001', 'name' => 'Rent', 'type' => 'asset', 'is_active' => true])->assertSessionHasErrors('type');
        $this->actingAs($this->a)->delete("/account/chart-of-accounts/{$rent->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $rent->id]);
    }

    public function test_other_companies_accounts_cannot_be_touched(): void
    {
        $b = $this->company('b@test.com');
        $this->actingAs($b)->get('/account/chart-of-accounts');
        $bAccount = $this->account($b, '1100');

        $this->actingAs($this->a)->put("/account/chart-of-accounts/{$bAccount->id}", ['code' => '1100', 'name' => 'Hacked', 'type' => 'asset'])->assertSessionHas('error');
        $this->actingAs($this->a)->delete("/account/chart-of-accounts/{$bAccount->id}")->assertSessionHas('error');
        $this->assertNotSame('Hacked', $bAccount->fresh()->name);
    }

    // ───────────── automatic postings ─────────────

    public function test_sales_invoice_is_booked_with_receivable_sales_tax_and_cost_of_goods(): void
    {
        $p = $this->product($this->a, 'P1', 25, 12.5);
        $this->stock($p, 20);
        $tax = $this->tax($this->a, 18);

        $doc = $this->posted($this->a, 'sales-invoices', $this->party($this->a, 'client'), [$this->line($p, 5, 25, 10, [$tax->id])]);
        $entry = $this->entryFor($doc);

        $this->assertSame('JE-00001', $entry->number);
        $this->assertSame('automatic', $entry->entry_type);
        $this->assertSame(135.7, $doc->total_amount);
        $this->assertEquals([
            '1100' => ['debit' => 135.7, 'credit' => 0],      // Accounts Receivable
            '4100' => ['debit' => 0, 'credit' => 115.0],      // Sales = subtotal - discount
            '2210' => ['debit' => 0, 'credit' => 20.7],       // Tax payable
            '5000' => ['debit' => 62.5, 'credit' => 0],       // COGS 5 x 12.50
            '1200' => ['debit' => 0, 'credit' => 62.5],       // Inventory
        ], $this->lines($entry));
        $this->assertSame($entry->total_debit, $entry->total_credit);
        $this->assertSame(198.2, $entry->total_debit);
    }

    public function test_services_have_no_cost_of_goods(): void
    {
        $service = $this->product($this->a, 'SRV', 100, 0, 'service');

        $doc = $this->posted($this->a, 'sales-invoices', $this->party($this->a, 'client'), [$this->line($service, 1, 100)]);

        $this->assertEquals(['1100' => ['debit' => 100, 'credit' => 0], '4100' => ['debit' => 0, 'credit' => 100]], $this->lines($this->entryFor($doc)));
    }

    public function test_purchase_invoice_splits_inventory_and_service_expense(): void
    {
        $goods = $this->product($this->a, 'G', 10, 4);
        $service = $this->product($this->a, 'S', 10, 0, 'service');
        $tax = $this->tax($this->a, 10);

        $doc = $this->posted($this->a, 'purchase-invoices', $this->party($this->a, 'vendor'), [
            $this->line($goods, 10, 4, 0, [$tax->id]),    // net 40, tax 4
            $this->line($service, 1, 50, 10, [$tax->id]), // net 40, tax 4
        ]);

        $this->assertSame(88.0, $doc->total_amount);
        $this->assertEquals([
            '1200' => ['debit' => 40, 'credit' => 0],
            '5200' => ['debit' => 40, 'credit' => 0],
            '1300' => ['debit' => 8, 'credit' => 0],
            '2000' => ['debit' => 0, 'credit' => 88],
        ], $this->lines($this->entryFor($doc)));
    }

    public function test_returns_reverse_the_booking_pro_rata(): void
    {
        $p = $this->product($this->a, 'P1', 20, 8);
        $this->stock($p, 100);
        $tax = $this->tax($this->a, 10);
        $invoice = $this->posted($this->a, 'sales-invoices', $this->party($this->a, 'client'), [$this->line($p, 10, 20, 20, [$tax->id])]); // 200 - 20 + 18 = 198

        $this->actingAs($this->a)->post('/sales-purchase/sales-returns', ['parent_id' => $invoice->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $invoice->items->first()->id, 'quantity' => 5]]]);
        $return = Document::where('type', 'sales_return')->firstOrFail();
        $this->assertSame(0, JournalEntry::where('reference_type', 'sales_return')->count()); // a draft return books nothing

        $this->actingAs($this->a)->post("/sales-purchase/sales-returns/{$return->id}/approve")->assertSessionHas('success');

        $this->assertEquals([
            '4200' => ['debit' => 90, 'credit' => 0],   // net 100 - 10
            '2210' => ['debit' => 9, 'credit' => 0],
            '1100' => ['debit' => 0, 'credit' => 99],
            '1200' => ['debit' => 40, 'credit' => 0],   // 5 x cost 8 back into stock
            '5000' => ['debit' => 0, 'credit' => 40],
        ], $this->lines($this->entryFor($return->refresh())));

        // purchase side
        $goods = $this->product($this->a, 'G', 10, 4);
        $purchase = $this->posted($this->a, 'purchase-invoices', $this->party($this->a, 'vendor'), [$this->line($goods, 10, 4, 0, [$tax->id])]); // 40 + 4
        $this->actingAs($this->a)->post('/sales-purchase/purchase-returns', ['parent_id' => $purchase->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $purchase->items->first()->id, 'quantity' => 5]]]);
        $pr = Document::where('type', 'purchase_return')->firstOrFail();
        $this->actingAs($this->a)->post("/sales-purchase/purchase-returns/{$pr->id}/approve")->assertSessionHas('success');

        $this->assertEquals([
            '1200' => ['debit' => 0, 'credit' => 20],
            '1300' => ['debit' => 0, 'credit' => 2],
            '2000' => ['debit' => 22, 'credit' => 0],
        ], $this->lines($this->entryFor($pr->refresh())));
    }

    public function test_booking_is_idempotent(): void
    {
        $p = $this->product($this->a, 'P1');
        $this->stock($p, 10);
        $doc = $this->posted($this->a, 'sales-invoices', $this->party($this->a, 'client'), [$this->line($p, 1, 25)]);

        $again = app(JournalService::class)->postDocument($doc->fresh());
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame($this->entryFor($doc)->id, $again->id);
    }

    public function test_companies_without_the_accounting_plan_are_not_booked(): void
    {
        $free = $this->company('free@test.com', pro: false);
        $wh = $this->warehouse($free);
        $p = $this->product($free, 'P1');
        app(StockService::class)->set($p, $wh->id, 10);

        $doc = $this->posted($free, 'sales-invoices', $this->party($free, 'client'), [$this->line($p, 1, 25)], $wh);

        $this->assertSame('posted', $doc->status);
        $this->assertSame(0, JournalEntry::where('created_by', $free->id)->count());
    }

    public function test_a_failing_booking_rolls_back_the_whole_posting(): void
    {
        $p = $this->product($this->a, 'P1');
        $this->stock($p, 10);
        $this->actingAs($this->a)->get('/account/chart-of-accounts');
        $this->account($this->a, '4100')->forceFill(['is_active' => false])->save(); // Sales account switched off behind the scenes

        $base = '/sales-purchase/sales-invoices';
        $this->actingAs($this->a)->post($base, ['party_id' => $this->party($this->a, 'client')->id, 'warehouse_id' => $this->wh->id, 'doc_date' => '2026-03-01', 'items' => [$this->line($p, 4, 25)]]);
        $doc = Document::firstOrFail();

        $this->actingAs($this->a)->post("{$base}/{$doc->id}/post")->assertSessionHas('error');

        $this->assertSame('draft', $doc->fresh()->status);
        $this->assertSame(10.0, app(StockService::class)->quantity($p->id, $this->wh->id), 'stock must be rolled back too');
        $this->assertSame(0, JournalEntry::count());
    }

    // ───────────── manual journal ─────────────

    private function manual(array $overrides = [])
    {
        $this->actingAs($this->a)->get('/account/chart-of-accounts');
        $cash = $this->account($this->a, '1000');
        $equity = $this->account($this->a, '3000');

        return $this->actingAs($this->a)->post('/account/journal-entries', $overrides + [
            'journal_date' => '2026-01-01', 'description' => 'Opening balance',
            'lines' => [['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0], ['account_id' => $equity->id, 'debit' => 0, 'credit' => 1000]],
        ]);
    }

    public function test_manual_entries_must_balance(): void
    {
        $this->manual()->assertSessionHasNoErrors();
        $entry = JournalEntry::firstOrFail();
        $this->assertSame('manual', $entry->entry_type);
        $this->assertSame(1000.0, $entry->total_debit);

        $cash = $this->account($this->a, '1000');
        $equity = $this->account($this->a, '3000');

        $this->manual(['lines' => [['account_id' => $cash->id, 'debit' => 100], ['account_id' => $equity->id, 'credit' => 99]]])->assertSessionHasErrors('lines');
        $this->manual(['lines' => [['account_id' => $cash->id, 'debit' => 100]]])->assertSessionHasErrors('lines');
        $this->manual(['lines' => [['account_id' => $cash->id, 'debit' => 100, 'credit' => 100], ['account_id' => $equity->id, 'credit' => 0]]])->assertSessionHasErrors('lines');
        $this->manual(['lines' => [['account_id' => $cash->id, 'debit' => 0], ['account_id' => $equity->id, 'credit' => 0]]])->assertSessionHasErrors('lines');
        $this->manual(['lines' => [['account_id' => $cash->id, 'debit' => -5], ['account_id' => $equity->id, 'credit' => -5]]])->assertSessionHasErrors();
        $this->manual(['description' => ''])->assertSessionHasErrors('description');
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_manual_entries_reject_foreign_and_inactive_accounts_and_can_be_deleted(): void
    {
        $b = $this->company('b@test.com');
        $this->actingAs($b)->get('/account/chart-of-accounts');
        $this->manual();
        $cash = $this->account($this->a, '1000');
        $equity = $this->account($this->a, '3000');
        $foreign = $this->account($b, '1000');

        $this->manual(['lines' => [['account_id' => $foreign->id, 'debit' => 5], ['account_id' => $equity->id, 'credit' => 5]]])->assertSessionHasErrors('lines.0.account_id');

        $rent = ChartOfAccount::create(['code' => '6000', 'name' => 'Rent', 'type' => 'expense', 'is_active' => false, 'created_by' => $this->a->id]);
        $this->manual(['lines' => [['account_id' => $rent->id, 'debit' => 5], ['account_id' => $cash->id, 'credit' => 5]]])->assertSessionHasErrors('lines.0.account_id');

        $entry = JournalEntry::where('created_by', $this->a->id)->firstOrFail();
        $this->actingAs($b)->delete("/account/journal-entries/{$entry->id}")->assertSessionHas('error');
        $this->actingAs($b)->get("/account/journal-entries/{$entry->id}")->assertRedirect(route('dashboard'));
        $this->actingAs($this->a)->delete("/account/journal-entries/{$entry->id}")->assertRedirect(route('account.journal-entries.index'));
        $this->assertSame(0, JournalEntry::where('created_by', $this->a->id)->count());
    }

    public function test_automatic_entries_cannot_be_deleted(): void
    {
        $p = $this->product($this->a, 'P1');
        $this->stock($p, 10);
        $doc = $this->posted($this->a, 'sales-invoices', $this->party($this->a, 'client'), [$this->line($p, 1, 25)]);
        $entry = $this->entryFor($doc);

        $this->actingAs($this->a)->delete("/account/journal-entries/{$entry->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
        $this->actingAs($this->a)->get("/account/journal-entries/{$entry->id}")->assertInertia(fn ($page) => $page->where('canDelete', false)->has('entry.items', 4));
    }

    // ───────────── payments ─────────────

    private function receivable(User $client = null, float $qty = 4, float $price = 25): array
    {
        $p = $this->product($this->a, 'R' . uniqid(), $price, 10);
        $this->stock($p, 100);
        $invoice = $this->posted($this->a, 'sales-invoices', $client ?? $this->party($this->a, 'client'), [$this->line($p, $qty, $price)]); // total = qty * price

        return [$invoice, $this->account($this->a, '1010')];
    }

    public function test_customer_payments_settle_the_invoice_and_book_cash_against_receivable(): void
    {
        [$invoice, $bank] = $this->receivable(null, 4, 25); // 100.00
        $pay = fn (float $amount) => $this->actingAs($this->a)->post('/account/customer-payments', [
            'document_id' => $invoice->id, 'account_id' => $bank->id, 'amount' => $amount, 'payment_date' => '2026-03-10', 'reference' => 'UTR1',
        ]);

        $pay(40)->assertSessionHas('success');
        $this->assertSame(40.0, $invoice->fresh()->paid_amount);
        $payment = Payment::firstOrFail();
        $this->assertEquals(['1010' => ['debit' => 40, 'credit' => 0], '1100' => ['debit' => 0, 'credit' => 40]],
            $this->lines(JournalEntry::where('reference_type', 'customer_payment')->where('reference_id', $payment->id)->firstOrFail()));

        $pay(60.01)->assertSessionHasErrors('amount');   // more than the 60 still open
        $pay(0)->assertSessionHasErrors('amount');
        $pay(60)->assertSessionHas('success');
        $this->assertSame(100.0, $invoice->fresh()->paid_amount);
        $pay(0.01)->assertSessionHasErrors('amount');    // fully paid

        $this->actingAs($this->a)->get('/account/customer-payments')->assertInertia(fn ($p) => $p->has('payments.data', 2)->has('invoices', 0)->has('accounts', 2));
    }

    public function test_outstanding_amount_respects_approved_returns(): void
    {
        [$invoice, $bank] = $this->receivable(null, 4, 25); // 100
        $this->actingAs($this->a)->post('/sales-purchase/sales-returns', ['parent_id' => $invoice->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $invoice->items->first()->id, 'quantity' => 1]]]);
        $return = Document::where('type', 'sales_return')->firstOrFail();

        $svc = app(PaymentService::class);
        $this->assertSame(100.0, $svc->outstanding($invoice->fresh()));   // a draft return changes nothing
        $this->actingAs($this->a)->post("/sales-purchase/sales-returns/{$return->id}/approve");
        $this->assertSame(75.0, $svc->outstanding($invoice->fresh()));

        $this->actingAs($this->a)->post('/account/customer-payments', ['document_id' => $invoice->id, 'account_id' => $bank->id, 'amount' => 80, 'payment_date' => '2026-03-10'])->assertSessionHasErrors('amount');
        $this->actingAs($this->a)->post('/account/customer-payments', ['document_id' => $invoice->id, 'account_id' => $bank->id, 'amount' => 75, 'payment_date' => '2026-03-10'])->assertSessionHas('success');
    }

    public function test_deleting_a_payment_restores_the_invoice_and_removes_its_booking(): void
    {
        [$invoice, $bank] = $this->receivable();
        $this->actingAs($this->a)->post('/account/customer-payments', ['document_id' => $invoice->id, 'account_id' => $bank->id, 'amount' => 30, 'payment_date' => '2026-03-10']);
        $payment = Payment::firstOrFail();

        $this->actingAs($this->a)->delete("/account/customer-payments/{$payment->id}")->assertSessionHas('success');

        $this->assertSame(0.0, $invoice->fresh()->paid_amount);
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, JournalEntry::where('reference_type', 'customer_payment')->count());
        $this->assertSame(1, JournalEntry::count()); // the invoice booking stays
    }

    public function test_payment_input_rules_and_isolation(): void
    {
        [$invoice, $bank] = $this->receivable();
        $b = $this->company('b@test.com');
        $this->actingAs($b)->get('/account/chart-of-accounts');
        $foreignBank = $this->account($b, '1010');
        $ar = $this->account($this->a, '1100'); // not a bank account
        $draftPurchaseParty = $this->party($this->a, 'vendor');

        $pay = fn (array $o) => $this->actingAs($this->a)->post('/account/customer-payments', $o + ['document_id' => $invoice->id, 'account_id' => $bank->id, 'amount' => 10, 'payment_date' => '2026-03-10']);

        $pay(['account_id' => $ar->id])->assertSessionHasErrors('account_id');          // must be a bank/cash account
        $pay(['account_id' => $foreignBank->id])->assertSessionHasErrors('account_id'); // other company's account
        $pay(['payment_date' => 'soon'])->assertSessionHasErrors('payment_date');
        $this->actingAs($b)->post('/account/customer-payments', ['document_id' => $invoice->id, 'account_id' => $foreignBank->id, 'amount' => 10, 'payment_date' => '2026-03-10'])
            ->assertSessionHasErrors('document_id');                                      // other company's invoice
        $this->assertSame(0, Payment::count());

        // a draft invoice and a purchase invoice cannot be paid as customer invoices
        $p = $this->product($this->a, 'D1');
        $this->actingAs($this->a)->post('/sales-purchase/sales-invoices', ['party_id' => $this->party($this->a, 'client')->id, 'warehouse_id' => $this->wh->id, 'doc_date' => '2026-03-01', 'items' => [$this->line($p, 1, 5)]]);
        $draft = Document::latest('id')->firstOrFail();
        $pay(['document_id' => $draft->id])->assertSessionHasErrors('document_id');
        $purchase = $this->posted($this->a, 'purchase-invoices', $draftPurchaseParty, [$this->line($p, 1, 5)]);
        $pay(['document_id' => $purchase->id])->assertSessionHasErrors('document_id');

        // another company cannot delete my payment
        $pay([])->assertSessionHas('success');
        $payment = Payment::firstOrFail();
        $this->actingAs($b)->delete("/account/customer-payments/{$payment->id}")->assertSessionHas('error');
        $this->assertSame(1, Payment::count());
    }

    public function test_vendor_payments_mirror_customer_payments(): void
    {
        $g = $this->product($this->a, 'G', 10, 4);
        $purchase = $this->posted($this->a, 'purchase-invoices', $this->party($this->a, 'vendor'), [$this->line($g, 10, 4)]); // 40
        $bank = $this->account($this->a, '1000');

        $this->actingAs($this->a)->post('/account/vendor-payments', ['document_id' => $purchase->id, 'account_id' => $bank->id, 'amount' => 25, 'payment_date' => '2026-03-10'])->assertSessionHas('success');
        $payment = Payment::firstOrFail();

        $this->assertSame('vendor', $payment->kind);
        $this->assertSame(25.0, $purchase->fresh()->paid_amount);
        $this->assertEquals(['2000' => ['debit' => 25, 'credit' => 0], '1000' => ['debit' => 0, 'credit' => 25]],
            $this->lines(JournalEntry::where('reference_type', 'vendor_payment')->firstOrFail()));

        // a sales invoice cannot be paid through the vendor screen
        $p = $this->product($this->a, 'S1');
        $this->stock($p, 5);
        $sales = $this->posted($this->a, 'sales-invoices', $this->party($this->a, 'client'), [$this->line($p, 1, 5)]);
        $this->actingAs($this->a)->post('/account/vendor-payments', ['document_id' => $sales->id, 'account_id' => $bank->id, 'amount' => 5, 'payment_date' => '2026-03-10'])->assertSessionHasErrors('document_id');

        $this->actingAs($this->a)->delete("/account/customer-payments/{$payment->id}")->assertSessionHas('error'); // wrong screen
        $this->actingAs($this->a)->delete("/account/vendor-payments/{$payment->id}")->assertSessionHas('success');
        $this->assertSame(0.0, $purchase->fresh()->paid_amount);
    }

    // ───────────── reports ─────────────

    /** Opening capital 5000, purchase 10 x 4 (+10% tax) paid in part, sale 5 x 25 (-10, +18%) with return of 2, one expense. */
    private function scenario(): void
    {
        $this->manual(['journal_date' => '2026-01-01', 'description' => 'Capital', 'lines' => [
            ['account_id' => $this->account($this->a, '1010')->id, 'debit' => 5000], ['account_id' => $this->account($this->a, '3000')->id, 'credit' => 5000],
        ]]);

        $tax10 = $this->tax($this->a, 10);
        $tax18 = $this->tax($this->a, 18);
        $goods = $this->product($this->a, 'G', 25, 4);

        $purchase = $this->posted($this->a, 'purchase-invoices', $this->party($this->a, 'vendor'), [$this->line($goods, 10, 4, 0, [$tax10->id])]); // 44
        $this->actingAs($this->a)->post('/account/vendor-payments', ['document_id' => $purchase->id, 'account_id' => $this->account($this->a, '1010')->id, 'amount' => 20, 'payment_date' => '2026-03-02']);

        $client = $this->party($this->a, 'client');
        $sale = $this->posted($this->a, 'sales-invoices', $client, [$this->line($goods, 5, 25, 10, [$tax18->id])]); // 115 + 20.70 = 135.70
        $this->actingAs($this->a)->post('/account/customer-payments', ['document_id' => $sale->id, 'account_id' => $this->account($this->a, '1000')->id, 'amount' => 50, 'payment_date' => '2026-03-15']);

        $this->actingAs($this->a)->post('/sales-purchase/sales-returns', ['parent_id' => $sale->id, 'doc_date' => '2026-03-20', 'items' => [['source_item_id' => $sale->items->first()->id, 'quantity' => 2]]]);
        $return = Document::where('type', 'sales_return')->firstOrFail();
        $this->actingAs($this->a)->post("/sales-purchase/sales-returns/{$return->id}/approve");

        $rent = ChartOfAccount::create(['code' => '6000', 'name' => 'Rent', 'type' => 'expense', 'is_active' => true, 'created_by' => $this->a->id]);
        app(JournalService::class)->record($this->a->id, $this->a->id, '2026-03-25', 'March rent', [['account_id' => $rent->id, 'debit' => 300], ['code' => '1000', 'credit' => 300]], null, null, 'manual');
    }

    public function test_trial_balance_always_balances(): void
    {
        $this->scenario();
        $report = app(ReportService::class)->trialBalance($this->a->id, '2026-12-31');

        $this->assertSame($report['total_debit'], $report['total_credit']);
        $this->assertGreaterThan(0, $report['total_debit']);

        // in mid January only the opening capital exists
        $early = app(ReportService::class)->trialBalance($this->a->id, '2026-01-15');
        $this->assertSame($early['total_debit'], $early['total_credit']);
        $this->assertSame(['1010', '3000'], array_column($early['rows'], 'code'));
    }

    public function test_profit_and_loss(): void
    {
        $this->scenario();
        $pl = app(ReportService::class)->profitLoss($this->a->id, '2026-01-01', '2026-12-31');

        // Sales 115, Sales returns -(2/5 * 115 = 46), COGS 5x4=20 - returned 2x4=8 => 12, rent 300
        $this->assertSame(69.0, $pl['total_revenue']);
        $this->assertSame(312.0, $pl['total_expenses']);
        $this->assertSame(-243.0, $pl['net_profit']);

        $line = fn (array $rows, string $code) => collect($rows)->firstWhere('code', $code)['amount'] ?? null;
        $this->assertSame(115.0, $line($pl['revenue'], '4100'));
        $this->assertSame(-46.0, $line($pl['revenue'], '4200'));
        $this->assertSame(12.0, $line($pl['expenses'], '5000'));

        // a period that contains no sale
        $feb = app(ReportService::class)->profitLoss($this->a->id, '2026-02-01', '2026-02-28');
        $this->assertSame(0.0, $feb['total_revenue']);
    }

    public function test_balance_sheet_balances_and_shows_current_earnings(): void
    {
        $this->scenario();
        $bs = app(ReportService::class)->balanceSheet($this->a->id, '2026-12-31');

        $this->assertTrue($bs['balanced']);
        $this->assertSame(-243.0, $bs['current_earnings']);
        $this->assertSame(round($bs['total_liabilities'] + $bs['total_equity'], 2), $bs['total_assets']);

        $amount = fn (string $code, array $rows) => collect($rows)->firstWhere('code', $code)['amount'] ?? null;
        $this->assertSame(5000.0, $amount('3000', $bs['equity']));
        $this->assertSame(24.0, $amount('2000', $bs['liabilities']));            // 44 owed - 20 paid
        $this->assertSame(4.0, $amount('1300', $bs['assets']));                  // input tax
        $this->assertSame(28.0, $amount('1200', $bs['assets']));                // inventory: 40 bought - 20 sold + 8 back

        // as of an early date the books still balance
        $this->assertTrue(app(ReportService::class)->balanceSheet($this->a->id, '2026-01-15')['balanced']);
    }

    public function test_ledger_has_an_opening_balance_and_a_running_balance(): void
    {
        $this->scenario();
        $ar = $this->account($this->a, '1100');

        $march = app(ReportService::class)->ledger($this->a->id, $ar, '2026-03-16', '2026-12-31');
        $this->assertSame(85.7, $march['opening']);   // 135.70 invoiced - 50 received before the 16th
        $this->assertCount(1, $march['lines']);       // the return on 20 March
        $this->assertSame(-54.28, round($march['lines'][0]['balance'] - $march['opening'], 2)); // 2/5 of 135.70
        $this->assertSame($march['lines'][0]['balance'], $march['closing']);

        $all = app(ReportService::class)->ledger($this->a->id, $ar, '2026-01-01', '2026-12-31');
        $this->assertSame(0.0, $all['opening']);
        $this->assertSame([135.7, 85.7, 31.42], array_column($all['lines'], 'balance'));

        // credit-normal accounts count the other way
        $ap = app(ReportService::class)->ledger($this->a->id, $this->account($this->a, '2000'), '2026-01-01', '2026-12-31');
        $this->assertSame([44.0, 24.0], array_column($ap['lines'], 'balance'));
    }

    public function test_report_page_and_input_sanitising(): void
    {
        $this->scenario();
        $get = fn (string $q) => $this->actingAs($this->a)->get('/account/reports' . $q);

        $get('')->assertOk()->assertInertia(fn ($p) => $p->component('Account/Reports/Index', false)->where('report', 'trial-balance')->has('data.rows'));
        $get('?report=profit-loss&from=2026-01-01&to=2026-12-31')->assertInertia(fn ($p) => $p->where('data.net_profit', -243));
        $get('?report=balance-sheet&to=2026-12-31')->assertInertia(fn ($p) => $p->where('data.balanced', true));
        $get('?report=ledger&account=' . $this->account($this->a, '1100')->id)->assertInertia(fn ($p) => $p->where('data.account.code', '1100'));
        $get('?report=hack&from=garbage&to=%27%3B--')->assertOk()->assertInertia(fn ($p) => $p->where('report', 'trial-balance'));
        $get('?report=ledger&account=999999')->assertOk(); // unknown account falls back to receivable
        $get('?report=profit-loss&from=2026-12-31&to=2026-01-01')->assertInertia(fn ($p) => $p->where('params.from', '2026-01-01')); // swapped dates are fixed
    }

    public function test_reports_never_mix_companies(): void
    {
        $b = $this->company('b@test.com');
        $this->scenario();
        $this->actingAs($b)->get('/account/reports?report=trial-balance')->assertInertia(fn ($p) => $p->where('data.rows', []));
        $this->assertSame(0.0, app(ReportService::class)->profitLoss($b->id, '2026-01-01', '2026-12-31')['total_revenue']);
    }

    // ───────────── permissions & lifecycle ─────────────

    public function test_staff_need_accounting_permissions(): void
    {
        $staff = $this->party($this->a, 'staff');

        foreach (['/account/chart-of-accounts', '/account/journal-entries', '/account/customer-payments', '/account/vendor-payments', '/account/reports'] as $url) {
            $this->actingAs($staff)->get($url)->assertRedirect(route('dashboard'));
        }
        $this->actingAs($staff)->post('/account/journal-entries', [])->assertSessionHas('error');

        $staff->givePermissionTo('manage-account-reports');
        $this->actingAs($staff)->get('/account/reports')->assertOk();
        $this->actingAs($staff)->get('/account/journal-entries')->assertRedirect(route('dashboard'));
    }

    public function test_free_plan_companies_cannot_open_accounting(): void
    {
        $free = $this->company('free@test.com', pro: false);

        $this->actingAs($free)->get('/account/reports')->assertRedirect(route('dashboard'));
        $this->actingAs($free)->get('/account/chart-of-accounts')->assertRedirect(route('dashboard'));
    }

    public function test_records_with_bookings_block_deletes_and_deleting_a_company_cleans_everything(): void
    {
        [$invoice, $bank] = $this->receivable();
        $this->actingAs($this->a)->post('/account/customer-payments', ['document_id' => $invoice->id, 'account_id' => $bank->id, 'amount' => 10, 'payment_date' => '2026-03-10']);
        $b = $this->company('b@test.com');
        $this->actingAs($b)->get('/account/chart-of-accounts');

        $admin = User::where('type', 'superadmin')->first();
        $this->actingAs($admin)->delete("/companies/{$this->a->id}")->assertSessionHas('success');

        $this->assertSame(0, ChartOfAccount::where('created_by', $this->a->id)->count());
        $this->assertSame(0, JournalEntry::where('created_by', $this->a->id)->count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Document::where('created_by', $this->a->id)->count());
        $this->assertSame(15, ChartOfAccount::where('created_by', $b->id)->count());
    }

    public function test_backfill_books_documents_posted_before_the_company_had_accounting(): void
    {
        $free = $this->company('late@test.com', pro: false);
        $wh = $this->warehouse($free);
        $p = $this->product($free, 'P1', 25, 10);
        app(StockService::class)->set($p, $wh->id, 20);
        $client = $this->party($free, 'client');

        $first = $this->posted($free, 'sales-invoices', $client, [$this->line($p, 2, 25)], $wh);
        $this->actingAs($free)->post('/sales-purchase/sales-returns', ['parent_id' => $first->id, 'doc_date' => '2026-03-05', 'items' => [['source_item_id' => $first->items->first()->id, 'quantity' => 1]]]);
        $return = Document::where('type', 'sales_return')->firstOrFail();
        $this->actingAs($free)->post("/sales-purchase/sales-returns/{$return->id}/approve")->assertSessionHas('success');
        $draft = Document::create(['type' => 'sales_invoice', 'number' => 'SI-00099', 'party_id' => $client->id, 'warehouse_id' => $wh->id, 'doc_date' => '2026-03-01', 'created_by' => $free->id]);
        $this->assertSame(0, JournalEntry::where('created_by', $free->id)->count());

        app(PlanService::class)->assign($free, Plan::where('name', 'Pro')->first(), 'year'); // the company buys Accounting later

        $this->artisan('account:backfill')->assertSuccessful();
        $this->assertSame(2, JournalEntry::where('created_by', $free->id)->count()); // invoice + approved return, never the draft
        $this->assertNull(JournalEntry::where('reference_id', $draft->id)->first());

        $this->artisan('account:backfill', ['company' => $free->id])->assertSuccessful(); // idempotent
        $this->assertSame(2, JournalEntry::where('created_by', $free->id)->count());

        $trial = app(ReportService::class)->trialBalance($free->id, '2026-12-31');
        $this->assertSame($trial['total_debit'], $trial['total_credit']);
        $this->assertSame(0, JournalEntry::where('created_by', $this->a->id)->count()); // other companies untouched
    }

    public function test_permissions_and_routes_exist(): void
    {
        foreach (['manage-chart-of-accounts', 'manage-journal-entries', 'manage-customer-payments', 'delete-vendor-payments', 'manage-account-reports'] as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name, 'add_on' => 'Account']);
        }
        foreach (['account.chart-of-accounts.index', 'account.journal-entries.create', 'account.customer-payments.store', 'account.vendor-payments.destroy', 'account.reports.index'] as $route) {
            $this->assertTrue(\Route::has($route), $route);
        }
    }
}
