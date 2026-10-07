<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Tests\TestCase;
use Workdo\Lead\Models\CrmPreference;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadActivityLog;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;

class CrmLeadsTest extends TestCase
{
    private User $a;

    private Pipeline $sales;

    /** @var array<string, int> stage name => id of the default Sales pipeline */
    private array $st;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->company('a@test.com');
        [$this->sales, $this->st] = $this->seedCrm($this->a);
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

    /** opening the setup page seeds the default pipeline */
    private function seedCrm(User $t): array
    {
        $this->actingAs($t)->get('/crm/setup')->assertOk();
        $pipeline = Pipeline::where('created_by', $t->id)->firstOrFail();

        return [$pipeline, LeadStage::where('pipeline_id', $pipeline->id)->pluck('id', 'name')->all()];
    }

    private function staff(string $email, array $permissions): User
    {
        $u = User::create(['name' => ucfirst($email), 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $u->assignRole('staff');
        $u->givePermissionTo($permissions);

        return $u;
    }

    private function lead(array $extra = [], ?User $as = null): Lead
    {
        $this->actingAs($as ?? $this->a)->post('/crm/leads', $extra + ['subject' => 'Website redesign', 'name' => 'Anita', 'pipeline_id' => $this->sales->id])->assertSessionHasNoErrors();

        return Lead::latest('id')->firstOrFail();
    }

    private function ids(int $stageId): array
    {
        return Lead::where('lead_stage_id', $stageId)->orderBy('order')->pluck('id')->all();
    }

    // ───────────── create / update / delete ─────────────

    public function test_a_new_lead_lands_in_the_first_stage_at_the_end_with_its_assignees_and_a_log(): void
    {
        $sam = $this->staff('sam@test.com', ['manage-leads']);

        $first = $this->lead(['user_ids' => [$sam->id, $this->a->id], 'email' => 'a@x.com', 'follow_up_date' => '2026-12-01']);
        $second = $this->lead(['subject' => 'Second']);

        $this->assertSame($this->st['New'], $first->lead_stage_id);
        $this->assertSame([1, 2], [$first->order, $second->order]);
        $this->assertEqualsCanonicalizing([$sam->id, $this->a->id], $first->users()->pluck('users.id')->all());
        $this->assertSame('created', LeadActivityLog::where('lead_id', $first->id)->value('type'));

        $into = $this->lead(['subject' => 'In Qualified', 'lead_stage_id' => $this->st['Qualified']]);
        $this->assertSame($this->st['Qualified'], $into->lead_stage_id);
    }

    public function test_lead_validation_and_foreign_references(): void
    {
        $b = $this->company('b@test.com');
        [$bPipeline, $bStages] = $this->seedCrm($b);
        $bUser = $this->staff('x@test.com', []);
        $bUser->update(['created_by' => $b->id]);
        $other = $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners']);
        $partners = Pipeline::where('name', 'Partners')->firstOrFail();

        $ok = ['subject' => 'S', 'name' => 'N', 'pipeline_id' => $this->sales->id];
        $this->actingAs($this->a)->post('/crm/leads', ['name' => 'N'] + ['pipeline_id' => $this->sales->id])->assertSessionHasErrors('subject');
        $this->actingAs($this->a)->post('/crm/leads', $ok + ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->actingAs($this->a)->post('/crm/leads', ['pipeline_id' => $bPipeline->id] + $ok)->assertSessionHasErrors('pipeline_id');
        $this->actingAs($this->a)->post('/crm/leads', $ok + ['lead_stage_id' => $bStages['New']])->assertSessionHasErrors('lead_stage_id');
        $this->actingAs($this->a)->post('/crm/leads', $ok + ['user_ids' => [$bUser->id]])->assertSessionHasErrors('user_ids.0');

        // a stage of another pipeline of the SAME company is not allowed either
        $partnerStage = LeadStage::where('pipeline_id', $partners->id)->value('id');
        $this->actingAs($this->a)->post('/crm/leads', $ok + ['lead_stage_id' => $partnerStage])->assertSessionHasErrors('lead_stage_id');
        $this->assertSame(0, Lead::count());
        $this->assertNotNull($other);
    }

    public function test_update_logs_assignment_changes_and_never_changes_the_stage(): void
    {
        $sam = $this->staff('sam@test.com', ['manage-leads']);
        $lead = $this->lead();

        $this->actingAs($this->a)->put("/crm/leads/{$lead->id}", ['subject' => 'Renamed', 'name' => 'Anita', 'user_ids' => [$sam->id], 'is_active' => false, 'lead_stage_id' => $this->st['Qualified']])->assertSessionHas('success');

        $lead->refresh();
        $this->assertSame('Renamed', $lead->subject);
        $this->assertFalse($lead->is_active);
        $this->assertSame($this->st['New'], $lead->lead_stage_id);
        $this->assertSame([$sam->id], $lead->users()->pluck('users.id')->all());
        $this->assertEqualsCanonicalizing(['created', 'assigned', 'updated'], LeadActivityLog::where('lead_id', $lead->id)->pluck('type')->all());

        // saving the same data again adds nothing
        $this->actingAs($this->a)->put("/crm/leads/{$lead->id}", ['subject' => 'Renamed', 'name' => 'Anita', 'user_ids' => [$sam->id], 'is_active' => false]);
        $this->assertSame(3, LeadActivityLog::where('lead_id', $lead->id)->count());
    }

    public function test_deleting_a_lead_removes_its_history(): void
    {
        $lead = $this->lead();
        $this->assertSame(1, LeadActivityLog::count());

        $this->actingAs($this->a)->delete("/crm/leads/{$lead->id}")->assertSessionHas('success');

        $this->assertSame(0, Lead::count());
        $this->assertSame(0, LeadActivityLog::count());
    }

    // ───────────── visibility and permissions ─────────────

    public function test_staff_see_only_their_own_or_assigned_leads_unless_they_may_view_all(): void
    {
        $sam = $this->staff('sam@test.com', ['manage-leads', 'create-leads', 'edit-leads', 'delete-leads', 'move-leads']);
        $kim = $this->staff('kim@test.com', ['manage-leads', 'edit-leads']);

        $owners = $this->lead(['subject' => 'Owner only']);
        $assigned = $this->lead(['subject' => 'Assigned to Sam', 'user_ids' => [$sam->id]]);
        $own = $this->lead(['subject' => 'Created by Sam'], $sam);

        $subjects = fn (User $u) => collect($this->actingAs($u)->get('/crm/leads')->assertOk()->viewData('page')['props']['leads'])->pluck('subject')->sort()->values()->all();

        $this->assertSame(['Assigned to Sam', 'Created by Sam'], $subjects($sam));
        $this->assertSame([], $subjects($kim));
        $this->assertCount(3, $this->actingAs($this->a)->get('/crm/leads')->viewData('page')['props']['leads']);

        $kim->givePermissionTo('view-all-leads');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertCount(3, $this->actingAs(User::find($kim->id))->get('/crm/leads')->viewData('page')['props']['leads']);

        // and what they cannot see they cannot change
        $lone = $this->staff('lone@test.com', ['manage-leads', 'edit-leads', 'delete-leads', 'move-leads']);
        $this->actingAs($lone)->put("/crm/leads/{$owners->id}", ['subject' => 'Hacked', 'name' => 'x'])->assertSessionHas('error');
        $this->actingAs($lone)->delete("/crm/leads/{$owners->id}")->assertSessionHas('error');
        $this->actingAs($lone)->post('/crm/leads/move', ['lead_id' => $owners->id, 'lead_stage_id' => $this->st['Contacted'], 'ids' => [$owners->id]])->assertSessionHas('error');
        $this->assertSame('Owner only', $owners->refresh()->subject);
        $this->assertNotNull($assigned);
        $this->assertNotNull($own);
    }

    public function test_actions_need_their_permissions_and_other_companies_are_locked_out(): void
    {
        $viewer = $this->staff('v@test.com', ['manage-leads']);
        $lead = $this->lead(['user_ids' => [$viewer->id]]);

        $this->actingAs($viewer)->post('/crm/leads', ['subject' => 'x', 'name' => 'y', 'pipeline_id' => $this->sales->id])->assertSessionHas('error');
        $this->actingAs($viewer)->put("/crm/leads/{$lead->id}", ['subject' => 'x', 'name' => 'y'])->assertSessionHas('error');
        $this->actingAs($viewer)->delete("/crm/leads/{$lead->id}")->assertSessionHas('error');
        $this->actingAs($viewer)->post('/crm/leads/move', ['lead_id' => $lead->id, 'lead_stage_id' => $this->st['Contacted'], 'ids' => [$lead->id]])->assertSessionHas('error');

        $nothing = $this->staff('n@test.com', []);
        $this->actingAs($nothing)->get('/crm/leads')->assertRedirect(route('dashboard'));

        $b = $this->company('b@test.com');
        $this->seedCrm($b);
        $this->actingAs($b)->put("/crm/leads/{$lead->id}", ['subject' => 'Hacked', 'name' => 'y'])->assertSessionHas('error');
        $this->actingAs($b)->delete("/crm/leads/{$lead->id}")->assertSessionHas('error');
        $this->actingAs($b)->post('/crm/leads/move', ['lead_id' => $lead->id, 'lead_stage_id' => $this->st['Contacted'], 'ids' => [$lead->id]])->assertNotFound();
        $this->assertCount(0, $this->actingAs($b)->get('/crm/leads')->viewData('page')['props']['leads']);
        $this->assertSame('Website redesign', $lead->refresh()->subject);
    }

    // ───────────── board: moving ─────────────

    public function test_moving_between_stages_rewrites_the_column_and_logs_it(): void
    {
        $a = $this->lead(['subject' => 'A']);
        $b = $this->lead(['subject' => 'B']);
        $c = $this->lead(['subject' => 'C', 'lead_stage_id' => $this->st['Contacted']]);
        $d = $this->lead(['subject' => 'D', 'lead_stage_id' => $this->st['Contacted']]);

        // A goes to the middle of the Contacted column: C, A, D
        $this->actingAs($this->a)->post('/crm/leads/move', ['lead_id' => $a->id, 'lead_stage_id' => $this->st['Contacted'], 'ids' => [$c->id, $a->id, $d->id]])->assertSessionHas('success');

        $this->assertSame([$c->id, $a->id, $d->id], $this->ids($this->st['Contacted']));
        $this->assertSame([$b->id], $this->ids($this->st['New']));
        $this->assertSame('Moved from New to Contacted', LeadActivityLog::where('lead_id', $a->id)->where('type', 'moved')->value('remark'));

        // re-ordering inside one column is not a "move"
        $this->actingAs($this->a)->post('/crm/leads/move', ['lead_id' => $a->id, 'lead_stage_id' => $this->st['Contacted'], 'ids' => [$a->id, $c->id, $d->id]])->assertSessionHas('success');
        $this->assertSame([$a->id, $c->id, $d->id], $this->ids($this->st['Contacted']));
        $this->assertSame(1, LeadActivityLog::where('lead_id', $a->id)->where('type', 'moved')->count());
    }

    public function test_bad_move_requests_change_nothing(): void
    {
        $b = $this->company('b@test.com');
        [$bPipeline, $bStages] = $this->seedCrm($b);
        $foreign = Lead::create(['subject' => 'B lead', 'name' => 'B', 'pipeline_id' => $bPipeline->id, 'lead_stage_id' => $bStages['New'], 'creator_id' => $b->id, 'created_by' => $b->id]);
        $mine = $this->lead(['subject' => 'Mine']);
        $other = $this->lead(['subject' => 'Other', 'lead_stage_id' => $this->st['Contacted']]);

        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners']);
        $partnerStage = LeadStage::where('pipeline_id', Pipeline::where('name', 'Partners')->value('id'))->value('id');

        $move = fn (array $d) => $this->actingAs($this->a)->post('/crm/leads/move', $d);

        $move(['lead_id' => $mine->id, 'lead_stage_id' => $partnerStage, 'ids' => [$mine->id]])->assertSessionHas('error');                       // other pipeline
        $move(['lead_id' => $mine->id, 'lead_stage_id' => $bStages['New'], 'ids' => [$mine->id]])->assertSessionHas('error');                      // foreign stage
        $move(['lead_id' => $mine->id, 'lead_stage_id' => $this->st['Contacted'], 'ids' => [$other->id]])->assertSessionHas('error');                // moved lead missing
        $move(['lead_id' => $mine->id, 'lead_stage_id' => $this->st['Contacted'], 'ids' => [$mine->id, $foreign->id]])->assertSessionHas('error');  // foreign lead in the list
        $move(['lead_id' => $mine->id, 'lead_stage_id' => $this->st['Contacted'], 'ids' => [$mine->id, $mine->id]])->assertSessionHasErrors('ids.1'); // duplicate

        $mine->update(['is_converted' => true]);
        $move(['lead_id' => $mine->id, 'lead_stage_id' => $this->st['Contacted'], 'ids' => [$mine->id, $other->id]])->assertSessionHas('error');   // converted

        $this->assertSame($this->st['New'], $mine->refresh()->lead_stage_id);
        $this->assertSame(0, LeadActivityLog::where('type', 'moved')->count());
    }

    // ───────────── pipeline switcher, list view ─────────────

    public function test_the_chosen_pipeline_is_remembered_per_user(): void
    {
        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners']);
        $partners = Pipeline::where('name', 'Partners')->firstOrFail();
        $b = $this->company('b@test.com');
        [$bPipeline] = $this->seedCrm($b);

        $this->assertSame($this->sales->id, $this->actingAs($this->a)->get('/crm/leads')->viewData('page')['props']['pipeline']['id']);

        $this->assertSame($partners->id, $this->actingAs($this->a)->get('/crm/leads?pipeline=' . $partners->id)->viewData('page')['props']['pipeline']['id']);
        $this->assertSame($partners->id, $this->actingAs($this->a)->get('/crm/leads')->viewData('page')['props']['pipeline']['id']);   // remembered

        // somebody else's pipeline is ignored, nothing is stored
        $this->assertSame($partners->id, $this->actingAs($this->a)->get('/crm/leads?pipeline=' . $bPipeline->id)->viewData('page')['props']['pipeline']['id']);
        $this->assertSame($partners->id, CrmPreference::where('user_id', $this->a->id)->value('default_pipeline_id'));
        $this->assertNull(CrmPreference::where('user_id', $b->id)->first());
    }

    public function test_the_board_shows_one_pipeline_and_the_list_view_filters(): void
    {
        $this->lead(['subject' => 'Alpha site', 'name' => 'Ann', 'email' => 'ann@x.com']);
        $this->lead(['subject' => 'Beta app', 'name' => 'Bob', 'lead_stage_id' => $this->st['Qualified']]);
        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners']);
        $partners = Pipeline::where('name', 'Partners')->firstOrFail();
        $this->actingAs($this->a)->post('/crm/leads', ['subject' => 'Partner deal', 'name' => 'Pat', 'pipeline_id' => $partners->id]);

        $props = $this->actingAs($this->a)->get('/crm/leads?pipeline=' . $this->sales->id)->viewData('page')['props'];
        $this->assertSame('kanban', $props['view']);
        $this->assertCount(2, $props['leads']);
        $this->assertCount(4, $props['stages']);

        $list = fn (string $q) => collect($this->actingAs($this->a)->get('/crm/leads?view=list&pipeline=' . $this->sales->id . $q)->viewData('page')['props']['leads']['data'])->pluck('subject')->all();
        $this->assertCount(2, $list(''));
        $this->assertSame(['Alpha site'], $list('&search=ann@x'));
        $this->assertSame(['Beta app'], $list('&stage=' . $this->st['Qualified']));
        $this->assertSame([], $list('&search=Partner'));          // that lead is in the other pipeline
    }

    // ───────────── setup guards ─────────────

    public function test_stages_and_pipelines_that_hold_leads_cannot_be_deleted(): void
    {
        $lead = $this->lead();
        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners']);

        $this->actingAs($this->a)->delete('/crm/setup/lead-stages/' . $this->st['New'])->assertSessionHas('error');
        $this->actingAs($this->a)->delete('/crm/setup/pipelines/' . $this->sales->id)->assertSessionHas('error');
        $this->assertNotNull(LeadStage::find($this->st['New']));

        $this->actingAs($this->a)->delete("/crm/leads/{$lead->id}");
        $this->actingAs($this->a)->delete('/crm/setup/lead-stages/' . $this->st['New'])->assertSessionHas('success');
        $this->actingAs($this->a)->delete('/crm/setup/pipelines/' . $this->sales->id)->assertSessionHas('success');
    }
}
