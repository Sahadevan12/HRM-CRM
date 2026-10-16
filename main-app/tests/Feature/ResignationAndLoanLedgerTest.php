<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserActiveModule;
use App\Services\PlanService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Modules\Account\Models\JournalEntry;
use Modules\Hrm\Models\Attendance;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\Loan;
use Modules\Hrm\Models\Resignation;
use Modules\Hrm\Services\PayrollService;
use Modules\Hrm\Services\WorkCalendar;

/** Staff resign for themselves; a loan to an employee is booked in the ledger. */
class ResignationAndLoanLedgerTest extends TestCase
{
    private User $a;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
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

    private function hire(string $email, ?User $as = null): Employee
    {
        $this->actingAs($as ?? $this->a)->post('/hrm/employees', [
            'name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'secret-pass-1', 'employment_type' => 'full_time', 'basic_salary' => 26000, 'date_of_joining' => '2025-01-01',
        ])->assertSessionHasNoErrors();
        Auth::forgetGuards();

        return Employee::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();
    }

    private function day(int $plus = 0): string
    {
        return now()->addDays($plus)->toDateString();
    }

    // ───────────── self-service resignation ─────────────

    public function test_an_employee_hands_in_their_own_resignation_and_the_owner_is_told(): void
    {
        $e = $this->hire('asha@test.com');

        $this->actingAs($e->user)->get('/hrm/my-resignation')->assertOk()->assertInertia(fn ($p) => $p->component('Hrm/Resignation/My', false)->where('canApply', true)->where('hasProfile', true));
        $this->actingAs($e->user)->post('/hrm/my-resignation', ['last_working_date' => $this->day(30), 'reason' => 'Moving abroad'])->assertSessionHas('success');

        $r = Resignation::firstOrFail();
        $this->assertSame([$e->id, 'pending', now()->toDateString(), $this->day(30)], [$r->employee_id, $r->status, $r->notice_date->toDateString(), $r->last_working_date->toDateString()]);
        $this->assertSame($e->user_id, $r->creator_id);
        $this->assertSame('active', $e->refresh()->status);                                                     // nothing changes before HR decides

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('a@test.com') && str_contains($m->mailSubject, 'Asha has submitted a resignation') && str_contains($m->htmlBody, 'Moving abroad'));
        $this->actingAs($e->user)->get('/hrm/my-resignation')->assertInertia(fn ($p) => $p->where('canApply', false)->has('history', 1));
    }

    public function test_the_rules_are_the_same_as_for_hr_entered_requests(): void
    {
        $e = $this->hire('asha@test.com');

        $this->actingAs($e->user)->post('/hrm/my-resignation', ['last_working_date' => $this->day(-1)])->assertSessionHasErrors('last_working_date');    // the past
        $this->actingAs($e->user)->post('/hrm/my-resignation', [])->assertSessionHasErrors('last_working_date');
        $this->actingAs($e->user)->post('/hrm/my-resignation', ['last_working_date' => $this->day(10)])->assertSessionHas('success');
        $this->actingAs($e->user)->post('/hrm/my-resignation', ['last_working_date' => $this->day(20)])->assertSessionHasErrors('last_working_date');    // one pending at a time
        $this->assertSame(1, Resignation::count());

        $e->update(['status' => 'resigned']);
        Resignation::query()->delete();
        $this->actingAs($e->user)->post('/hrm/my-resignation', ['last_working_date' => $this->day(10)])->assertSessionHasErrors('last_working_date');       // already gone
    }

    public function test_hr_decides_it_like_any_other_resignation(): void
    {
        $e = $this->hire('asha@test.com');
        $this->actingAs($e->user)->post('/hrm/my-resignation', ['last_working_date' => $this->day(-0)]);
        $r = Resignation::firstOrFail();

        $this->actingAs($this->a)->post("/hrm/lifecycle/resignations/{$r->id}/approve", ['decision_comment' => 'Sorry to see you go'])->assertSessionHas('success');

        $this->assertSame('resigned', $e->refresh()->status);                                                  // last day is today: applied at once
        $this->assertSame('Sorry to see you go', collect($this->actingAs($this->a)->get('/hrm/lifecycle/resignations')->viewData('page')['props']['rows']['data'])->first()['comment']);
    }

    public function test_withdrawing_and_who_may_do_what(): void
    {
        $e = $this->hire('asha@test.com');
        $other = $this->hire('ben@test.com');
        $this->actingAs($e->user)->post('/hrm/my-resignation', ['last_working_date' => $this->day(30)]);
        $r = Resignation::firstOrFail();

        $this->actingAs($other->user)->delete("/hrm/my-resignation/{$r->id}")->assertSessionHas('error');         // somebody else's
        $this->assertSame(1, Resignation::count());

        $this->actingAs($e->user)->delete("/hrm/my-resignation/{$r->id}")->assertSessionHas('success');
        $this->assertSame(0, Resignation::count());

        $this->actingAs($e->user)->post('/hrm/my-resignation', ['last_working_date' => $this->day(30)]);
        $decided = Resignation::firstOrFail();
        $this->actingAs($this->a)->post("/hrm/lifecycle/resignations/{$decided->id}/reject");
        $this->actingAs($e->user)->delete("/hrm/my-resignation/{$decided->id}")->assertSessionHas('error');       // decided: it stays as history
        $this->assertSame(1, Resignation::count());

        // the company owner does not resign through the app, and another company's employee cannot touch it
        $this->actingAs($this->a)->get('/hrm/my-resignation')->assertRedirect(route('dashboard'));
        $b = $this->company('b@test.com');
        $bEmp = $this->hire('bemp@test.com', $b);
        $this->actingAs($bEmp->user)->delete("/hrm/my-resignation/{$decided->id}")->assertSessionHas('error');
    }

    public function test_a_login_without_an_employee_profile_gets_a_clear_message(): void
    {
        $staff = User::create(['name' => 'S', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $staff->assignRole('staff');

        $this->actingAs($staff)->get('/hrm/my-resignation')->assertOk()->assertInertia(fn ($p) => $p->where('hasProfile', false));
        $this->actingAs($staff)->post('/hrm/my-resignation', ['last_working_date' => $this->day(10)])->assertSessionHas('error');
    }

    public function test_the_mobile_app_can_resign_too(): void
    {
        $e = $this->hire('asha@test.com');
        $login = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'asha@test.com', 'password' => 'secret-pass-1', 'device_name' => 'phone'])->json('token');
        $call = function (string $method, string $uri, array $data = []) use ($login) {
            $token = $login();
            Auth::forgetGuards();

            return $this->withToken($token)->json($method, $uri, $data);
        };

        $call('GET', '/api/v1/hrm/resignation')->assertOk()->assertJsonPath('can_apply', true);
        $call('POST', '/api/v1/hrm/resignation', ['last_working_date' => $this->day(-3)])->assertStatus(422);
        $id = $call('POST', '/api/v1/hrm/resignation', ['last_working_date' => $this->day(30), 'reason' => 'x'])->assertCreated()->json('data.id');
        $call('POST', '/api/v1/hrm/resignation', ['last_working_date' => $this->day(40)])->assertStatus(422);
        $call('GET', '/api/v1/hrm/resignation')->assertJsonPath('can_apply', false)->assertJsonPath('data.0.status', 'pending');

        $call('DELETE', "/api/v1/hrm/resignation/{$id}")->assertOk();
        $this->assertSame(0, Resignation::count());
    }

    // ───────────── loan in the books ─────────────

    private function loan(array $extra = []): void
    {
        $e = Employee::first() ?? $this->hire('asha@test.com');
        $this->actingAs($this->a)->post('/hrm/loans', $extra + ['employee_id' => $e->id, 'title' => 'Bike', 'amount' => 1000, 'installment' => 500, 'start_month' => '2026-03'])->assertSessionHasNoErrors();
    }

    private function entry(): ?JournalEntry
    {
        return JournalEntry::with('items.account')->where('created_by', $this->a->id)->where('reference_type', 'loan')->first();
    }

    private function amount(JournalEntry $entry, string $code, string $side): float
    {
        return (float) $entry->items->filter(fn ($i) => $i->account->code === $code)->sum($side);
    }

    public function test_a_loan_paid_from_the_bank_is_booked_dr_loans_cr_bank(): void
    {
        $this->loan(['paid_from' => 'bank', 'paid_on' => '2026-03-01']);

        $loan = Loan::firstOrFail();
        $this->assertSame(['bank', '2026-03-01'], [$loan->disbursed_from, $loan->disbursed_on->toDateString()]);
        $this->assertSame('2026-03-01', $loan->start_month->toDateString());                                   // the month column is still the first of the month

        $entry = $this->entry();
        $this->assertNotNull($entry);
        $this->assertSame('2026-03-01', $entry->journal_date->toDateString());
        $this->assertSame($entry->total_debit, $entry->total_credit);
        $this->assertEquals(1000, $this->amount($entry, '1400', 'debit'));
        $this->assertEquals(1000, $this->amount($entry, '1010', 'credit'));
        $this->assertStringContainsString('Bike', $entry->description);
    }

    public function test_a_cash_loan_uses_the_cash_account_and_no_choice_books_nothing(): void
    {
        $this->loan(['paid_from' => 'cash', 'paid_on' => '2026-03-01']);
        $this->assertEquals(1000, $this->amount($this->entry(), '1000', 'credit'));

        JournalEntry::query()->delete();
        Loan::query()->delete();
        $this->loan();                                                                                            // "do not record in accounts"
        $this->assertNull($this->entry());
        $this->assertNull(Loan::firstOrFail()->disbursed_from);
    }

    public function test_the_payment_details_are_validated(): void
    {
        $e = $this->hire('asha@test.com');
        $base = ['employee_id' => $e->id, 'title' => 'x', 'amount' => 1000, 'installment' => 500, 'start_month' => '2026-03'];

        $this->actingAs($this->a)->post('/hrm/loans', $base + ['paid_from' => 'bank'])->assertSessionHasErrors('paid_on');
        $this->actingAs($this->a)->post('/hrm/loans', $base + ['paid_on' => '2026-03-01'])->assertSessionHasErrors('paid_from');
        $this->actingAs($this->a)->post('/hrm/loans', $base + ['paid_from' => 'crypto', 'paid_on' => '2026-03-01'])->assertSessionHasErrors('paid_from');
        $this->assertSame(0, Loan::count());
    }

    public function test_without_the_accounting_module_the_loan_is_still_created(): void
    {
        $this->hire('asha@test.com');
        UserActiveModule::where('user_id', $this->a->id)->where('module', 'Account')->delete();

        $this->loan(['paid_from' => 'bank', 'paid_on' => '2026-03-01']);

        $this->assertSame(1, Loan::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_deleting_an_unrepaid_loan_removes_its_entry_but_a_repaid_one_keeps_it(): void
    {
        $this->loan(['paid_from' => 'bank', 'paid_on' => '2026-03-01']);
        $loan = Loan::firstOrFail();

        $this->actingAs($this->a)->delete("/hrm/loans/{$loan->id}")->assertSessionHas('success');
        $this->assertNull($this->entry());

        $this->loan(['paid_from' => 'bank', 'paid_on' => '2026-03-01']);
        $second = Loan::firstOrFail();
        $second->update(['repaid' => 500]);
        $this->actingAs($this->a)->delete("/hrm/loans/{$second->id}")->assertSessionHas('error');
        $this->assertNotNull($this->entry());                                                                       // still in the books
    }

    public function test_the_ledger_balance_of_the_loans_account_follows_the_instalments(): void
    {
        $e = $this->hire('asha@test.com');
        $this->loan(['paid_from' => 'bank', 'paid_on' => '2026-03-01', 'amount' => 1500, 'installment' => 500]);
        foreach (WorkCalendar::for($this->a->id)->workingDays('2026-03-01', '2026-03-31') as $day) {
            Attendance::create(['employee_id' => $e->id, 'date' => $day, 'status' => 'present', 'total_hours' => 8, 'source' => 'manual', 'created_by' => $this->a->id]);
        }

        $payroll = app(PayrollService::class)->generate($this->a->id, '2026-03', $this->a->id);
        $service = app(PayrollService::class);
        $service->approve($payroll, $this->a->id);
        $service->pay($payroll, '2026-04-01', 'bank', $this->a->id);

        // the account holds what the employee still owes: 1500 lent - 500 recovered
        $items = \Modules\Account\Models\JournalEntryItem::whereHas('account', fn ($q) => $q->where('created_by', $this->a->id)->where('code', '1400'))->get();
        $this->assertEquals(1000, $items->sum('debit') - $items->sum('credit'));
        $this->assertEquals(Loan::firstOrFail()->remaining(), $items->sum('debit') - $items->sum('credit'));
    }

    public function test_only_people_with_the_loan_permission_can_book_one_and_companies_are_separate(): void
    {
        $e = $this->hire('asha@test.com');
        $b = $this->company('b@test.com');

        $this->actingAs($e->user)->post('/hrm/loans', ['employee_id' => $e->id, 'title' => 'x', 'amount' => 10, 'installment' => 5, 'start_month' => '2026-03', 'paid_from' => 'bank', 'paid_on' => '2026-03-01'])->assertSessionHas('error');
        $this->actingAs($b)->post('/hrm/loans', ['employee_id' => $e->id, 'title' => 'x', 'amount' => 10, 'installment' => 5, 'start_month' => '2026-03', 'paid_from' => 'bank', 'paid_on' => '2026-03-01'])->assertSessionHasErrors('employee_id');
        $this->assertSame(0, JournalEntry::count());
    }
}
