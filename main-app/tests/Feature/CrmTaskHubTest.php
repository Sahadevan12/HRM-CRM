<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Modules\Lead\Models\Deal;
use Modules\Lead\Models\DealStage;
use Modules\Lead\Models\DealTask;
use Modules\Lead\Models\Lead;
use Modules\Lead\Models\LeadStage;
use Modules\Lead\Models\LeadTask;
use Modules\Lead\Models\Pipeline;
use Tests\TestCase;

class CrmTaskHubTest extends TestCase
{
    private User $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->company('a@test.com');
        $this->actingAs($this->a)->get('/crm/setup')->assertOk(); // seeds the default pipeline
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

    private function lead(User $t, string $subject): Lead
    {
        $pipeline = Pipeline::where('created_by', $t->id)->firstOrFail();

        return Lead::create([
            'subject' => $subject, 'name' => 'Contact', 'pipeline_id' => $pipeline->id,
            'lead_stage_id' => LeadStage::where('pipeline_id', $pipeline->id)->orderBy('order')->value('id'),
            'creator_id' => $t->id, 'created_by' => $t->id,
        ]);
    }

    private function deal(User $t, string $name): Deal
    {
        $pipeline = Pipeline::where('created_by', $t->id)->firstOrFail();

        return Deal::create([
            'name' => $name, 'price' => 100, 'pipeline_id' => $pipeline->id,
            'deal_stage_id' => DealStage::where('pipeline_id', $pipeline->id)->orderBy('order')->value('id'),
            'creator_id' => $t->id, 'created_by' => $t->id,
        ]);
    }

    private function props(User $as): array
    {
        return $this->actingAs($as)->get('/crm/tasks')->assertOk()->viewData('page')['props'];
    }

    public function test_tasks_of_leads_and_deals_are_grouped_by_due_date(): void
    {
        $lead = $this->lead($this->a, 'Acme');
        $deal = $this->deal($this->a, 'Big deal');
        $own = ['creator_id' => $this->a->id, 'created_by' => $this->a->id, 'priority' => 'high', 'status' => 'on_going'];

        LeadTask::create(['lead_id' => $lead->id, 'name' => 'Call back', 'due_date' => now()->subDays(2)->toDateString()] + $own);
        LeadTask::create(['lead_id' => $lead->id, 'name' => 'Send quote', 'due_date' => now()->toDateString()] + $own);
        DealTask::create(['deal_id' => $deal->id, 'name' => 'Negotiate', 'due_date' => now()->addDays(3)->toDateString()] + $own);
        LeadTask::create(['lead_id' => $lead->id, 'name' => 'Finished', 'due_date' => now()->toDateString(), 'status' => 'completed'] + $own);

        $props = $this->props($this->a);

        $this->assertSame(['Call back'], collect($props['overdue'])->pluck('name')->all());
        $this->assertSame(['Send quote'], collect($props['dueToday'])->pluck('name')->all());
        $this->assertSame(['Negotiate'], collect($props['upcoming'])->pluck('name')->all());
        $this->assertSame('deal', $props['upcoming'][0]['kind']);
        $this->assertSame('Big deal', $props['upcoming'][0]['title']);
    }

    public function test_a_task_can_be_ticked_off_from_the_hub_link(): void
    {
        $lead = $this->lead($this->a, 'Acme');
        $task = LeadTask::create(['lead_id' => $lead->id, 'name' => 'Call', 'due_date' => now()->toDateString(), 'priority' => 'low', 'status' => 'on_going', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        $url = $this->props($this->a)['dueToday'][0]['toggle'];
        $this->actingAs($this->a)->postJson($url)->assertOk();

        $this->assertSame('completed', $task->refresh()->status);
        $this->assertCount(0, $this->props($this->a)['dueToday']);
    }

    public function test_another_company_never_sees_the_tasks(): void
    {
        $b = $this->company('b@test.com');
        $lead = $this->lead($this->a, 'Secret');
        LeadTask::create(['lead_id' => $lead->id, 'name' => 'Hidden', 'due_date' => now()->toDateString(), 'priority' => 'low', 'status' => 'on_going', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        $this->actingAs($b)->get('/crm/setup')->assertOk();
        $props = $this->props($b);

        $this->assertCount(0, $props['dueToday']);
        $this->assertCount(0, $props['overdue']);
    }

    public function test_users_without_task_permissions_are_sent_away(): void
    {
        $staff = User::create(['name' => 'S', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/crm/tasks')->assertRedirect(route('dashboard'));
    }
}
