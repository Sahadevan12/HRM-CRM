<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workdo\Account\Models\JournalEntry;
use Workdo\Hrm\Exceptions\HrmException;
use Workdo\Hrm\Models\Attendance;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\LeaveType;
use Workdo\Hrm\Models\Loan;
use Workdo\Hrm\Models\Payroll;
use Workdo\Hrm\Models\Payslip;
use Workdo\Hrm\Models\SalaryComponent;
use Workdo\Hrm\Services\LeaveService;
use Workdo\Hrm\Services\PayrollService;
use Workdo\Hrm\Services\WorkCalendar;

/**
 * March 2026 has 31 days, 5 Sundays (1, 8, 15, 22, 29) => 26 working days with the default Mon-Sat week.
 * Basic 26000 => daily rate 1000, overtime hourly 125 (daily / 8) x 1.5.
 */
class HrmPayrollTest extends TestCase
{
    private const MONTH = '2026-03';

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

    private function hire(string $email = 'asha@test.com', ?User $as = null, array $extra = []): Employee
    {
        $this->actingAs($as ?? $this->a)->post('/hrm/employees', $extra + [
            'name' => 'Emp ' . $email, 'email' => $email, 'password' => 'secret-pass-1', 'employment_type' => 'full_time', 'basic_salary' => 26000,
            'date_of_joining' => '2025-01-01',
        ])->assertSessionHasNoErrors();

        return Employee::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();
    }

    /** a present record for every working day of the month (from $from), except the days in $skip */
    private function present(Employee $e, array $skip = [], ?string $from = null, array $overtime = []): void
    {
        $end = Carbon::parse(self::MONTH . '-01')->endOfMonth()->toDateString();

        foreach (WorkCalendar::for($e->created_by)->workingDays($from ?? self::MONTH . '-01', $end) as $day) {
            if (in_array($day, $skip, true)) {
                continue;
            }
            Attendance::create([
                'employee_id' => $e->id, 'date' => $day, 'status' => 'present', 'total_hours' => 8, 'overtime_hours' => $overtime[$day] ?? 0,
                'late_minutes' => 0, 'source' => 'manual', 'created_by' => $e->created_by,
            ]);
        }
    }

    private function makeComponent(User $t, string $name, string $type, string $calc, float $amount): SalaryComponent
    {
        return SalaryComponent::create(['name' => $name, 'type' => $type, 'calc' => $calc, 'amount' => $amount, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function generate(?User $t = null): Payroll
    {
        return app(PayrollService::class)->generate(($t ?? $this->a)->id, self::MONTH, ($t ?? $this->a)->id);
    }

    private function slip(Payroll $p, Employee $e): Payslip
    {
        return Payslip::with('lines')->where('payroll_id', $p->id)->where('employee_id', $e->id)->firstOrFail();
    }

    private function line(Payslip $s, string $kind): float
    {
        return (float) $s->lines->where('kind', $kind)->sum('amount');
    }

    // ───────────── calculation ─────────────

    public function test_full_attendance_pays_the_basic_salary(): void
    {
        $e = $this->hire();
        $this->present($e);

        $payroll = $this->generate();
        $s = $this->slip($payroll, $e);

        $this->assertSame(26, $s->working_days);
        $this->assertEqualsWithDelta(26000, $s->net_pay, 0.001);
        $this->assertEqualsWithDelta(0, $s->deductions, 0.001);
        $this->assertEqualsWithDelta(26000, $payroll->total_net, 0.001);
        $this->assertSame('draft', $payroll->status);
    }

    public function test_absent_days_unpaid_leave_and_half_days_reduce_the_pay(): void
    {
        $e = $this->hire();
        $unpaid = LeaveType::create(['name' => 'LOP', 'days_per_year' => 0, 'is_paid' => false, 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $paid = LeaveType::create(['name' => 'Casual', 'days_per_year' => 12, 'is_paid' => true, 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        // Mar 2 absent (no record), Mar 3 half day, Mar 4 unpaid leave, Mar 5 paid leave
        $this->present($e, ['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05']);
        Attendance::create(['employee_id' => $e->id, 'date' => '2026-03-03', 'status' => 'half_day', 'total_hours' => 3, 'source' => 'manual', 'created_by' => $this->a->id]);
        $leaves = app(LeaveService::class);
        $leaves->approve($leaves->apply($e, $unpaid, '2026-03-04', '2026-03-04', null, $this->a->id), null, $this->a->id);
        $leaves->approve($leaves->apply($e, $paid, '2026-03-05', '2026-03-05', null, $this->a->id), null, $this->a->id);

        $s = $this->slip($this->generate(), $e);

        $this->assertEquals(2.5, $s->unpaid_days);                              // 1 absent + 1 unpaid leave + half of a half day
        $this->assertEqualsWithDelta(2500, $this->line($s, 'absence'), 0.001);
        $this->assertEqualsWithDelta(23500, $s->net_pay, 0.001);
    }

    public function test_allowances_deductions_and_overtime(): void
    {
        $e = $this->hire();
        $hra = $this->makeComponent($this->a, 'HRA', 'allowance', 'percent', 10);       // 2600
        $phone = $this->makeComponent($this->a, 'Phone', 'allowance', 'fixed', 500);
        $pf = $this->makeComponent($this->a, 'PF', 'deduction', 'percent', 12);          // 3120
        $this->actingAs($this->a)->put("/hrm/salary-setup/{$e->id}", [
            'basic_salary' => 26000, 'hourly_rate' => '',
            'components' => [['salary_component_id' => $hra->id, 'value' => 10], ['salary_component_id' => $phone->id, 'value' => 500], ['salary_component_id' => $pf->id, 'value' => 12]],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->present($e, [], null, ['2026-03-02' => 1, '2026-03-03' => 1]);        // 2 overtime hours

        $s = $this->slip($this->generate(), $e);

        $this->assertEqualsWithDelta(3100, $this->line($s, 'allowance'), 0.001);
        $this->assertEqualsWithDelta(3120, $this->line($s, 'deduction'), 0.001);
        $this->assertEqualsWithDelta(375, $this->line($s, 'overtime'), 0.001);        // 2 h x 125 x 1.5
        $this->assertEqualsWithDelta(26000 + 3100 + 375, $s->earnings, 0.001);
        $this->assertEqualsWithDelta(26000 + 3100 + 375 - 3120, $s->net_pay, 0.001);
    }

    public function test_an_explicit_hourly_rate_is_used_for_overtime(): void
    {
        $e = $this->hire(extra: ['hourly_rate' => 200]);
        $this->present($e, [], null, ['2026-03-02' => 2]);

        $this->assertEqualsWithDelta(600, $this->line($this->slip($this->generate(), $e), 'overtime'), 0.001); // 2 x 200 x 1.5
    }

    public function test_a_new_joiner_is_paid_for_the_days_since_joining_only(): void
    {
        $e = $this->hire(extra: ['date_of_joining' => '2026-03-16']);
        $this->present($e, [], '2026-03-16');

        $s = $this->slip($this->generate(), $e);

        $this->assertSame(14, $s->working_days);                                   // 16-21, 23-28, 30, 31
        $this->assertEqualsWithDelta(14000, $s->net_pay, 0.001);                    // 26000 x 14 / 26
        $this->assertEqualsWithDelta(0, $this->line($s, 'absence'), 0.001);          // days before joining are not absences
    }

    public function test_employees_who_joined_later_or_are_not_active_get_no_payslip(): void
    {
        $later = $this->hire('later@test.com', extra: ['date_of_joining' => '2026-04-01']);
        $gone = $this->hire('gone@test.com');
        $gone->update(['status' => 'terminated']);
        $ok = $this->hire('ok@test.com');
        $this->present($ok);

        $payroll = $this->generate();

        $this->assertSame(1, $payroll->payslips()->count());
        $this->assertSame($ok->id, $payroll->payslips()->first()->employee_id);
        $this->assertNotNull($later);
    }

    public function test_regenerating_a_draft_recalculates_instead_of_duplicating(): void
    {
        $e = $this->hire();
        $this->present($e, ['2026-03-02']);
        $first = $this->generate();
        $this->assertEqualsWithDelta(25000, $first->total_net, 0.001);

        Attendance::create(['employee_id' => $e->id, 'date' => '2026-03-02', 'status' => 'present', 'total_hours' => 8, 'source' => 'manual', 'created_by' => $this->a->id]);
        $second = $this->generate();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payslip::count());
        $this->assertEqualsWithDelta(26000, $second->total_net, 0.001);
    }

    public function test_no_payroll_for_a_future_month_or_without_employees(): void
    {
        $this->expectException(HrmException::class);
        app(PayrollService::class)->generate($this->a->id, '2099-01', $this->a->id);
    }

    public function test_generating_without_any_employee_is_refused(): void
    {
        $this->actingAs($this->a)->post('/hrm/payrolls', ['month' => self::MONTH])->assertSessionHasErrors('month');
        $this->assertSame(0, Payroll::count());
    }

    // ───────────── loans ─────────────

    public function test_loan_instalments_are_taken_until_repaid(): void
    {
        $e = $this->hire();
        $this->present($e);
        $loan = Loan::create(['employee_id' => $e->id, 'title' => 'Bike', 'amount' => 1500, 'installment' => 1000, 'start_month' => '2026-03-01', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $svc = app(PayrollService::class);

        $p = $this->generate();
        $this->assertEqualsWithDelta(1000, $this->line($this->slip($p, $e), 'loan'), 0.001);
        $this->assertEqualsWithDelta(0, $loan->refresh()->repaid, 0.001);            // only an APPROVED payroll repays

        $svc->approve($p, $this->a->id);
        $this->assertEqualsWithDelta(1000, $loan->refresh()->repaid, 0.001);

        $svc->reopen($p);
        $this->assertEqualsWithDelta(0, $loan->refresh()->repaid, 0.001);
        $this->assertSame('active', $loan->status);

        $svc->approve($p->refresh(), $this->a->id);
        // next month: only the 500 that is left
        $this->assertEqualsWithDelta(500, $loan->refresh()->remaining(), 0.001);
    }

    public function test_the_last_instalment_is_the_remaining_amount_and_completes_the_loan(): void
    {
        $e = $this->hire();
        $this->present($e);
        $loan = Loan::create(['employee_id' => $e->id, 'title' => 'Adv', 'amount' => 1500, 'installment' => 1000, 'repaid' => 1000, 'start_month' => '2026-02-01', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        $p = $this->generate();
        $this->assertEqualsWithDelta(500, $this->line($this->slip($p, $e), 'loan'), 0.001);

        app(PayrollService::class)->approve($p, $this->a->id);
        $this->assertSame('completed', $loan->refresh()->status);
    }

    public function test_a_loan_never_pushes_the_net_pay_below_zero_and_future_loans_wait(): void
    {
        $e = $this->hire();                                                           // no attendance => all absent => nothing to take from
        Loan::create(['employee_id' => $e->id, 'title' => 'Big', 'amount' => 90000, 'installment' => 30000, 'start_month' => '2026-03-01', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        Loan::create(['employee_id' => $e->id, 'title' => 'Later', 'amount' => 900, 'installment' => 300, 'start_month' => '2026-04-01', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        $s = $this->slip($this->generate(), $e);

        $this->assertGreaterThanOrEqual(0, $s->net_pay);
        $this->assertEqualsWithDelta(0, $this->line($s, 'loan'), 0.001);
        $this->assertCount(0, $s->lines->where('kind', 'loan'));
    }

    public function test_cancelled_loans_are_not_deducted_and_loan_rules_over_http(): void
    {
        $e = $this->hire();
        $this->present($e);

        $this->actingAs($this->a)->post('/hrm/loans', ['employee_id' => $e->id, 'title' => 'X', 'amount' => 1000, 'installment' => 2000, 'start_month' => '2026-03'])->assertSessionHasErrors('installment');
        $this->actingAs($this->a)->post('/hrm/loans', ['employee_id' => $e->id, 'title' => 'X', 'amount' => 1000, 'installment' => 500, 'start_month' => '2026-03'])->assertSessionHas('success');
        $loan = Loan::firstOrFail();

        $this->actingAs($this->a)->post("/hrm/loans/{$loan->id}/cancel")->assertSessionHas('success');
        $this->assertCount(0, $this->slip($this->generate(), $e)->lines->where('kind', 'loan'));

        $this->actingAs($this->a)->post("/hrm/loans/{$loan->id}/cancel")->assertSessionHas('error');
        $this->actingAs($this->a)->delete("/hrm/loans/{$loan->id}")->assertSessionHas('success');
    }

    public function test_a_loan_for_another_companys_employee_is_rejected(): void
    {
        $b = $this->company('b@test.com');
        $bEmp = $this->hire('bemp@test.com', $b);

        $this->actingAs($this->a)->post('/hrm/loans', ['employee_id' => $bEmp->id, 'title' => 'X', 'amount' => 1000, 'installment' => 500, 'start_month' => '2026-03'])->assertSessionHasErrors('employee_id');
    }

    // ───────────── workflow ─────────────

    public function test_status_flow_draft_approved_paid(): void
    {
        $e = $this->hire();
        $this->present($e);
        $svc = app(PayrollService::class);
        $p = $this->generate();

        foreach ([fn () => $svc->pay($p, '2026-04-01', 'bank', $this->a->id), fn () => $svc->reopen($p)] as $tooEarly) {
            try {
                $tooEarly();
                $this->fail('not allowed on a draft');
            } catch (HrmException) {
                $this->assertTrue(true);
            }
        }

        $svc->approve($p, $this->a->id);
        $this->assertSame('approved', $p->refresh()->status);
        $this->assertSame($this->a->id, $p->approved_by);

        foreach ([fn () => $svc->approve($p, $this->a->id), fn () => $svc->generate($this->a->id, self::MONTH, null), fn () => $svc->delete($p)] as $notOnApproved) {
            try {
                $notOnApproved();
                $this->fail('not allowed once approved');
            } catch (HrmException) {
                $this->assertTrue(true);
            }
        }

        $svc->pay($p, '2026-04-01', 'bank', $this->a->id);
        $this->assertSame('paid', $p->refresh()->status);
        $this->assertSame('2026-04-01', $p->paid_on->toDateString());

        $this->expectException(HrmException::class);
        $svc->pay($p, '2026-04-02', 'bank', $this->a->id);
    }

    public function test_a_draft_payroll_can_be_deleted(): void
    {
        $e = $this->hire();
        $this->present($e);
        $p = $this->generate();

        $this->actingAs($this->a)->delete("/hrm/payrolls/{$p->id}")->assertRedirect(route('hrm.payrolls.index'));

        $this->assertSame(0, Payroll::count());
        $this->assertSame(0, Payslip::count());
    }

    // ───────────── accounting ─────────────

    public function test_paying_books_one_balanced_journal_entry(): void
    {
        $e = $this->hire();
        $pf = $this->makeComponent($this->a, 'PF', 'deduction', 'fixed', 1000);
        $this->actingAs($this->a)->put("/hrm/salary-setup/{$e->id}", ['basic_salary' => 26000, 'components' => [['salary_component_id' => $pf->id, 'value' => 1000]]])->assertSessionHasNoErrors();
        Loan::create(['employee_id' => $e->id, 'title' => 'Adv', 'amount' => 5000, 'installment' => 500, 'start_month' => '2026-03-01', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $this->present($e, ['2026-03-02']);                                          // one absent day: -1000

        $svc = app(PayrollService::class);
        $p = $this->generate();
        $svc->approve($p, $this->a->id);
        $this->assertSame(0, JournalEntry::count());                                 // approving books nothing

        $svc->pay($p, '2026-04-01', 'cash', $this->a->id);

        $entry = JournalEntry::with('items.account')->where('reference_type', 'payroll')->where('reference_id', $p->id)->firstOrFail();
        $this->assertSame($entry->total_debit, $entry->total_credit);

        $amount = fn (string $code, string $side) => (float) $entry->items->filter(fn ($i) => $i->account->code === $code)->sum($side);
        $this->assertEqualsWithDelta(25000, $amount('5300', 'debit'), 0.001);        // 26000 earned - 1000 absence
        $this->assertEqualsWithDelta(23500, $amount('1000', 'credit'), 0.001);        // net: 25000 - 1000 PF - 500 loan, paid from CASH
        $this->assertEqualsWithDelta(1000, $amount('2300', 'credit'), 0.001);
        $this->assertEqualsWithDelta(500, $amount('1400', 'credit'), 0.001);
        $this->assertEqualsWithDelta(23500, $p->refresh()->total_net, 0.001);
    }

    public function test_a_failing_booking_leaves_the_payroll_approved(): void
    {
        $e = $this->hire();
        $this->present($e);
        $svc = app(PayrollService::class);
        $p = $this->generate();
        $svc->approve($p, $this->a->id);

        app(\Workdo\Account\Services\AccountService::class)->ensureDefaults($this->a->id); // the chart is created lazily
        \Workdo\Account\Models\ChartOfAccount::where('created_by', $this->a->id)->where('code', '5300')->delete();
        \Workdo\Account\Models\ChartOfAccount::where('created_by', $this->a->id)->update(['is_active' => false]);

        try {
            $svc->pay($p, '2026-04-01', 'bank', $this->a->id);
            $this->fail('the journal needs active accounts');
        } catch (\Workdo\Account\Exceptions\UnbalancedJournalException) {
            $this->assertSame('approved', $p->refresh()->status);
            $this->assertNull($p->paid_on);
        }
    }

    public function test_payment_method_must_be_cash_or_bank(): void
    {
        $e = $this->hire();
        $this->present($e);
        $p = $this->generate();
        app(PayrollService::class)->approve($p, $this->a->id);

        $this->actingAs($this->a)->post("/hrm/payrolls/{$p->id}/pay", ['paid_on' => '2026-04-01', 'method' => 'crypto'])->assertSessionHasErrors('method');
        $this->actingAs($this->a)->post("/hrm/payrolls/{$p->id}/pay", ['paid_on' => '2026-04-01', 'method' => 'bank'])->assertSessionHas('success');
        $this->assertSame('paid', $p->refresh()->status);
    }

    // ───────────── access ─────────────

    public function test_payroll_pages_and_actions_over_http(): void
    {
        $e = $this->hire();
        $this->present($e);

        $this->actingAs($this->a)->post('/hrm/payrolls', ['month' => self::MONTH])->assertRedirect();
        $p = Payroll::firstOrFail();

        $this->actingAs($this->a)->get('/hrm/payrolls')->assertOk()->assertInertia(fn ($x) => $x->component('Hrm/Payrolls/Index', false)->where('payrolls.total', 1));
        $this->actingAs($this->a)->get("/hrm/payrolls/{$p->id}")->assertOk()->assertInertia(fn ($x) => $x->component('Hrm/Payrolls/Show', false)->has('payroll.payslips', 1));
        $this->actingAs($this->a)->post("/hrm/payrolls/{$p->id}/approve")->assertSessionHas('success');
        $this->actingAs($this->a)->post("/hrm/payrolls/{$p->id}/approve")->assertSessionHas('error');
        $this->actingAs($this->a)->get('/hrm/salary-setup')->assertOk()->assertInertia(fn ($x) => $x->component('Hrm/SalarySetup/Index', false));
        $this->actingAs($this->a)->get('/hrm/loans')->assertOk();
    }

    public function test_another_company_cannot_see_or_change_a_payroll(): void
    {
        $e = $this->hire();
        $this->present($e);
        $p = $this->generate();
        $slip = $p->payslips()->first();
        $b = $this->company('b@test.com');

        $this->actingAs($b)->get("/hrm/payrolls/{$p->id}")->assertRedirect(route('dashboard'));
        $this->actingAs($b)->get("/hrm/payslips/{$slip->id}")->assertRedirect(route('dashboard'));
        $this->actingAs($b)->post("/hrm/payrolls/{$p->id}/approve")->assertSessionHas('error');
        $this->actingAs($b)->delete("/hrm/payrolls/{$p->id}")->assertSessionHas('error');
        $this->assertSame('draft', $p->refresh()->status);

        $this->actingAs($b)->get('/hrm/payrolls')->assertOk()->assertInertia(fn ($x) => $x->where('payrolls.total', 0));
    }

    public function test_staff_see_only_their_own_approved_payslips(): void
    {
        $me = $this->hire('me@test.com');
        $other = $this->hire('other@test.com');
        $this->present($me);
        $this->present($other);
        $p = $this->generate();
        $mine = $this->slip($p, $me);
        $theirs = $this->slip($p, $other);

        // still a draft: nobody but HR can see it
        $this->actingAs($me->user)->get('/hrm/payslips')->assertOk()->assertInertia(fn ($x) => $x->component('Hrm/Payslips/My', false)->has('payslips', 0));
        $this->actingAs($me->user)->get("/hrm/payslips/{$mine->id}")->assertRedirect(route('dashboard'));

        app(PayrollService::class)->approve($p, $this->a->id);

        $this->actingAs($me->user)->get('/hrm/payslips')->assertOk()->assertInertia(fn ($x) => $x->has('payslips', 1));
        $this->actingAs($me->user)->get("/hrm/payslips/{$mine->id}")->assertOk()->assertInertia(fn ($x) => $x->component('Hrm/Payslips/Show', false));
        $this->actingAs($me->user)->get("/hrm/payslips/{$theirs->id}")->assertRedirect(route('dashboard'));

        // and staff cannot run payroll
        $this->actingAs($me->user)->get('/hrm/payrolls')->assertRedirect(route('dashboard'));
        $this->actingAs($me->user)->post('/hrm/payrolls', ['month' => self::MONTH])->assertSessionHas('error');
        $this->actingAs($me->user)->post("/hrm/payrolls/{$p->id}/pay", ['paid_on' => '2026-04-01', 'method' => 'cash'])->assertSessionHas('error');
        $this->actingAs($me->user)->get('/hrm/salary-setup')->assertRedirect(route('dashboard'));
    }

    // ───────────── salary setup / components / employees ─────────────

    public function test_salary_setup_validation_and_tenant_isolation(): void
    {
        $e = $this->hire();
        $b = $this->company('b@test.com');
        $foreign = $this->makeComponent($b, 'Foreign', 'allowance', 'fixed', 10);
        $pct = $this->makeComponent($this->a, 'Pct', 'allowance', 'percent', 5);

        $this->actingAs($this->a)->put("/hrm/salary-setup/{$e->id}", ['basic_salary' => 100, 'components' => [['salary_component_id' => $foreign->id, 'value' => 10]]])->assertSessionHasErrors('components.0.salary_component_id');
        $this->actingAs($this->a)->put("/hrm/salary-setup/{$e->id}", ['basic_salary' => 100, 'components' => [['salary_component_id' => $pct->id, 'value' => 150]]])->assertSessionHasErrors('components.0.value');
        $this->actingAs($this->a)->put("/hrm/salary-setup/{$e->id}", ['basic_salary' => 100, 'components' => [['salary_component_id' => $pct->id, 'value' => 5], ['salary_component_id' => $pct->id, 'value' => 6]]])->assertSessionHasErrors();
        $this->actingAs($this->a)->put("/hrm/salary-setup/{$e->id}", ['basic_salary' => -1])->assertSessionHasErrors('basic_salary');

        $this->actingAs($b)->put("/hrm/salary-setup/{$e->id}", ['basic_salary' => 1])->assertSessionHas('error');
        $this->assertEquals(26000, $e->refresh()->basic_salary);

        $this->actingAs($this->a)->put("/hrm/salary-setup/{$e->id}", ['basic_salary' => 30000, 'hourly_rate' => 150, 'components' => [['salary_component_id' => $pct->id, 'value' => 5]]])->assertSessionHas('success');
        $this->assertEquals(30000, $e->refresh()->basic_salary);
        $this->assertSame(1, $e->salaryComponents()->count());
    }

    public function test_salary_component_rules(): void
    {
        $this->actingAs($this->a)->post('/hrm/salary-components', ['name' => 'X', 'type' => 'bonus', 'calc' => 'fixed', 'amount' => 5])->assertSessionHasErrors('type');
        $this->actingAs($this->a)->post('/hrm/salary-components', ['name' => 'X', 'type' => 'allowance', 'calc' => 'weird', 'amount' => 5])->assertSessionHasErrors('calc');
        $this->actingAs($this->a)->post('/hrm/salary-components', ['name' => 'X', 'type' => 'allowance', 'calc' => 'percent', 'amount' => 120])->assertSessionHasErrors('amount');
        $this->actingAs($this->a)->post('/hrm/salary-components', ['name' => 'X', 'type' => 'allowance', 'calc' => 'percent', 'amount' => 12])->assertSessionHas('success');
    }

    public function test_an_employee_with_payslips_cannot_be_deleted(): void
    {
        $e = $this->hire();
        $this->present($e);
        $this->generate();

        $this->actingAs($this->a)->delete("/hrm/employees/{$e->id}")->assertSessionHas('error');
        $this->assertNotNull(Employee::find($e->id));
    }
}
