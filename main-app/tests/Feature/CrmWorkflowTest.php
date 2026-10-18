<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Support\Facades\Mail;
use Modules\Lead\Models\Deal;
use Modules\Lead\Models\DealStage;
use Modules\Lead\Models\DealTask;
use Modules\Lead\Models\Lead;
use Modules\Lead\Models\LeadStage;
use Modules\Lead\Models\LeadTask;
use Modules\Lead\Models\Pipeline;
use Modules\Lead\Models\Workflow;
use Modules\Lead\Models\WorkflowRun;
use Modules\Lead\Services\DealService;
use Modules\Lead\Services\LeadService;
use Tests\TestCase;

class CrmWorkflowTest extends TestCase
{
    private User $a;

    private Pipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->company('a@test.com');
        $this->actingAs($this->a)->get('/crm/setup')->assertOk(); // seeds the default pipeline and stages
        $this->pipeline = Pipeline::where('created_by', $this->a->id)->firstOrFail();
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

    private function rule(array $over = []): Workflow
    {
        return Workflow::create($over + [
            'name' => 'Rule', 'module' => 'lead', 'trigger' => 'created', 'match' => 'all', 'conditions' => [],
            'actions' => [['type' => 'create_task', 'name' => 'Call {{name}}', 'due_days' => 2, 'priority' => 'high']],
            'is_active' => true, 'creator_id' => $this->a->id, 'created_by' => $this->a->id,
        ]);
    }

    private function newLead(string $name = 'Asha', array $extra = []): Lead
    {
        return app(LeadService::class)->create($this->a->id, $this->a->id, ['subject' => 'Website', 'name' => $name, 'pipeline_id' => $this->pipeline->id] + $extra, [$this->a->id]);
    }

    private function newDeal(float $price = 100): Deal
    {
        return app(DealService::class)->create($this->a->id, $this->a->id, ['name' => 'Big deal', 'price' => $price, 'pipeline_id' => $this->pipeline->id], [$this->a->id], []);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'name' => 'Welcome', 'module' => 'lead', 'trigger' => 'created', 'match' => 'all', 'is_active' => true,
            'conditions' => [['field' => 'name', 'operator' => 'contains', 'value' => 'a']],
            'actions' => [['type' => 'create_task', 'name' => 'Call back', 'due_days' => 1, 'priority' => 'medium']],
        ];
    }

    // ───────────── engine ─────────────

    public function test_a_new_lead_gets_the_task_of_a_matching_rule(): void
    {
        $rule = $this->rule();

        $lead = $this->newLead('Asha');

        $task = LeadTask::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('Call Asha', $task->name);
        $this->assertSame('high', $task->priority);
        $this->assertSame(now()->addDays(2)->toDateString(), $task->due_date->toDateString());
        $this->assertSame(1, $rule->refresh()->run_count);
        $this->assertSame('ok', WorkflowRun::firstOrFail()->status);
        $this->assertTrue($lead->activities()->where('type', 'automation')->exists());
    }

    public function test_conditions_decide_whether_a_rule_runs(): void
    {
        $this->rule(['conditions' => [['field' => 'name', 'operator' => 'contains', 'value' => 'Corp'], ['field' => 'email', 'operator' => 'not_empty', 'value' => null]]]);

        $plain = $this->newLead('Asha', ['email' => 'a@x.com']);
        $match = $this->newLead('Acme Corp', ['email' => 'c@x.com']);
        $noEmail = $this->newLead('Zeta Corp');

        $this->assertSame(0, LeadTask::where('lead_id', $plain->id)->count());
        $this->assertSame(1, LeadTask::where('lead_id', $match->id)->count());
        $this->assertSame(0, LeadTask::where('lead_id', $noEmail->id)->count());
    }

    public function test_match_any_needs_just_one_condition(): void
    {
        $this->rule(['match' => 'any', 'conditions' => [['field' => 'name', 'operator' => 'eq', 'value' => 'nobody'], ['field' => 'email', 'operator' => 'contains', 'value' => '@vip']]]);

        $this->assertSame(1, LeadTask::where('lead_id', $this->newLead('Asha', ['email' => 'x@vip.com'])->id)->count());
        $this->assertSame(0, LeadTask::where('lead_id', $this->newLead('Ben', ['email' => 'x@other.com'])->id)->count());
    }

    public function test_inactive_rules_and_other_triggers_do_nothing(): void
    {
        $this->rule(['is_active' => false]);
        $this->rule(['trigger' => 'stage_changed']);

        $lead = $this->newLead();

        $this->assertSame(0, LeadTask::where('lead_id', $lead->id)->count());
        $this->assertSame(0, WorkflowRun::count());
    }

    public function test_rules_of_other_companies_never_run(): void
    {
        $b = $this->company('b@test.com');
        Workflow::create(['name' => 'Foreign', 'module' => 'lead', 'trigger' => 'created', 'match' => 'all', 'conditions' => [], 'actions' => [['type' => 'create_task', 'name' => 'X']], 'is_active' => true, 'creator_id' => $b->id, 'created_by' => $b->id]);

        $lead = $this->newLead();

        $this->assertSame(0, LeadTask::where('lead_id', $lead->id)->count());
    }

    public function test_moving_a_lead_fires_stage_changed_and_a_rule_can_assign_and_note(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $staff->assignRole('staff');
        $qualified = LeadStage::where('pipeline_id', $this->pipeline->id)->where('name', 'Qualified')->firstOrFail();
        $this->rule(['trigger' => 'stage_changed', 'conditions' => [['field' => 'stage', 'operator' => 'eq', 'value' => (string) $qualified->id]],
            'actions' => [['type' => 'assign_user', 'user_id' => $staff->id], ['type' => 'add_note', 'text' => 'Now {{stage}}']]]);
        $lead = $this->newLead();

        app(LeadService::class)->move($lead, $qualified->id, [$lead->id], $this->a->id);

        $this->assertTrue($lead->users()->whereKey($staff->id)->exists());
        $this->assertTrue($lead->activities()->where('remark', 'Now Qualified')->exists());
    }

    public function test_a_rule_can_move_a_deal_and_react_to_won_and_lost_with_price_conditions(): void
    {
        $stage = DealStage::where('pipeline_id', $this->pipeline->id)->where('name', 'Negotiation')->firstOrFail();
        $this->rule(['module' => 'deal', 'trigger' => 'created', 'conditions' => [['field' => 'price', 'operator' => 'gt', 'value' => '1000']], 'actions' => [['type' => 'change_stage', 'stage_id' => $stage->id]]]);
        $this->rule(['module' => 'deal', 'trigger' => 'won', 'actions' => [['type' => 'create_task', 'name' => 'Thank {{title}}', 'due_days' => 0]]]);

        $small = $this->newDeal(500);
        $big = $this->newDeal(5000);
        app(DealService::class)->setStatus($big->refresh(), 'won', $this->a->id);

        $this->assertNotSame($stage->id, $small->refresh()->deal_stage_id);
        $this->assertSame($stage->id, $big->refresh()->deal_stage_id);
        $this->assertSame(['Thank Big deal'], DealTask::where('deal_id', $big->id)->pluck('name')->all());
    }

    public function test_an_email_action_sends_to_the_assignees(): void
    {
        Mail::fake();
        $this->rule(['actions' => [['type' => 'send_email', 'to' => 'assignees', 'subject' => 'New lead {{name}}', 'body' => 'Please call {{name}} about {{title}}.']]]);

        $this->newLead('Asha');

        Mail::assertSentCount(0); // raw mails are not mailables
        $this->assertSame('ok', WorkflowRun::firstOrFail()->status);
        $this->assertStringContainsString('1 recipient', WorkflowRun::firstOrFail()->message);
    }

    public function test_a_failing_rule_never_breaks_the_change_and_is_recorded(): void
    {
        // the stage belongs to nobody: the action throws
        $this->rule(['actions' => [['type' => 'change_stage', 'stage_id' => 999999]]]);

        $lead = $this->newLead();

        $this->assertNotNull($lead->fresh());
        $run = WorkflowRun::firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertTrue($lead->activities()->where('remark', 'like', '%failed%')->exists());
    }

    // ───────────── management screen ─────────────

    public function test_the_page_lists_rules_and_the_company_can_create_edit_toggle_and_delete(): void
    {
        $this->actingAs($this->a)->get('/crm/workflows')->assertOk();

        $this->actingAs($this->a)->post('/crm/workflows', $this->payload())->assertSessionHas('success');
        $rule = Workflow::firstOrFail();
        $this->assertSame('Welcome', $rule->name);
        $this->assertSame('create_task', $rule->actions[0]['type']);

        $this->actingAs($this->a)->put("/crm/workflows/{$rule->id}", $this->payload(['name' => 'Renamed', 'match' => 'any']))->assertSessionHas('success');
        $this->assertSame('Renamed', $rule->refresh()->name);
        $this->assertSame('any', $rule->match);

        $this->actingAs($this->a)->post("/crm/workflows/{$rule->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($rule->refresh()->is_active);

        $this->actingAs($this->a)->delete("/crm/workflows/{$rule->id}")->assertSessionHas('success');
        $this->assertSame(0, Workflow::count());
    }

    public function test_the_definition_is_validated(): void
    {
        $bad = fn (array $over) => $this->actingAs($this->a)->post('/crm/workflows', $this->payload($over));

        $bad(['trigger' => 'won'])->assertSessionHasErrors('trigger');                       // leads cannot be "won"
        $bad(['module' => 'invoice'])->assertSessionHasErrors('module');
        $bad(['conditions' => [['field' => 'price', 'operator' => 'gt', 'value' => '1']]])->assertSessionHasErrors('conditions.0.field'); // not a lead field
        $bad(['conditions' => [['field' => 'name', 'operator' => 'eq', 'value' => '']]])->assertSessionHasErrors('conditions.0.value');
        $bad(['actions' => []])->assertSessionHasErrors('actions');
        $bad(['actions' => [['type' => 'create_task']]])->assertSessionHasErrors('actions.0.name');
        $bad(['actions' => [['type' => 'send_email', 'to' => 'custom', 'email' => 'nope', 'subject' => 's', 'body' => 'b']]])->assertSessionHasErrors('actions.0.email');
        $bad(['actions' => [['type' => 'change_stage', 'stage_id' => 999999]]])->assertSessionHasErrors('actions.0.stage_id');
        $bad(['actions' => [['type' => 'delete_everything']]])->assertSessionHasErrors('actions.0.type');

        $this->assertSame(0, Workflow::count());
    }

    public function test_stages_and_users_of_other_companies_cannot_be_used(): void
    {
        $b = $this->company('b@test.com');
        $this->actingAs($b)->get('/crm/setup')->assertOk();
        $foreignStage = LeadStage::where('created_by', $b->id)->firstOrFail();

        $this->actingAs($this->a)->post('/crm/workflows', $this->payload(['actions' => [['type' => 'change_stage', 'stage_id' => $foreignStage->id]]]))->assertSessionHasErrors('actions.0.stage_id');
        $this->actingAs($this->a)->post('/crm/workflows', $this->payload(['actions' => [['type' => 'assign_user', 'user_id' => $b->id]]]))->assertSessionHasErrors('actions.0.user_id');
    }

    public function test_other_companies_and_unauthorised_users_cannot_touch_a_rule(): void
    {
        $b = $this->company('b@test.com');
        $rule = $this->rule();

        $this->actingAs($b)->put("/crm/workflows/{$rule->id}", $this->payload())->assertNotFound();
        $this->actingAs($b)->delete("/crm/workflows/{$rule->id}")->assertNotFound();
        $this->actingAs($b)->post("/crm/workflows/{$rule->id}/toggle")->assertNotFound();

        $staff = User::create(['name' => 'S', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $staff->assignRole('staff');
        $this->actingAs($staff)->get('/crm/workflows')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->post('/crm/workflows', $this->payload())->assertSessionHas('error');
        $this->assertSame(1, Workflow::count());
    }
}
