<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\DealStage;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Models\Source;
use Workdo\Lead\Services\DealService;

class CrmReportsTest extends TestCase
{
    private User $a;

    private Pipeline $sales;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->a = $this->company('a@test.com');
        $this->sales = $this->seedCrm($this->a);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function company(string $email): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create(['name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company', 'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id]);
        $c->assignRole('company');
        $p = Plan::where('name', 'Pro')->first();
        app(PlanService::class)->assign($c, $p, $p->free_plan ? null : 'year');

        return $c->refresh();
    }

    private function seedCrm(User $t): Pipeline
    {
        $this->actingAs($t)->get('/crm/setup')->assertOk();

        return Pipeline::where('created_by', $t->id)->firstOrFail();
    }

    private function staff(string $email, array $permissions): User
    {
        $u = User::create(['name' => ucfirst($email), 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $u->assignRole('staff');
        $u->givePermissionTo($permissions);

        return $u;
    }

    private function lead(string $createdAt, array $extra = [], ?Pipeline $pipeline = null, ?User $owner = null): Lead
    {
        $pipeline ??= $this->sales;
        $owner ??= $this->a;
        $lead = Lead::create($extra + [
            'subject' => 'L', 'name' => 'N', 'pipeline_id' => $pipeline->id, 'lead_stage_id' => LeadStage::where('pipeline_id', $pipeline->id)->orderBy('order')->value('id'),
            'creator_id' => $owner->id, 'created_by' => $owner->id === $this->a->id || $owner->type === 'staff' ? $this->a->id : $owner->id,
        ]);
        $lead->forceFill(['created_at' => $createdAt])->save();

        return $lead;
    }

    private function deal(string $status, float $price, ?string $closedAt = null, array $extra = []): Deal
    {
        $deal = Deal::create($extra + [
            'name' => 'D', 'price' => $price, 'pipeline_id' => $this->sales->id, 'deal_stage_id' => DealStage::where('pipeline_id', $this->sales->id)->orderBy('order')->value('id'),
            'status' => $status, 'closed_at' => $closedAt, 'creator_id' => $this->a->id, 'created_by' => $this->a->id,
        ]);

        return $deal;
    }

    private function report(?User $as = null, string $query = ''): array
    {
        return $this->actingAs($as ?? $this->a)->get('/crm/reports?from=2026-01-01&to=2026-06-30&pipeline=' . $this->sales->id . $query)->assertOk()->viewData('page')['props'];
    }

    // ───────────── lead report ─────────────

    public function test_lead_numbers_by_stage_source_user_and_month(): void
    {
        $stages = LeadStage::where('pipeline_id', $this->sales->id)->orderBy('order')->pluck('id', 'name');
        $web = Source::where('created_by', $this->a->id)->where('name', 'Website')->firstOrFail();
        $sam = $this->staff('sam@test.com', ['manage-leads']);

        $l1 = $this->lead('2026-02-10', ['lead_stage_id' => $stages['New']]);
        $l2 = $this->lead('2026-02-20', ['lead_stage_id' => $stages['Qualified']]);
        $l3 = $this->lead('2026-05-05', ['lead_stage_id' => $stages['Qualified'], 'is_converted' => true]);
        $this->lead('2025-12-31');                                                           // before the period
        $l1->sources()->attach($web->id);
        $l2->sources()->attach($web->id);
        $l1->users()->attach($sam->id);
        $l2->users()->attach($sam->id);
        $l3->users()->attach($this->a->id);

        $r = $this->report()['leads'];

        $this->assertSame(['leads' => 3, 'converted' => 1, 'conversion_rate' => 33.3], $r['totals']);
        $this->assertSame(['New' => 1, 'Contacted' => 0, 'Qualified' => 2, 'Proposal Sent' => 0], collect($r['byStage'])->pluck('value', 'name')->all());
        $this->assertSame(['Website' => 2, 'No source' => 1], collect($r['bySource'])->pluck('value', 'name')->all());
        $this->assertSame(['Sam@test.com' => 2, 'a@test.com' => 1], collect($r['byUser'])->pluck('value', 'name')->all());

        $months = collect($r['byMonth'])->pluck('leads', 'month')->all();
        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'], array_keys($months));
        $this->assertSame([0, 2, 0, 0, 1, 0], array_values($months));
    }

    public function test_other_pipelines_and_other_companies_are_left_out(): void
    {
        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners']);
        $partners = Pipeline::where('name', 'Partners')->firstOrFail();
        $b = $this->company('b@test.com');
        $bPipeline = $this->seedCrm($b);

        $this->lead('2026-03-01');
        $this->lead('2026-03-02', [], $partners);
        $this->lead('2026-03-03', [], $bPipeline, $b);

        $this->assertSame(1, $this->report()['leads']['totals']['leads']);
        $this->assertSame(1, $this->actingAs($this->a)->get('/crm/reports?from=2026-01-01&to=2026-06-30&pipeline=' . $partners->id)->viewData('page')['props']['leads']['totals']['leads']);
        // somebody else's pipeline id is ignored (the pipeline chosen last stays)
        $this->assertSame($partners->id, $this->actingAs($this->a)->get('/crm/reports?pipeline=' . $bPipeline->id)->viewData('page')['props']['pipeline']['id']);
    }

    // ───────────── deal report ─────────────

    public function test_deal_value_is_counted_in_the_month_it_was_decided(): void
    {
        $sam = $this->staff('sam@test.com', ['manage-deals']);
        $w1 = $this->deal('won', 1000, '2026-02-10 09:00:00');
        $w2 = $this->deal('won', 4000, '2026-05-20 09:00:00');
        $this->deal('won', 9999, '2025-11-01 09:00:00');           // outside the period
        $this->deal('lost', 2500, '2026-05-02 09:00:00');
        $this->deal('active', 700);
        $this->deal('active', 300, null, ['deal_stage_id' => DealStage::where('pipeline_id', $this->sales->id)->where('name', 'Meeting')->value('id')]);
        $w1->users()->attach($sam->id);
        $w2->users()->attach($sam->id);

        $r = $this->report()['deals'];

        $this->assertEquals(['won_count' => 2, 'won_value' => 5000, 'lost_count' => 1, 'lost_value' => 2500, 'open_count' => 2, 'open_value' => 1000, 'win_rate' => 66.7], $r['totals']);
        $stages = collect($r['byStage'])->keyBy('name');
        $this->assertEquals([1, 700], [$stages['Initial Contact']['count'], $stages['Initial Contact']['value']]);
        $this->assertEquals([1, 300], [$stages['Meeting']['count'], $stages['Meeting']['value']]);
        $this->assertEquals([['name' => 'Sam@test.com', 'count' => 2, 'value' => 5000]], $r['byUser']);

        $months = collect($r['byMonth'])->keyBy('month');
        $this->assertEquals([1000, 0], [$months['2026-02']['won'], $months['2026-02']['lost']]);
        $this->assertEquals([4000, 2500], [$months['2026-05']['won'], $months['2026-05']['lost']]);
        $this->assertEquals([0, 0], [$months['2026-03']['won'], $months['2026-03']['lost']]);
    }

    public function test_closed_at_follows_the_status(): void
    {
        $deal = $this->deal('active', 100);
        $service = app(DealService::class);

        $service->setStatus($deal, 'won', $this->a->id);
        $this->assertSame('2026-06-15 10:00:00', $deal->refresh()->closed_at->toDateTimeString());

        $service->setStatus($deal, 'active', $this->a->id);
        $this->assertNull($deal->refresh()->closed_at);

        $service->setStatus($deal, 'lost', $this->a->id);
        $this->assertNotNull($deal->refresh()->closed_at);
    }

    public function test_no_deals_means_zeroes_not_errors(): void
    {
        $r = $this->report()['deals'];

        $this->assertEquals(0, $r['totals']['win_rate']);
        $this->assertEquals(0, $r['totals']['won_value']);
        $this->assertSame([], $r['byUser']);
    }

    // ───────────── visibility, params, permissions ─────────────

    public function test_staff_numbers_only_cover_what_they_may_see(): void
    {
        $sam = $this->staff('sam@test.com', ['manage-leads', 'view-crm-reports', 'view-crm-dashboard']);
        $mine = $this->lead('2026-03-01');
        $mine->users()->attach($sam->id);
        $this->lead('2026-03-02');
        $this->lead('2026-03-03');
        $mineDeal = $this->deal('won', 500, '2026-04-01 10:00:00');
        $mineDeal->users()->attach($sam->id);
        $this->deal('won', 8000, '2026-04-02 10:00:00');

        $r = $this->report($sam);
        $this->assertSame(1, $r['leads']['totals']['leads']);
        $this->assertEquals(500, $r['deals']['totals']['won_value']);

        $this->assertSame(3, $this->report()['leads']['totals']['leads']);                  // the owner sees all

        $kpis = $this->actingAs($sam)->get('/crm/dashboard')->viewData('page')['props']['kpis'];
        $this->assertSame(1, $kpis['leads_total']);
    }

    public function test_period_parameters_are_sanitised(): void
    {
        $swapped = $this->actingAs($this->a)->get('/crm/reports?from=2026-06-30&to=2026-01-01')->viewData('page')['props'];
        $this->assertSame(['2026-01-01', '2026-06-30'], [$swapped['from'], $swapped['to']]);

        $junk = $this->actingAs($this->a)->get('/crm/reports?from=yesterday&to=<script>')->viewData('page')['props'];
        $this->assertSame(['2026-01-01', '2026-06-15'], [$junk['from'], $junk['to']]);          // this year until today

        $long = $this->actingAs($this->a)->get('/crm/reports?from=1990-01-01&to=2026-06-15')->viewData('page')['props'];
        $this->assertSame('2021-06-15', $long['from']);                                              // limited to 5 years
        $this->assertCount(61, $long['leads']['byMonth']);
    }

    public function test_pages_need_their_permissions(): void
    {
        $none = $this->staff('none@test.com', ['manage-leads']);

        $this->actingAs($none)->get('/crm/dashboard')->assertRedirect(route('dashboard'));
        $this->actingAs($none)->get('/crm/reports')->assertRedirect(route('dashboard'));
        $this->actingAs($this->a)->get('/crm/dashboard')->assertOk()->assertInertia(fn ($p) => $p->component('Lead/Dashboard/Index', false)->has('kpis')->has('leadsByMonth', 6));
        $this->actingAs($this->a)->get('/crm/reports')->assertOk()->assertInertia(fn ($p) => $p->component('Lead/Reports/Index', false));
    }

    public function test_dashboard_numbers_and_tasks(): void
    {
        $stages = LeadStage::where('pipeline_id', $this->sales->id)->orderBy('order')->pluck('id', 'name');
        $l1 = $this->lead('2026-06-02', ['lead_stage_id' => $stages['Contacted']]);
        $this->lead('2026-06-03', ['is_converted' => true]);
        $this->lead('2026-03-03', ['is_active' => false]);
        $this->deal('active', 400);
        $this->deal('won', 1500, '2026-06-10 10:00:00');
        $this->deal('lost', 500, '2026-06-11 10:00:00');
        $mk = fn (string $date, string $status) => $l1->tasks()->create(['name' => 'T', 'due_date' => $date, 'priority' => 'low', 'status' => $status, 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $mk('2026-06-15', 'on_going');
        $mk('2026-06-10', 'on_going');
        $mk('2026-06-10', 'completed');
        $mk('2026-06-20', 'on_going');

        $p = $this->actingAs($this->a)->get('/crm/dashboard')->viewData('page')['props'];
        $k = $p['kpis'];

        $this->assertSame(3, $k['leads_total']);
        $this->assertSame(1, $k['leads_active']);          // active and not converted
        $this->assertSame(2, $k['leads_new_month']);
        $this->assertSame(1, $k['leads_converted_month']);
        $this->assertSame(1, $k['open_deals']);
        $this->assertEquals(400, $k['open_value']);
        $this->assertEquals(1500, $k['won_month_value']);
        $this->assertEquals(500, $k['lost_month_value']);
        $this->assertEquals(50, $k['win_rate']);
        $this->assertSame(1, $k['tasks_due']);
        $this->assertSame(1, $k['tasks_overdue']);
        $this->assertSame(1, collect($p['leadsByStage'])->firstWhere('name', 'Contacted')['value']);
        // the trail is written by the services: this lead was made directly, so look at one created the normal way
        $this->assertIsArray($p['activity']);
        $made = app(\Workdo\Lead\Services\LeadService::class)->create($this->a->id, $this->a->id, ['subject' => 'Via service', 'name' => 'X', 'pipeline_id' => $this->sales->id], []);
        app(DealService::class)->create($this->a->id, $this->a->id, ['name' => 'Deal via service', 'price' => 1, 'pipeline_id' => $this->sales->id], [], []);
        $activity = $this->actingAs($this->a)->get('/crm/dashboard')->viewData('page')['props']['activity'];
        $this->assertEqualsCanonicalizing(['Via service', 'Deal via service'], collect($activity)->pluck('title')->all());
        $this->assertEqualsCanonicalizing(['lead', 'deal'], collect($activity)->pluck('kind')->all());
        $this->assertNotNull($made);
    }

    // ───────────── demo data ─────────────

    public function test_the_demo_command_fills_a_company_once(): void
    {
        $b = $this->company('demo@test.com');

        $this->artisan('crm:demo', ['email' => 'demo@test.com'])->assertSuccessful()->expectsOutputToContain('Created 12 leads and 8 deals');

        $this->assertSame(12, Lead::where('created_by', $b->id)->count());
        $this->assertSame(8, Deal::where('created_by', $b->id)->count());
        $this->assertSame(3, Deal::where('created_by', $b->id)->where('status', 'won')->count());
        $this->assertSame(0, Deal::where('created_by', $b->id)->whereIn('status', ['won', 'lost'])->whereNull('closed_at')->count());
        $this->assertSame(0, Lead::where('created_by', $this->a->id)->count());                      // nobody else is touched

        $this->artisan('crm:demo', ['email' => 'demo@test.com'])->assertFailed();                    // not twice
        $this->assertSame(12, Lead::where('created_by', $b->id)->count());
        $this->artisan('crm:demo', ['email' => 'demo@test.com', '--force' => true])->assertSuccessful();
        $this->assertSame(24, Lead::where('created_by', $b->id)->count());

        $this->artisan('crm:demo', ['email' => 'nobody@test.com'])->assertFailed();
        $this->artisan('crm:demo', ['email' => 'a@test.com'])->assertSuccessful();                    // an existing company without leads
    }

    public function test_the_demo_data_shows_up_in_the_reports(): void
    {
        $this->artisan('crm:demo', ['email' => 'a@test.com'])->assertSuccessful();

        $r = $this->report();
        $this->assertGreaterThan(0, $r['deals']['totals']['won_value']);
        $this->assertGreaterThan(0, $r['deals']['totals']['open_count']);
        $this->assertSame(12, collect($this->actingAs($this->a)->get('/crm/reports?from=2025-01-01&to=2026-12-31')->viewData('page')['props']['leads']['byStage'])->sum('value'));
    }
}
