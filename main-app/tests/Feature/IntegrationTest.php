<?php

namespace Tests\Feature;

use App\Events\DealWon;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\DealActivityLog;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadActivityLog;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Services\DealService;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\Warehouse;
use Workdo\SalesPurchase\Models\Document;

/** CRM / HRM / Sales talking to each other through core events only (I1). */
class IntegrationTest extends TestCase
{
    private User $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->company('a@test.com');
        $this->actingAs($this->a)->get('/crm/setup')->assertOk();
    }

    private function company(string $email, string $plan = 'Pro'): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create(['name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company', 'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id]);
        $c->assignRole('company');
        $p = Plan::where('name', $plan)->first();
        app(PlanService::class)->assign($c, $p, $p->free_plan ? null : 'year');

        return $c->refresh();
    }

    private function client(User $t): User
    {
        $c = User::create(['name' => 'Acme', 'email' => 'acme@x.com', 'password' => 'secret-pass-1', 'type' => 'client', 'email_verified_at' => now(), 'creator_id' => $t->id, 'created_by' => $t->id]);
        $c->assignRole('client');

        return $c;
    }

    private function product(string $sku = 'P1', float $price = 250): Product
    {
        return Product::create(['name' => 'Widget ' . $sku, 'sku' => $sku, 'type' => 'product', 'sale_price' => $price, 'purchase_price' => 100, 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create(['name' => 'Main', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
    }

    private function deal(array $with = []): Deal
    {
        $pipeline = Pipeline::where('created_by', $this->a->id)->firstOrFail();
        $this->actingAs($this->a)->post('/crm/deals', ['name' => 'Big deal', 'price' => 900, 'pipeline_id' => $pipeline->id, 'client_ids' => $with['clients'] ?? []])->assertSessionHasNoErrors();
        $deal = Deal::latest('id')->firstOrFail();
        $deal->products()->sync($with['products'] ?? []);

        return $deal;
    }

    private function win(Deal $deal): void
    {
        $this->actingAs($this->a)->post("/crm/deals/{$deal->id}/status", ['status' => 'won'])->assertSessionHas('success');
    }

    // ───────────── deal won -> draft proposal ─────────────

    public function test_a_won_deal_drafts_a_proposal_only_when_the_company_switched_it_on(): void
    {
        $deal = $this->deal(['clients' => [$this->client($this->a)->id], 'products' => [$this->product()->id]]);
        $this->warehouse();

        $this->win($deal);

        $this->assertSame(0, Document::where('type', 'sales_proposal')->count());                       // off by default
        $this->assertSame(0, DealActivityLog::where('type', 'automation')->count());
    }

    public function test_the_draft_proposal_has_the_client_the_products_and_a_marker(): void
    {
        $client = $this->client($this->a);
        $p1 = $this->product('P1', 250);
        $p2 = $this->product('P2', 100);
        $warehouse = $this->warehouse();
        $this->actingAs($this->a)->put('/crm/setup/automation', ['draft_proposal_on_win' => true])->assertSessionHas('success');
        $deal = $this->deal(['clients' => [$client->id], 'products' => [$p1->id, $p2->id]]);

        $this->win($deal);

        $doc = Document::where('type', 'sales_proposal')->firstOrFail();
        $this->assertSame('draft', $doc->status);
        $this->assertSame($client->id, $doc->party_id);
        $this->assertSame($warehouse->id, $doc->warehouse_id);
        $this->assertSame($this->a->id, $doc->created_by);
        $this->assertSame("CRM deal #{$deal->id}: Big deal", $doc->notes);
        $this->assertSame(2, $doc->items()->count());
        $this->assertEquals(350, $doc->total_amount);
        $this->assertSame("Draft sales proposal {$doc->number} created.", DealActivityLog::where('deal_id', $deal->id)->where('type', 'automation')->value('remark'));
    }

    public function test_winning_again_does_not_draft_a_second_proposal(): void
    {
        $this->actingAs($this->a)->put('/crm/setup/automation', ['draft_proposal_on_win' => true]);
        $deal = $this->deal(['clients' => [$this->client($this->a)->id], 'products' => [$this->product()->id]]);
        $this->warehouse();

        $this->win($deal);
        $this->actingAs($this->a)->post("/crm/deals/{$deal->id}/status", ['status' => 'active']);
        $this->win($deal);

        $this->assertSame(1, Document::where('type', 'sales_proposal')->count());
        $this->assertSame('A proposal was already drafted for this deal.', DealActivityLog::where('deal_id', $deal->id)->where('type', 'automation')->latest('id')->value('remark'));
    }

    public function test_missing_ingredients_are_explained_not_guessed(): void
    {
        $this->actingAs($this->a)->put('/crm/setup/automation', ['draft_proposal_on_win' => true]);
        $client = $this->client($this->a);
        $product = $this->product();

        $noClient = $this->deal(['products' => [$product->id]]);
        $noProducts = $this->deal(['clients' => [$client->id]]);
        $noWarehouse = $this->deal(['clients' => [$client->id], 'products' => [$product->id]]);

        foreach ([$noClient, $noProducts, $noWarehouse] as $d) {
            $this->win($d);
        }

        $note = fn (Deal $d) => DealActivityLog::where('deal_id', $d->id)->where('type', 'automation')->value('remark');
        $this->assertSame('No proposal drafted: the deal has no client.', $note($noClient));
        $this->assertSame('No proposal drafted: the deal has no products.', $note($noProducts));
        $this->assertSame('No proposal drafted: the company has no warehouse yet.', $note($noWarehouse));
        $this->assertSame(0, Document::count());
    }

    public function test_a_failing_follow_up_never_undoes_the_win(): void
    {
        Event::listen(DealWon::class, fn () => throw new \RuntimeException('sales is down'));
        $deal = $this->deal();

        $this->win($deal);

        $this->assertSame('won', $deal->refresh()->status);
        $this->assertStringContainsString('sales is down', DealActivityLog::where('deal_id', $deal->id)->where('type', 'automation')->value('remark'));
    }

    public function test_the_setting_is_per_company_and_needs_permission(): void
    {
        $b = $this->company('b@test.com');
        $this->actingAs($b)->get('/crm/setup')->assertOk();
        $this->actingAs($this->a)->put('/crm/setup/automation', ['draft_proposal_on_win' => true]);

        $this->assertSame('1', tenantSettings($this->a->id)['crmDraftProposalOnWin']);
        $this->assertArrayNotHasKey('crmDraftProposalOnWin', tenantSettings($b->id));

        $this->actingAs($this->a)->put('/crm/setup/automation', [])->assertSessionHasErrors('draft_proposal_on_win');

        $staff = User::create(['name' => 'S', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $staff->assignRole('staff');
        $staff->givePermissionTo('manage-pipelines');
        $this->actingAs($staff)->put('/crm/setup/automation', ['draft_proposal_on_win' => false])->assertSessionHas('error');
        $this->actingAs($staff)->post('/crm/setup/automation/token')->assertSessionHas('error');
        $this->assertSame('1', tenantSettings($this->a->id)['crmDraftProposalOnWin']);
    }

    // ───────────── web to lead ─────────────

    private function token(?User $as = null): string
    {
        $this->actingAs($as ?? $this->a)->post('/crm/setup/automation/token')->assertSessionHas('success');

        return tenantSettings(($as ?? $this->a)->id)['crmWebToLeadToken'];
    }

    public function test_the_website_form_creates_a_lead_in_the_first_stage_with_the_website_source(): void
    {
        $token = $this->token();
        $url = $this->actingAs($this->a)->get('/crm/setup')->viewData('page')['props']['automation']['webToLeadUrl'];
        $this->assertStringEndsWith("/crm/web-to-lead/{$token}", $url);

        auth()->logout();
        $this->postJson("/crm/web-to-lead/{$token}", ['name' => 'Visitor', 'email' => 'v@site.com', 'phone' => '555', 'subject' => 'Pricing', 'message' => 'Call me'])
            ->assertOk()->assertJson(['ok' => true])->assertHeader('Access-Control-Allow-Origin', '*');

        $lead = Lead::firstOrFail();
        $this->assertSame($this->a->id, $lead->created_by);
        $this->assertSame('Pricing', $lead->subject);
        $this->assertSame('Call me', $lead->notes);
        $this->assertSame('New', $lead->stage->name);
        $this->assertSame(['Website'], $lead->sources()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['created', 'web'], LeadActivityLog::where('lead_id', $lead->id)->pluck('type')->all());
        $this->assertNull(LeadActivityLog::where('lead_id', $lead->id)->where('type', 'web')->value('user_id'));
    }

    public function test_the_form_validates_and_ignores_bots(): void
    {
        $token = $this->token();

        $this->postJson("/crm/web-to-lead/{$token}", ['email' => 'v@site.com'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson("/crm/web-to-lead/{$token}", ['name' => 'V'])->assertStatus(422)->assertJsonValidationErrors('email');         // needs a way to reach them
        $this->postJson("/crm/web-to-lead/{$token}", ['name' => 'V', 'email' => 'nope'])->assertStatus(422);
        $this->postJson("/crm/web-to-lead/{$token}", ['name' => str_repeat('x', 300), 'email' => 'v@site.com'])->assertStatus(422);
        $this->postJson("/crm/web-to-lead/{$token}", ['name' => 'Bot', 'email' => 'b@site.com', 'website_url' => 'http://spam'])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(0, Lead::count());                                                                                               // the bot got nothing

        $this->postJson("/crm/web-to-lead/{$token}", ['name' => 'Only phone', 'phone' => '999'])->assertOk();
        $this->assertSame('Website enquiry', Lead::firstOrFail()->subject);
    }

    public function test_only_the_current_secret_works(): void
    {
        $old = $this->token();
        $new = $this->token();
        $this->assertNotSame($old, $new);

        $this->postJson("/crm/web-to-lead/{$old}", ['name' => 'V', 'email' => 'v@x.com'])->assertNotFound();
        $this->postJson('/crm/web-to-lead/short', ['name' => 'V', 'email' => 'v@x.com'])->assertNotFound();
        $this->postJson('/crm/web-to-lead/' . str_repeat('a', 40), ['name' => 'V', 'email' => 'v@x.com'])->assertNotFound();
        $this->postJson("/crm/web-to-lead/{$new}", ['name' => 'V', 'email' => 'v@x.com'])->assertOk();

        $this->actingAs($this->a)->delete('/crm/setup/automation/token')->assertSessionHas('success');
        $this->postJson("/crm/web-to-lead/{$new}", ['name' => 'V2', 'email' => 'v@x.com'])->assertNotFound();
        $this->postJson('/crm/web-to-lead/', ['name' => 'V2'])->assertNotFound();
        $this->assertSame(1, Lead::count());
    }

    public function test_every_company_has_its_own_secret_and_its_own_leads(): void
    {
        $b = $this->company('b@test.com');
        $this->actingAs($b)->get('/crm/setup')->assertOk();
        $tokenA = $this->token();
        $tokenB = $this->token($b);

        $this->postJson("/crm/web-to-lead/{$tokenA}", ['name' => 'For A', 'email' => 'a@x.com'])->assertOk();
        $this->postJson("/crm/web-to-lead/{$tokenB}", ['name' => 'For B', 'email' => 'b@x.com'])->assertOk();

        $this->assertSame(['For A'], Lead::where('created_by', $this->a->id)->pluck('name')->all());
        $this->assertSame(['For B'], Lead::where('created_by', $b->id)->pluck('name')->all());
        $this->assertSame(LeadStage::where('pipeline_id', Pipeline::where('created_by', $b->id)->value('id'))->orderBy('order')->value('id'), Lead::where('created_by', $b->id)->value('lead_stage_id'));
    }

    public function test_a_company_without_the_crm_module_gets_nothing(): void
    {
        $token = $this->token();
        // the company's plan no longer includes the CRM module
        \App\Models\UserActiveModule::where('user_id', $this->a->id)->where('module', 'Lead')->delete();
        $this->assertFalse(Module_is_active('Lead', $this->a->id));

        $this->postJson("/crm/web-to-lead/{$token}", ['name' => 'V', 'email' => 'v@x.com'])->assertNotFound();
    }

    public function test_the_preflight_request_is_answered(): void
    {
        $token = $this->token();

        $this->call('OPTIONS', "/crm/web-to-lead/{$token}")->assertNoContent()->assertHeader('Access-Control-Allow-Origin', '*');
    }

    // ───────────── dashboard widgets ─────────────

    public function test_the_company_dashboard_shows_one_card_per_module(): void
    {
        $widgets = $this->actingAs($this->a)->get('/dashboard')->assertOk()->viewData('page')['props']['widgets'];

        $keys = collect($widgets)->pluck('key')->all();
        $this->assertContains('hrm', $keys);
        $this->assertContains('crm', $keys);
        $this->assertSame(['hrm', 'crm'], array_values(array_intersect($keys, ['hrm', 'crm'])));       // ordered
        $crm = collect($widgets)->firstWhere('key', 'crm');
        $this->assertSame(['Open leads', 'Open deals', 'Open pipeline value', 'Won this month'], collect($crm['stats'])->pluck('label')->all());
        $this->assertSame(route('crm.dashboard'), $crm['href']);
    }

    public function test_the_widget_numbers_are_the_companys_own(): void
    {
        $b = $this->company('b@test.com');
        $this->actingAs($b)->get('/crm/setup')->assertOk();
        $pipelineB = Pipeline::where('created_by', $b->id)->firstOrFail();

        $deal = $this->deal();
        $deal->update(['price' => 1234]);
        Deal::create(['name' => 'B deal', 'price' => 99999, 'pipeline_id' => $pipelineB->id, 'deal_stage_id' => \Workdo\Lead\Models\DealStage::where('pipeline_id', $pipelineB->id)->value('id'), 'creator_id' => $b->id, 'created_by' => $b->id]);

        $crm = collect($this->actingAs($this->a)->get('/dashboard')->viewData('page')['props']['widgets'])->firstWhere('key', 'crm');
        $stats = collect($crm['stats'])->pluck('value', 'label');

        $this->assertSame(1, $stats['Open deals']);
        $this->assertEquals(1234, $stats['Open pipeline value']);
    }

    public function test_staff_see_only_the_widgets_they_may_open(): void
    {
        $staff = User::create(['name' => 'S', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $staff->assignRole('staff');

        $this->assertSame([], $this->actingAs($staff)->get('/dashboard')->assertOk()->viewData('page')['props']['widgets']);

        $staff->givePermissionTo('view-crm-dashboard');
        $keys = collect($this->actingAs(User::find($staff->id))->get('/dashboard')->viewData('page')['props']['widgets'])->pluck('key')->all();
        $this->assertSame(['crm'], $keys);
    }

    public function test_the_superadmin_dashboard_has_no_company_cards(): void
    {
        $admin = User::where('type', 'superadmin')->first();

        $this->assertSame([], $this->actingAs($admin)->get('/dashboard')->assertOk()->viewData('page')['props']['widgets']);
    }

    // ───────────── review findings ─────────────

    public function test_secret_settings_are_not_shared_with_the_browser(): void
    {
        $token = $this->token();
        setSetting('currencySymbol', 'Rs', $this->a->id);              // a public setting stays available
        $staff = User::create(['name' => 'S', 'email' => 's2@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $staff->assignRole('staff');

        foreach ([$this->a, $staff] as $user) {
            $shared = $this->actingAs($user)->get('/dashboard')->assertOk()->viewData('page')['props']['companyAllSetting'];

            $this->assertArrayNotHasKey('crmWebToLeadToken', $shared);
            $this->assertArrayNotHasKey('crmDraftProposalOnWin', $shared);
            $this->assertArrayNotHasKey('walkInCustomerId', $shared);
            $this->assertSame('Rs', $shared['currencySymbol']);
            $this->assertStringNotContainsString($token, json_encode($shared));
        }
    }
}
