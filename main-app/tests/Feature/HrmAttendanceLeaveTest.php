<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Workdo\Hrm\Exceptions\HrmException;
use Workdo\Hrm\Models\Attendance;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\Holiday;
use Workdo\Hrm\Models\IpRestriction;
use Workdo\Hrm\Models\LeaveApplication;
use Workdo\Hrm\Models\LeaveType;
use Workdo\Hrm\Models\Shift;
use Workdo\Hrm\Services\AttendanceService;
use Workdo\Hrm\Services\AttendanceSummary;
use Workdo\Hrm\Services\LeaveService;

class HrmAttendanceLeaveTest extends TestCase
{
    private User $a;

    // 2026-03-02 is a Monday; default working days are Mon-Sat
    private const MON = '2026-03-02';

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
            'name' => 'Emp ' . $email, 'email' => $email, 'password' => 'secret-pass-1', 'employment_type' => 'full_time', 'basic_salary' => 30000,
        ])->assertSessionHasNoErrors();

        return Employee::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();
    }

    private function shift(User $t, string $start = '09:00', string $end = '18:00', int $break = 60, bool $night = false): Shift
    {
        return Shift::create(['name' => "S$start", 'start_time' => $start, 'end_time' => $end, 'break_minutes' => $break, 'is_night_shift' => $night, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function leaveType(User $t, int $days = 12, bool $paid = true, string $name = 'Casual'): LeaveType
    {
        return LeaveType::create(['name' => $name, 'days_per_year' => $days, 'is_paid' => $paid, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function svc(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    private function at(string $time, string $date = self::MON): Carbon
    {
        return Carbon::parse("$date $time", config('app.timezone'));
    }

    // ───────────── clock in / out maths ─────────────

    public function test_clock_in_and_out_compute_hours_overtime_and_lateness(): void
    {
        $shift = $this->shift($this->a); // 09:00-18:00 with 60 min break = 8h
        $emp = $this->hire(extra: ['shift_id' => $shift->id]);

        $this->svc()->clockIn($emp, null, $this->at('09:25')); // 25 min late, beyond the 10 min grace
        $rec = $this->svc()->clockOut($emp, null, $this->at('19:25'));

        $this->assertSame(25, $rec->late_minutes);
        $this->assertEqualsWithDelta(9.0, $rec->total_hours, 0.001); // 10h - 60 min break
        $this->assertEqualsWithDelta(1.0, $rec->overtime_hours, 0.001);
        $this->assertSame('present', $rec->status);
        $this->assertSame(self::MON, $rec->date->toDateString());
    }

    public function test_arriving_within_the_grace_period_is_not_late(): void
    {
        $emp = $this->hire(extra: ['shift_id' => $this->shift($this->a)->id]);

        $rec = $this->svc()->clockIn($emp, null, $this->at('09:10'));

        $this->assertSame(0, $rec->late_minutes);
    }

    public function test_a_short_day_is_a_half_day(): void
    {
        $emp = $this->hire(extra: ['shift_id' => $this->shift($this->a)->id]);

        $this->svc()->clockIn($emp, null, $this->at('09:00'));
        $rec = $this->svc()->clockOut($emp, null, $this->at('12:00'));

        $this->assertSame('half_day', $rec->status);
        $this->assertSame(0.0, $rec->overtime_hours);
    }

    public function test_cannot_clock_in_twice_or_clock_out_without_clocking_in(): void
    {
        $emp = $this->hire();

        try {
            $this->svc()->clockOut($emp, null, $this->at('10:00'));
            $this->fail('clock out without clock in must fail');
        } catch (HrmException) {
            $this->assertTrue(true);
        }

        $this->svc()->clockIn($emp, null, $this->at('09:00'));
        $this->expectException(HrmException::class);
        $this->svc()->clockIn($emp, null, $this->at('09:05'));
    }

    public function test_one_record_per_day(): void
    {
        $emp = $this->hire();
        $this->svc()->clockIn($emp, null, $this->at('09:00'));
        $this->svc()->clockOut($emp, null, $this->at('17:00'));

        $this->expectException(HrmException::class);
        $this->svc()->clockIn($emp, null, $this->at('18:00'));
    }

    public function test_night_shift_clock_out_after_midnight(): void
    {
        $shift = $this->shift($this->a, '22:00', '06:00', 30, true);
        $emp = $this->hire(extra: ['shift_id' => $shift->id]);

        $this->assertEqualsWithDelta(7.5, $this->svc()->shiftHours($shift), 0.001);

        $this->svc()->clockIn($emp, null, $this->at('22:00'));
        $rec = $this->svc()->clockOut($emp, null, $this->at('06:00', '2026-03-03'));

        $this->assertEqualsWithDelta(7.5, $rec->total_hours, 0.001);
        $this->assertSame(0.0, $rec->overtime_hours);
        $this->assertSame(self::MON, $rec->date->toDateString()); // belongs to the day the shift started
    }

    public function test_ip_restriction_limits_self_clocking(): void
    {
        $emp = $this->hire();
        IpRestriction::create(['ip_address' => '10.0.0.5', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        try {
            $this->svc()->clockIn($emp, '8.8.8.8', $this->at('09:00'));
            $this->fail('outside the allowed ip');
        } catch (HrmException) {
            $this->assertSame(0, Attendance::count());
        }

        $this->svc()->clockIn($emp, '10.0.0.5', $this->at('09:00'));
        $this->assertSame(1, Attendance::count());
    }

    public function test_inactive_employees_cannot_clock(): void
    {
        $emp = $this->hire();
        $emp->update(['status' => 'terminated']);

        $this->expectException(HrmException::class);
        $this->svc()->clockIn($emp->refresh(), null, $this->at('09:00'));
    }

    // ───────────── HR manual entries ─────────────

    public function test_hr_manual_entry_absent_and_duplicate_day(): void
    {
        $emp = $this->hire();

        $this->actingAs($this->a)->post('/hrm/attendances', ['employee_id' => $emp->id, 'date' => self::MON, 'clock_in' => '09:00', 'clock_out' => '17:00'])->assertSessionHas('success');
        $rec = Attendance::firstOrFail();
        $this->assertEqualsWithDelta(8.0, $rec->total_hours, 0.001);
        $this->assertSame('manual', $rec->source);

        $this->actingAs($this->a)->post('/hrm/attendances', ['employee_id' => $emp->id, 'date' => self::MON, 'clock_in' => '10:00'])->assertSessionHasErrors('date');

        $this->actingAs($this->a)->post('/hrm/attendances', ['employee_id' => $emp->id, 'date' => '2026-03-03', 'status' => 'absent'])->assertSessionHas('success');
        $absent = Attendance::where('date', '2026-03-03')->firstOrFail();
        $this->assertSame('absent', $absent->status);
        $this->assertNull($absent->clock_in);

        $this->actingAs($this->a)->put("/hrm/attendances/{$rec->id}", ['employee_id' => $emp->id, 'date' => self::MON, 'clock_in' => '09:00', 'clock_out' => '19:00'])->assertSessionHas('success');
        $this->assertEqualsWithDelta(2.0, $rec->refresh()->overtime_hours, 0.001);

        $this->actingAs($this->a)->delete("/hrm/attendances/{$absent->id}")->assertSessionHas('success');
        $this->assertSame(1, Attendance::count());
    }

    public function test_manual_entry_rejects_an_employee_of_another_company(): void
    {
        $b = $this->company('b@test.com');
        $bEmp = $this->hire('bemp@test.com', $b);

        $this->actingAs($this->a)->post('/hrm/attendances', ['employee_id' => $bEmp->id, 'date' => self::MON, 'clock_in' => '09:00'])->assertSessionHasErrors('employee_id');
        $this->assertSame(0, Attendance::count());
    }

    public function test_tenant_cannot_touch_another_companys_attendance(): void
    {
        $b = $this->company('b@test.com');
        $bEmp = $this->hire('bemp@test.com', $b);
        $this->actingAs($b)->post('/hrm/attendances', ['employee_id' => $bEmp->id, 'date' => self::MON, 'clock_in' => '09:00', 'clock_out' => '17:00']);
        $rec = Attendance::firstOrFail();

        $this->actingAs($this->a)->delete("/hrm/attendances/{$rec->id}")->assertSessionHas('error');
        $this->assertSame(1, Attendance::count());

        $this->actingAs($this->a)->get('/hrm/attendances?from=2026-03-01&to=2026-03-31')->assertOk()->assertInertia(fn ($p) => $p->where('records.total', 0));
    }

    // ───────────── self service over HTTP ─────────────

    public function test_staff_can_clock_through_the_web_ui_but_not_manage_attendance(): void
    {
        $emp = $this->hire();
        $staff = $emp->user;

        $this->actingAs($staff)->get('/hrm/attendances/my')->assertOk()->assertInertia(fn ($p) => $p->component('Hrm/Attendances/My', false));
        $this->actingAs($staff)->post('/hrm/attendances/clock-in')->assertSessionHas('success');
        $this->assertSame(1, Attendance::where('employee_id', $emp->id)->count());
        $this->actingAs($staff)->post('/hrm/attendances/clock-in')->assertSessionHas('error');
        $this->actingAs($staff)->post('/hrm/attendances/clock-out')->assertSessionHas('success');

        $this->actingAs($staff)->get('/hrm/attendances')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->post('/hrm/attendances', ['employee_id' => $emp->id, 'date' => self::MON, 'clock_in' => '09:00'])->assertSessionHas('error');
    }

    public function test_the_company_owner_without_a_profile_gets_a_clear_message(): void
    {
        $this->actingAs($this->a)->post('/hrm/attendances/clock-in')->assertSessionHas('error');
    }

    // ───────────── summary ─────────────

    public function test_summary_counts_weekly_offs_holidays_leave_and_absence_correctly(): void
    {
        $emp = $this->hire();
        $holiday = Holiday::create(['name' => 'Fest', 'start_date' => '2026-03-04', 'end_date' => '2026-03-04', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $type = $this->leaveType($this->a);

        // week Mon 2 - Sun 8 March: Wed 4 is a holiday, Sun 8 a weekly off => 5 working days
        $this->actingAs($this->a)->post('/hrm/attendances', ['employee_id' => $emp->id, 'date' => '2026-03-02', 'clock_in' => '09:00', 'clock_out' => '17:00']);
        $this->actingAs($this->a)->post('/hrm/attendances', ['employee_id' => $emp->id, 'date' => '2026-03-03', 'clock_in' => '09:00', 'clock_out' => '12:00']);
        $leave = app(LeaveService::class)->apply($emp, $type, '2026-03-05', '2026-03-05', 'x', $this->a->id);
        app(LeaveService::class)->approve($leave, null, $this->a->id);
        // Fri 6 and Sat 7 have no record => absent

        $totals = app(AttendanceSummary::class)->forEmployee($emp, '2026-03-02', '2026-03-08', '2026-03-31')['totals'];

        $this->assertSame(5, $totals['working_days']);
        $this->assertEquals(1, $totals['present']);
        $this->assertEquals(1, $totals['half_day']);
        $this->assertEquals(1, $totals['leave_paid']);
        $this->assertEquals(2, $totals['absent']);
        $this->assertEqualsWithDelta(11.0, $totals['worked_hours'], 0.001);
        $this->assertNotNull($holiday);
    }

    public function test_days_after_today_are_upcoming_not_absent(): void
    {
        $emp = $this->hire();

        $s = app(AttendanceSummary::class)->forEmployee($emp, '2026-03-02', '2026-03-04', '2026-03-02');

        $this->assertSame('absent', $s['days']['2026-03-02']);
        $this->assertSame('upcoming', $s['days']['2026-03-03']);
        $this->assertEquals(1, $s['totals']['absent']);
    }

    public function test_summary_page_renders_for_hr(): void
    {
        $this->hire();

        $this->actingAs($this->a)->get('/hrm/attendances/summary?month=2026-03')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Hrm/Attendances/Summary', false)->has('rows', 1));
    }

    // ───────────── leave ─────────────

    public function test_leave_counts_only_working_days(): void
    {
        $emp = $this->hire();
        $type = $this->leaveType($this->a);
        Holiday::create(['name' => 'Fest', 'start_date' => '2026-03-04', 'end_date' => '2026-03-04', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        // Mon 2 .. Sun 8: Wed holiday + Sun off => 5 days
        $leave = app(LeaveService::class)->apply($emp, $type, '2026-03-02', '2026-03-08', null, $this->a->id);

        $this->assertEquals(5, $leave->total_days);
        $this->assertSame('pending', $leave->status);
    }

    public function test_leave_on_non_working_days_only_is_rejected(): void
    {
        $emp = $this->hire();

        $this->expectException(HrmException::class);
        app(LeaveService::class)->apply($emp, $this->leaveType($this->a), '2026-03-08', '2026-03-08', null, $this->a->id); // Sunday
    }

    public function test_leave_cannot_overlap_or_span_years_or_exceed_the_balance(): void
    {
        $emp = $this->hire();
        $type = $this->leaveType($this->a, 3);
        $svc = app(LeaveService::class);

        $svc->apply($emp, $type, '2026-03-02', '2026-03-03', null, $this->a->id);

        foreach ([
            ['2026-03-03', '2026-03-04'],   // overlaps
            ['2026-03-09', '2026-03-10'],   // 2 + 2 > 3
            ['2026-12-30', '2027-01-02'],   // spans two years
            ['2026-03-12', '2026-03-10'],   // end before start
        ] as [$from, $to]) {
            try {
                $svc->apply($emp, $type, $from, $to, null, $this->a->id);
                $this->fail("$from..$to should be rejected");
            } catch (HrmException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, LeaveApplication::count());
    }

    public function test_unlimited_leave_type_has_no_balance_check(): void
    {
        $emp = $this->hire();
        $type = $this->leaveType($this->a, 0, false, 'LOP');

        $leave = app(LeaveService::class)->apply($emp, $type, '2026-03-02', '2026-03-28', null, $this->a->id);

        $this->assertEquals(24, $leave->total_days); // four full Mon-Sat weeks
    }

    public function test_leave_flow_over_http_apply_approve_reject_and_permissions(): void
    {
        $emp = $this->hire();
        $other = $this->hire('other@test.com');
        $type = $this->leaveType($this->a, 5);
        $staff = $emp->user;

        // an employee applies for themselves (even if they try to name somebody else)
        $this->actingAs($staff)->post('/hrm/leave-applications', ['employee_id' => $other->id, 'leave_type_id' => $type->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'reason' => 'trip'])
            ->assertSessionHas('error');
        $this->actingAs($staff)->post('/hrm/leave-applications', ['leave_type_id' => $type->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'reason' => 'trip'])
            ->assertSessionHas('success');
        $leave = LeaveApplication::firstOrFail();
        $this->assertSame($emp->id, $leave->employee_id);

        // staff cannot approve their own leave
        $this->actingAs($staff)->post("/hrm/leave-applications/{$leave->id}/approve")->assertSessionHas('error');
        $this->assertSame('pending', $leave->refresh()->status);

        $this->actingAs($this->a)->post("/hrm/leave-applications/{$leave->id}/approve", ['approver_comment' => 'enjoy'])->assertSessionHas('success');
        $leave->refresh();
        $this->assertSame('approved', $leave->status);
        $this->assertSame($this->a->id, $leave->approved_by);

        // decided applications cannot be decided again
        $this->actingAs($this->a)->post("/hrm/leave-applications/{$leave->id}/reject")->assertSessionHas('error');

        // staff list shows only their own applications
        $this->actingAs($other->user)->get('/hrm/leave-applications')->assertOk()->assertInertia(fn ($p) => $p->where('applications.total', 0));
        $this->actingAs($staff)->get('/hrm/leave-applications')->assertOk()->assertInertia(fn ($p) => $p->where('applications.total', 1));

        // a rejected leave frees the days again
        $second = app(LeaveService::class)->apply($emp, $type, '2026-03-09', '2026-03-11', null, $this->a->id);
        $this->actingAs($this->a)->post("/hrm/leave-applications/{$second->id}/reject", ['approver_comment' => 'busy'])->assertSessionHas('success');
        $balance = collect(app(LeaveService::class)->balance($emp, 2026))->firstWhere('type_id', $type->id);
        $this->assertEquals(2, $balance['used']);
        $this->assertEquals(3, $balance['remaining']);
    }

    public function test_staff_can_withdraw_only_their_own_pending_leave(): void
    {
        $emp = $this->hire();
        $other = $this->hire('other@test.com');
        $type = $this->leaveType($this->a);
        $mine = app(LeaveService::class)->apply($emp, $type, '2026-03-02', '2026-03-02', null, $emp->user_id);
        $theirs = app(LeaveService::class)->apply($other, $type, '2026-03-03', '2026-03-03', null, $other->user_id);

        $this->actingAs($emp->user)->delete("/hrm/leave-applications/{$theirs->id}")->assertSessionHas('error');
        $this->actingAs($emp->user)->delete("/hrm/leave-applications/{$mine->id}")->assertSessionHas('success');

        $this->assertSame(1, LeaveApplication::count());
    }

    public function test_leave_type_with_applications_cannot_be_deleted(): void
    {
        $emp = $this->hire();
        $type = $this->leaveType($this->a);
        app(LeaveService::class)->apply($emp, $type, '2026-03-02', '2026-03-02', null, $this->a->id);

        $this->actingAs($this->a)->delete("/hrm/leave-types/{$type->id}")->assertSessionHas('error');
        $this->assertNotNull($type->fresh());

        LeaveApplication::query()->delete();
        $this->actingAs($this->a)->delete("/hrm/leave-types/{$type->id}")->assertSessionHas('success');
    }

    public function test_leave_application_rejects_a_leave_type_of_another_company(): void
    {
        $b = $this->company('b@test.com');
        $bType = $this->leaveType($b);
        $this->hire();

        $this->actingAs($this->a)->post('/hrm/leave-applications', ['employee_id' => Employee::first()->id, 'leave_type_id' => $bType->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-02'])
            ->assertSessionHasErrors('leave_type_id');
    }

    // ───────────── settings ─────────────

    public function test_hrm_settings_change_the_working_days(): void
    {
        $emp = $this->hire();
        $type = $this->leaveType($this->a);

        // Mon-Fri only
        $this->actingAs($this->a)->put('/hrm/settings', ['working_days' => [1, 2, 3, 4, 5], 'late_grace_minutes' => 5])->assertSessionHas('success');
        $this->actingAs($this->a)->get('/hrm/settings')->assertOk()->assertInertia(fn ($p) => $p->where('workingDays', [1, 2, 3, 4, 5])->where('lateGraceMinutes', 5));

        // Saturday is now a weekly off
        $this->expectException(HrmException::class);
        app(LeaveService::class)->apply($emp, $type, '2026-03-07', '2026-03-07', null, $this->a->id);
    }

    public function test_hrm_settings_validation_and_permission(): void
    {
        $emp = $this->hire();

        $this->actingAs($this->a)->put('/hrm/settings', ['working_days' => [], 'late_grace_minutes' => 5])->assertSessionHasErrors('working_days');
        $this->actingAs($this->a)->put('/hrm/settings', ['working_days' => [8], 'late_grace_minutes' => 5])->assertSessionHasErrors('working_days.0');
        $this->actingAs($emp->user)->put('/hrm/settings', ['working_days' => [1], 'late_grace_minutes' => 5])->assertSessionHas('error');
    }

    // ───────────── generated CRUD patches ─────────────

    public function test_shift_holiday_and_ip_rules(): void
    {
        $this->actingAs($this->a)->post('/hrm/shifts', ['name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00', 'break_minutes' => 60, 'is_night_shift' => false])->assertSessionHas('success');
        $this->actingAs($this->a)->post('/hrm/shifts', ['name' => 'Bad', 'start_time' => '9am', 'end_time' => '18:00', 'break_minutes' => 60, 'is_night_shift' => false])->assertSessionHasErrors('start_time');

        $this->actingAs($this->a)->post('/hrm/holidays', ['name' => 'X', 'start_date' => '2026-03-05', 'end_date' => '2026-03-04'])->assertSessionHasErrors('end_date');

        $this->actingAs($this->a)->post('/hrm/ip-restrictions', ['ip_address' => '10.1.1.1'])->assertSessionHas('success');
        $this->actingAs($this->a)->post('/hrm/ip-restrictions', ['ip_address' => '10.1.1.1'])->assertSessionHasErrors('ip_address');
        $this->actingAs($this->a)->post('/hrm/ip-restrictions', ['ip_address' => 'not-an-ip'])->assertSessionHasErrors('ip_address');
    }

    public function test_employee_can_be_given_a_shift_of_the_same_company_only(): void
    {
        $b = $this->company('b@test.com');
        $bShift = $this->shift($b);
        $aShift = $this->shift($this->a);

        $this->actingAs($this->a)->post('/hrm/employees', ['name' => 'X', 'email' => 'x@test.com', 'password' => 'secret-pass-1', 'employment_type' => 'full_time', 'basic_salary' => 1, 'shift_id' => $bShift->id])
            ->assertSessionHasErrors('shift_id');

        $emp = $this->hire('y@test.com', null, ['shift_id' => $aShift->id]);
        $this->assertSame($aShift->id, $emp->shift_id);
    }
}
