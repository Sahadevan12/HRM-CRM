<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Tests\TestCase;
use Workdo\Lead\Models\DealStage;
use Workdo\Lead\Models\Label;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Models\Source;

class CrmSetupTest extends TestCase
{
    private User $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->company('a@test.com');
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

    /** opens the setup page once: that is what seeds the defaults */
    private function open(?User $as = null): array
    {
        return $this->actingAs($as ?? $this->a)->get('/crm/setup')->assertOk()->viewData('page')['props'];
    }

    private function pipeline(User $t, string $name = 'Other'): Pipeline
    {
        return Pipeline::create(['name' => $name, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function stages(string $model, int $pipelineId): array
    {
        return $model::where('pipeline_id', $pipelineId)->orderBy('order')->pluck('name')->all();
    }

    // ───────────── defaults ─────────────

    public function test_the_first_visit_seeds_a_sales_pipeline_with_stages_labels_and_sources(): void
    {
        $this->assertSame(0, Pipeline::where('created_by', $this->a->id)->count());

        $props = $this->open();

        $pipeline = Pipeline::where('created_by', $this->a->id)->firstOrFail();
        $this->assertSame('Sales', $pipeline->name);
        $this->assertSame(['New', 'Contacted', 'Qualified', 'Proposal Sent'], $this->stages(LeadStage::class, $pipeline->id));
        $this->assertSame(['Initial Contact', 'Qualification', 'Meeting', 'Proposal', 'Negotiation'], $this->stages(DealStage::class, $pipeline->id));
        $this->assertSame(['Hot', 'Warm', 'Cold'], Label::where('created_by', $this->a->id)->orderBy('id')->pluck('name')->all());
        $this->assertSame(6, Source::where('created_by', $this->a->id)->count());
        $this->assertCount(4, $props['leadStages']);
        $this->assertCount(5, $props['dealStages']);
    }

    public function test_defaults_are_seeded_once_and_deleted_ones_stay_deleted(): void
    {
        $this->open();
        Source::where('created_by', $this->a->id)->where('name', 'Website')->delete();

        $this->open();

        $this->assertSame(5, Source::where('created_by', $this->a->id)->count());
        $this->assertSame(1, Pipeline::where('created_by', $this->a->id)->count());
    }

    public function test_every_company_gets_its_own_defaults(): void
    {
        $b = $this->company('b@test.com');
        $this->open();
        $this->open($b);

        $this->assertSame(1, Pipeline::where('created_by', $b->id)->count());
        $this->assertSame(4, LeadStage::where('created_by', $b->id)->count());
        $this->assertSame(4, LeadStage::where('created_by', $this->a->id)->count());
    }

    // ───────────── CRUD ─────────────

    public function test_a_new_pipeline_starts_with_the_standard_stages(): void
    {
        $this->open();

        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners'])->assertSessionHas('success');

        $p = Pipeline::where('name', 'Partners')->firstOrFail();
        $this->assertCount(4, $this->stages(LeadStage::class, $p->id));
        $this->assertCount(5, $this->stages(DealStage::class, $p->id));
    }

    public function test_names_are_unique_per_company_only(): void
    {
        $b = $this->company('b@test.com');
        $this->open();

        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Sales'])->assertSessionHasErrors('name');
        $this->actingAs($this->a)->post('/crm/setup/sources', ['name' => 'Website'])->assertSessionHasErrors('name');
        $this->actingAs($b)->post('/crm/setup/sources', ['name' => 'Website'])->assertSessionHas('success');   // another company is fine

        // renaming a row to its own name is not a clash
        $source = Source::where('created_by', $this->a->id)->where('name', 'Website')->firstOrFail();
        $this->actingAs($this->a)->put("/crm/setup/sources/{$source->id}", ['name' => 'Website'])->assertSessionHas('success');
    }

    public function test_labels_need_a_hex_colour_and_a_pipeline_of_the_company(): void
    {
        $b = $this->company('b@test.com');
        $foreign = $this->pipeline($b);
        $this->open();
        $own = Pipeline::where('created_by', $this->a->id)->firstOrFail();

        $this->actingAs($this->a)->post('/crm/setup/labels', ['name' => 'VIP', 'color' => 'red', 'pipeline_id' => $own->id])->assertSessionHasErrors('color');
        $this->actingAs($this->a)->post('/crm/setup/labels', ['name' => 'VIP', 'color' => '#aabbcc', 'pipeline_id' => $foreign->id])->assertSessionHasErrors('pipeline_id');
        $this->actingAs($this->a)->post('/crm/setup/labels', ['name' => 'VIP', 'color' => '#aabbcc', 'pipeline_id' => $own->id])->assertSessionHas('success');
    }

    public function test_a_stage_never_moves_to_another_pipeline_and_new_stages_go_last(): void
    {
        $this->open();
        $sales = Pipeline::where('created_by', $this->a->id)->firstOrFail();
        $other = $this->pipeline($this->a);

        $this->actingAs($this->a)->post('/crm/setup/lead-stages', ['name' => 'Won', 'pipeline_id' => $sales->id])->assertSessionHas('success');
        $this->assertSame('Won', LeadStage::where('pipeline_id', $sales->id)->orderByDesc('order')->value('name'));
        $this->assertSame(5, LeadStage::where('pipeline_id', $sales->id)->max('order'));

        $stage = LeadStage::where('pipeline_id', $sales->id)->where('name', 'New')->firstOrFail();
        $this->actingAs($this->a)->put("/crm/setup/lead-stages/{$stage->id}", ['name' => 'Fresh', 'pipeline_id' => $other->id])->assertSessionHas('success');

        $stage->refresh();
        $this->assertSame('Fresh', $stage->name);
        $this->assertSame($sales->id, $stage->pipeline_id);
    }

    // ───────────── reorder ─────────────

    public function test_reordering_rewrites_the_order_column(): void
    {
        $this->open();
        $p = Pipeline::where('created_by', $this->a->id)->firstOrFail();
        $ids = LeadStage::where('pipeline_id', $p->id)->orderBy('order')->pluck('id')->all();

        $this->actingAs($this->a)->post('/crm/setup/lead-stages/reorder', ['pipeline_id' => $p->id, 'ids' => array_reverse($ids)])->assertSessionHas('success');

        $this->assertSame(['Proposal Sent', 'Qualified', 'Contacted', 'New'], $this->stages(LeadStage::class, $p->id));
        $this->assertSame([1, 2, 3, 4], LeadStage::where('pipeline_id', $p->id)->orderBy('order')->pluck('order')->all());
        $this->assertSame(['Initial Contact', 'Qualification', 'Meeting', 'Proposal', 'Negotiation'], $this->stages(DealStage::class, $p->id)); // untouched
    }

    public function test_a_stale_or_foreign_stage_list_is_refused(): void
    {
        $b = $this->company('b@test.com');
        $this->open();
        $this->open($b);
        $p = Pipeline::where('created_by', $this->a->id)->firstOrFail();
        $ids = LeadStage::where('pipeline_id', $p->id)->orderBy('order')->pluck('id')->all();
        $foreignId = LeadStage::where('created_by', $b->id)->value('id');

        $this->actingAs($this->a)->post('/crm/setup/lead-stages/reorder', ['pipeline_id' => $p->id, 'ids' => array_slice($ids, 1)])->assertSessionHas('error');           // one missing
        $this->actingAs($this->a)->post('/crm/setup/lead-stages/reorder', ['pipeline_id' => $p->id, 'ids' => [...array_slice($ids, 1), $foreignId]])->assertSessionHas('error'); // foreign id
        $this->actingAs($this->a)->post('/crm/setup/lead-stages/reorder', ['pipeline_id' => $p->id, 'ids' => [$ids[0], $ids[0], $ids[1], $ids[2]]])->assertSessionHasErrors('ids.1'); // duplicate
        $this->actingAs($b)->post('/crm/setup/lead-stages/reorder', ['pipeline_id' => $p->id, 'ids' => $ids])->assertSessionHasErrors('pipeline_id');                     // not their pipeline

        $this->assertSame(['New', 'Contacted', 'Qualified', 'Proposal Sent'], $this->stages(LeadStage::class, $p->id));
        $this->actingAs($this->a)->post('/crm/setup/labels/reorder', ['pipeline_id' => $p->id, 'ids' => [1]])->assertNotFound();    // only stages can be ordered
    }

    public function test_deleting_a_stage_closes_the_gap(): void
    {
        $this->open();
        $p = Pipeline::where('created_by', $this->a->id)->firstOrFail();
        $second = LeadStage::where('pipeline_id', $p->id)->where('name', 'Contacted')->firstOrFail();

        $this->actingAs($this->a)->delete("/crm/setup/lead-stages/{$second->id}")->assertSessionHas('success');

        $this->assertSame([1, 2, 3], LeadStage::where('pipeline_id', $p->id)->orderBy('order')->pluck('order')->all());
    }

    // ───────────── deleting pipelines ─────────────

    public function test_the_last_pipeline_cannot_be_deleted_but_others_can_with_their_stages(): void
    {
        $this->open();
        $sales = Pipeline::where('created_by', $this->a->id)->firstOrFail();

        $this->actingAs($this->a)->delete("/crm/setup/pipelines/{$sales->id}")->assertSessionHas('error');
        $this->assertNotNull($sales->fresh());

        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners']);
        $partners = Pipeline::where('name', 'Partners')->firstOrFail();
        $this->actingAs($this->a)->delete("/crm/setup/pipelines/{$partners->id}")->assertSessionHas('success');

        $this->assertSame(0, LeadStage::where('pipeline_id', $partners->id)->count());
        $this->assertSame(0, DealStage::where('pipeline_id', $partners->id)->count());
    }

    // ───────────── access ─────────────

    public function test_other_companies_cannot_touch_the_setup(): void
    {
        $b = $this->company('b@test.com');
        $this->open();
        $source = Source::where('created_by', $this->a->id)->firstOrFail();

        $this->actingAs($b)->put("/crm/setup/sources/{$source->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->actingAs($b)->delete("/crm/setup/sources/{$source->id}")->assertNotFound();
        $this->assertNotSame('Hacked', $source->refresh()->name);

        $props = $this->open($b);
        $this->assertCount(1, $props['pipelines']);
        $this->assertNotContains($source->id, collect($props['sources'])->pluck('id')->all());
    }

    public function test_unknown_kinds_and_missing_permissions(): void
    {
        $this->open();
        $this->actingAs($this->a)->post('/crm/setup/bogus', ['name' => 'x'])->assertNotFound();

        // a user of the company without CRM permissions
        $staff = User::create(['name' => 'S', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/crm/setup')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->post('/crm/setup/sources', ['name' => 'Nope'])->assertSessionHas('error');
        $this->assertSame(0, Source::where('name', 'Nope')->count());
    }
}
