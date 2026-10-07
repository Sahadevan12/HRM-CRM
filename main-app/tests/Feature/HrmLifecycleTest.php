<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workdo\Hrm\Models\Award;
use Workdo\Hrm\Models\AwardType;
use Workdo\Hrm\Models\Branch;
use Workdo\Hrm\Models\Complaint;
use Workdo\Hrm\Models\Department;
use Workdo\Hrm\Models\Designation;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\Promotion;
use Workdo\Hrm\Models\Resignation;
use Workdo\Hrm\Models\Termination;
use Workdo\Hrm\Models\Transfer;
use Workdo\Hrm\Models\Warning;
use Workdo\Hrm\Services\LifecycleService;

class HrmLifecycleTest extends TestCase
{
    private User $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->company('a@test.com');
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

    private function hire(string $email = 'asha@test.com', ?User $as = null, array $extra = []): Employee
    {
        $this->actingAs($as ?? $this->a)->post('/hrm/employees', $extra + [
            'name' => 'Emp ' . $email, 'email' => $email, 'password' => 'secret-pass-1', 'employment_type' => 'full_time', 'basic_salary' => 1000,
        ])->assertSessionHasNoErrors();

        return Employee::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();
    }

    private function designation(User $t, string $name): Designation
    {
        return Designation::create(['name' => $name, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function submit(string $kind, array $data)
    {
        return $this->actingAs($this->a)->post("/hrm/lifecycle/{$kind}", $data);
    }

    private function today(int $plus = 0): string
    {
        return now()->addDays($plus)->toDateString();
    }

    // ───────────── promotions ─────────────

    public function test_a_promotion_changes_the_designation_and_keeps_history(): void
    {
        $junior = $this->designation($this->a, 'Junior');
        $senior = $this->designation($this->a, 'Senior');
        $e = $this->hire(extra: ['designation_id' => $junior->id]);

        $this->submit('promotions', ['employee_id' => $e->id, 'designation_id' => $senior->id, 'title' => 'Annual', 'promotion_date' => $this->today()])->assertSessionHas('success');

        $this->assertSame($senior->id, $e->refresh()->designation_id);
        $p = Promotion::firstOrFail();
        $this->assertSame($junior->id, $p->previous_designation_id);

        $this->submit('promotions', ['employee_id' => $e->id, 'designation_id' => $senior->id, 'title' => 'Again', 'promotion_date' => $this->today()])->assertSessionHasErrors('employee_id');
        $this->assertSame(1, Promotion::count());
    }

    public function test_promotion_rules(): void
    {
        $b = $this->company('b@test.com');
        $foreign = $this->designation($b, 'Foreign');
        $mine = $this->designation($this->a, 'Mine');
        $e = $this->hire();

        $this->submit('promotions', ['employee_id' => $e->id, 'designation_id' => $foreign->id, 'title' => 'X', 'promotion_date' => $this->today()])->assertSessionHasErrors('designation_id');

        $e->update(['status' => 'inactive']);
        $this->submit('promotions', ['employee_id' => $e->id, 'designation_id' => $mine->id, 'title' => 'X', 'promotion_date' => $this->today()])->assertSessionHasErrors('employee_id');
        $this->assertSame(0, Promotion::count());
    }

    // ───────────── resignations / terminations ─────────────

    public function test_an_approved_resignation_on_a_past_date_applies_at_once_and_blocks_the_login(): void
    {
        $e = $this->hire();

        $this->submit('resignations', ['employee_id' => $e->id, 'notice_date' => $this->today(-10), 'last_working_date' => $this->today(-1), 'reason' => 'moving'])->assertSessionHas('success');
        $r = Resignation::firstOrFail();
        $this->assertSame('pending', $r->status);
        $this->assertSame('active', $e->refresh()->status);

        $this->actingAs($this->a)->post("/hrm/lifecycle/resignations/{$r->id}/approve", ['decision_comment' => 'ok'])->assertSessionHas('success');

        $this->assertSame('resigned', $e->refresh()->status);
        $this->assertSame(0, (int) $e->user->refresh()->is_enable_login);
        $this->assertNotNull($r->refresh()->applied_at);
        $this->assertSame($this->a->id, $r->decided_by);
    }

    public function test_a_future_resignation_is_applied_by_the_scheduled_command(): void
    {
        $e = $this->hire();
        $this->submit('resignations', ['employee_id' => $e->id, 'notice_date' => $this->today(), 'last_working_date' => $this->today(20)]);
        $r = Resignation::firstOrFail();
        $this->actingAs($this->a)->post("/hrm/lifecycle/resignations/{$r->id}/approve")->assertSessionHas('success');

        $this->assertSame('active', $e->refresh()->status);                       // still employed until the last working day
        $this->assertNull($r->refresh()->applied_at);

        $this->assertSame(0, app(LifecycleService::class)->applyDue());

        Carbon::setTestNow(now()->addDays(21));
        $this->artisan('hrm:apply-lifecycle')->assertSuccessful();

        $this->assertSame('resigned', $e->refresh()->status);
        $this->assertSame(0, app(LifecycleService::class)->applyDue());              // idempotent
    }

    public function test_resignation_rules(): void
    {
        $e = $this->hire();

        $this->submit('resignations', ['employee_id' => $e->id, 'notice_date' => $this->today(5), 'last_working_date' => $this->today(1)])->assertSessionHasErrors('last_working_date');

        $this->submit('resignations', ['employee_id' => $e->id, 'notice_date' => $this->today(), 'last_working_date' => $this->today(30)])->assertSessionHas('success');
        $this->submit('resignations', ['employee_id' => $e->id, 'notice_date' => $this->today(), 'last_working_date' => $this->today(40)])->assertSessionHasErrors('employee_id');
        $this->assertSame(1, Resignation::count());
    }

    public function test_reject_delete_and_double_decisions(): void
    {
        $e = $this->hire();
        $this->submit('resignations', ['employee_id' => $e->id, 'notice_date' => $this->today(), 'last_working_date' => $this->today(30)]);
        $r = Resignation::firstOrFail();

        $this->actingAs($this->a)->post("/hrm/lifecycle/resignations/{$r->id}/reject", ['decision_comment' => 'stay'])->assertSessionHas('success');
        $this->assertSame('rejected', $r->refresh()->status);
        $this->assertSame('active', $e->refresh()->status);

        $this->actingAs($this->a)->post("/hrm/lifecycle/resignations/{$r->id}/approve")->assertSessionHas('error');   // already decided

        $this->actingAs($this->a)->delete("/hrm/lifecycle/resignations/{$r->id}")->assertSessionHas('success');
        $this->assertSame(0, Resignation::count());

        // an approved one is history
        $this->submit('resignations', ['employee_id' => $e->id, 'notice_date' => $this->today(), 'last_working_date' => $this->today(30)]);
        $r2 = Resignation::firstOrFail();
        $this->actingAs($this->a)->post("/hrm/lifecycle/resignations/{$r2->id}/approve");
        $this->actingAs($this->a)->delete("/hrm/lifecycle/resignations/{$r2->id}")->assertSessionHas('error');
        $this->assertSame(1, Resignation::count());
    }

    public function test_termination_flow(): void
    {
        $e = $this->hire();

        $this->submit('terminations', ['employee_id' => $e->id, 'type' => 'unknown', 'notice_date' => $this->today(-2), 'termination_date' => $this->today(-1)])->assertSessionHasErrors('type');
        $this->submit('terminations', ['employee_id' => $e->id, 'type' => 'misconduct', 'notice_date' => $this->today(-2), 'termination_date' => $this->today(-1), 'reason' => 'x'])->assertSessionHas('success');
        $t = Termination::firstOrFail();

        $this->actingAs($this->a)->post("/hrm/lifecycle/terminations/{$t->id}/approve")->assertSessionHas('success');

        $this->assertSame('terminated', $e->refresh()->status);
        $this->assertSame(0, (int) $e->user->refresh()->is_enable_login);
    }

    public function test_a_resigned_employee_cannot_get_new_requests(): void
    {
        $e = $this->hire();
        $e->update(['status' => 'resigned']);

        $this->submit('transfers', ['employee_id' => $e->id, 'branch_id' => null, 'transfer_date' => $this->today()])->assertSessionHasErrors('employee_id');
        $this->submit('terminations', ['employee_id' => $e->id, 'type' => 'other', 'notice_date' => $this->today(), 'termination_date' => $this->today()])->assertSessionHasErrors('employee_id');
    }

    // ───────────── transfers ─────────────

    public function test_an_approved_transfer_moves_the_employee(): void
    {
        $hq = Branch::create(['name' => 'HQ', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $south = Branch::create(['name' => 'South', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $ops = Department::create(['name' => 'Ops', 'branch_id' => $south->id, 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $e = $this->hire(extra: ['branch_id' => $hq->id]);

        $this->submit('transfers', ['employee_id' => $e->id, 'branch_id' => $hq->id, 'transfer_date' => $this->today()])->assertSessionHasErrors('employee_id'); // nothing changes
        $this->submit('transfers', ['employee_id' => $e->id, 'branch_id' => $south->id, 'department_id' => $ops->id, 'transfer_date' => $this->today(), 'reason' => 'growth'])->assertSessionHas('success');
        $t = Transfer::firstOrFail();
        $this->assertSame($hq->id, $t->from_branch_id);

        $this->assertSame($hq->id, $e->refresh()->branch_id);                       // not before the approval
        $this->actingAs($this->a)->post("/hrm/lifecycle/transfers/{$t->id}/approve")->assertSessionHas('success');

        $e->refresh();
        $this->assertSame($south->id, $e->branch_id);
        $this->assertSame($ops->id, $e->department_id);
    }

    public function test_transfer_targets_must_belong_to_the_company(): void
    {
        $b = $this->company('b@test.com');
        $foreign = Branch::create(['name' => 'Foreign', 'creator_id' => $b->id, 'created_by' => $b->id]);
        $e = $this->hire();

        $this->submit('transfers', ['employee_id' => $e->id, 'branch_id' => $foreign->id, 'transfer_date' => $this->today()])->assertSessionHasErrors('branch_id');
    }

    // ───────────── access ─────────────

    public function test_other_companies_and_staff_have_no_access(): void
    {
        $e = $this->hire();
        $this->submit('resignations', ['employee_id' => $e->id, 'notice_date' => $this->today(), 'last_working_date' => $this->today(30)]);
        $r = Resignation::firstOrFail();
        $b = $this->company('b@test.com');

        $this->actingAs($b)->post("/hrm/lifecycle/resignations/{$r->id}/approve")->assertNotFound();
        $this->actingAs($b)->get('/hrm/lifecycle/resignations')->assertOk()->assertInertia(fn ($p) => $p->where('rows.total', 0));

        $this->actingAs($e->user)->get('/hrm/lifecycle/resignations')->assertRedirect(route('dashboard'));
        $this->actingAs($e->user)->post('/hrm/lifecycle/terminations', [])->assertSessionHas('error');
        $this->actingAs($e->user)->post("/hrm/lifecycle/resignations/{$r->id}/approve")->assertSessionHas('error');

        $this->actingAs($this->a)->get('/hrm/lifecycle/bogus')->assertNotFound();
        $this->actingAs($this->a)->get('/hrm/lifecycle/promotions')->assertOk()->assertInertia(fn ($p) => $p->component('Hrm/Lifecycle/Index', false)->where('workflow', false));
        $this->actingAs($this->a)->get('/hrm/lifecycle/resignations')->assertOk()->assertInertia(fn ($p) => $p->where('rows.total', 1)->has('fields', 4));
    }

    public function test_a_resigned_employee_gets_no_payslip(): void
    {
        $e = $this->hire(extra: ['date_of_joining' => '2025-01-01']);
        $e->update(['status' => 'resigned']);

        $this->expectException(\Workdo\Hrm\Exceptions\HrmException::class); // nobody active to pay
        app(\Workdo\Hrm\Services\PayrollService::class)->generate($this->a->id, '2026-03', $this->a->id);
    }

    // ───────────── awards / warnings / complaints (generated CRUD) ─────────────

    public function test_awards_warnings_and_complaints(): void
    {
        $b = $this->company('b@test.com');
        $bEmp = $this->hire('bemp@test.com', $b);
        $e = $this->hire();
        $other = $this->hire('other@test.com');
        $type = AwardType::create(['name' => 'Star', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        $this->actingAs($this->a)->post('/hrm/awards', ['employee_id' => $e->id, 'award_type_id' => $type->id, 'award_date' => $this->today(), 'gift' => 'Trophy'])->assertSessionHas('success');
        $this->actingAs($this->a)->post('/hrm/awards', ['employee_id' => $bEmp->id, 'award_type_id' => $type->id, 'award_date' => $this->today()])->assertSessionHasErrors('employee_id');
        $this->assertSame(1, Award::count());
        $this->actingAs($this->a)->get('/hrm/awards')->assertOk()->assertInertia(fn ($p) => $p->has('awards.data', 1)->has('employeeOptions', 2));

        $this->actingAs($this->a)->post('/hrm/warnings', ['employee_id' => $e->id, 'subject' => 'Late', 'severity' => 'extreme', 'warning_date' => $this->today()])->assertSessionHasErrors('severity');
        $this->actingAs($this->a)->post('/hrm/warnings', ['employee_id' => $e->id, 'subject' => 'Late', 'severity' => 'low', 'warning_date' => $this->today()])->assertSessionHas('success');
        $this->assertSame(1, Warning::count());
        $this->actingAs($this->a)->get('/hrm/warnings')->assertOk();

        $base = ['from_employee_id' => $e->id, 'subject' => 'Noise', 'complaint_date' => $this->today(), 'description' => 'loud', 'status' => 'open'];
        $this->actingAs($this->a)->post('/hrm/complaints', $base + ['against_employee_id' => $e->id])->assertSessionHasErrors('against_employee_id');
        $this->actingAs($this->a)->post('/hrm/complaints', array_merge($base, ['status' => 'closed']))->assertSessionHasErrors('status');
        $this->actingAs($this->a)->post('/hrm/complaints', $base + ['against_employee_id' => $other->id])->assertSessionHas('success');
        $this->assertSame(1, Complaint::count());
        $this->actingAs($this->a)->get('/hrm/complaints')->assertOk();
    }
}
