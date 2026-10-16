<?php

namespace Workdo\Hrm\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Workdo\Hrm\Exceptions\HrmException;
use Workdo\Hrm\Http\Controllers\PayrollController;
use Workdo\Hrm\Models\Acknowledgment;
use Workdo\Hrm\Models\Announcement;
use Workdo\Hrm\Models\Attendance;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\LeaveApplication;
use Workdo\Hrm\Models\LeaveType;
use Workdo\Hrm\Models\Payslip;
use Workdo\Hrm\Services\AttendanceService;
use Workdo\Hrm\Services\LeaveService;
use Workdo\Hrm\Services\PayslipDocument;
use Workdo\Hrm\Services\SelfResignation;
use Workdo\Hrm\Models\Resignation;

/**
 * Employee self service for the mobile app: profile, clock in / out, own attendance, apply for leave and see the balance, own payslips (also as PDF),
 * announcements. Everything is about the logged-in employee only; HR functions stay on the website. Same permissions as the web pages.
 */
class HrmApiController extends Controller
{
    public function __construct(private AttendanceService $attendance, private LeaveService $leaves)
    {
    }

    public function me(): JsonResponse
    {
        if (!$employee = $this->employee()) {
            return $this->noProfile();
        }
        $employee->load('user:id,name,email', 'branch:id,name', 'department:id,name', 'designation:id,name', 'shift:id,name,start_time,end_time');

        return response()->json(['data' => [
            'id' => $employee->id, 'code' => $employee->employee_code, 'name' => $employee->user->name, 'email' => $employee->user->email, 'status' => $employee->status,
            'branch' => $employee->branch?->name, 'department' => $employee->department?->name, 'designation' => $employee->designation?->name,
            'joined' => $employee->date_of_joining?->toDateString(),
            'shift' => $employee->shift ? ['name' => $employee->shift->name, 'start' => substr($employee->shift->start_time, 0, 5), 'end' => substr($employee->shift->end_time, 0, 5)] : null,
        ]]);
    }

    // ───────────── attendance ─────────────

    /** Today's state (are you clocked in?) and the last 14 days. */
    public function attendance(): JsonResponse
    {
        if ($denied = $this->need('clock-attendance')) {
            return $denied;
        }
        if (!$employee = $this->employee()) {
            return $this->noProfile();
        }

        $open = Attendance::where('employee_id', $employee->id)->whereNotNull('clock_in')->whereNull('clock_out')->latest('clock_in')->first();

        return response()->json([
            'timezone' => $this->attendance->tz(creatorId()),
            'clocked_in' => (bool) $open,
            'since' => $open?->in_time,
            'recent' => Attendance::where('employee_id', $employee->id)->orderByDesc('date')->limit(14)->get()
                ->map(fn (Attendance $a) => ['date' => $a->date->toDateString(), 'in' => $a->in_time, 'out' => $a->out_time, 'hours' => $a->total_hours, 'overtime' => $a->overtime_hours, 'late_minutes' => $a->late_minutes, 'status' => $a->status]),
        ]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        return $this->clock($request, fn (Employee $e) => $this->attendance->clockIn($e, $request->ip()));
    }

    public function clockOut(Request $request): JsonResponse
    {
        return $this->clock($request, fn (Employee $e) => $this->attendance->clockOut($e, $request->ip()));
    }

    // ───────────── leave ─────────────

    public function leaves(Request $request): JsonResponse
    {
        if ($denied = $this->need('apply-leave')) {
            return $denied;
        }
        if (!$employee = $this->employee()) {
            return $this->noProfile();
        }

        $year = (int) $request->get('year', now()->format('Y'));

        return response()->json([
            'types' => LeaveType::where('created_by', creatorId())->orderBy('name')->get(['id', 'name', 'days_per_year', 'is_paid']),
            'balances' => $this->leaves->balance($employee, max(2000, min(2100, $year))),
            'applications' => LeaveApplication::with('type:id,name')->where('employee_id', $employee->id)->latest('start_date')->latest('id')->limit(50)->get()
                ->map(fn (LeaveApplication $l) => [
                    'id' => $l->id, 'type' => $l->type->name, 'from' => $l->start_date->toDateString(), 'to' => $l->end_date->toDateString(), 'days' => $l->total_days,
                    'reason' => $l->reason, 'status' => $l->status, 'comment' => $l->approver_comment,
                ]),
        ]);
    }

    public function applyLeave(Request $request): JsonResponse
    {
        if ($denied = $this->need('apply-leave')) {
            return $denied;
        }
        if (!$employee = $this->employee()) {
            return $this->noProfile();
        }

        $data = $request->validate([
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')->where('created_by', creatorId())],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $leave = $this->leaves->apply($employee, LeaveType::findOrFail($data['leave_type_id']), $data['start_date'], $data['end_date'], $data['reason'] ?? null, Auth::id());
        } catch (HrmException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['start_date' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => ['id' => $leave->id, 'days' => $leave->total_days, 'status' => $leave->status]], 201);
    }

    /** Only your own, and only while it is pending. */
    public function withdrawLeave(LeaveApplication $leave): JsonResponse
    {
        $employee = $this->employee();

        if (!$employee || $leave->employee_id !== $employee->id || $leave->created_by !== creatorId()) {
            return response()->json(['message' => __('Permission denied')], 403);
        }
        if ($leave->status !== 'pending') {
            return response()->json(['message' => __('Only a pending application can be withdrawn.')], 422);
        }

        $leave->delete();

        return response()->json(['ok' => true]);
    }

    // ───────────── resignation ─────────────

    public function resignation(SelfResignation $resignations): JsonResponse
    {
        if ($denied = $this->need('apply-resignation')) {
            return $denied;
        }
        if (!$employee = $this->employee()) {
            return $this->noProfile();
        }

        $history = $resignations->history($employee);

        return response()->json(['can_apply' => $employee->isActive() && !collect($history)->contains('status', 'pending'), 'data' => $history]);
    }

    public function resign(Request $request, SelfResignation $resignations): JsonResponse
    {
        if ($denied = $this->need('apply-resignation')) {
            return $denied;
        }
        if (!$employee = $this->employee()) {
            return $this->noProfile();
        }

        $data = $request->validate(['last_working_date' => 'required|date', 'reason' => 'nullable|string|max:2000']);

        try {
            $resignation = $resignations->apply($employee, $data['last_working_date'], $data['reason'] ?? null);
        } catch (HrmException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['last_working_date' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => ['id' => $resignation->id, 'status' => $resignation->status]], 201);
    }

    public function withdrawResignation(Resignation $resignation, SelfResignation $resignations): JsonResponse
    {
        $employee = $this->employee();
        if (!$employee || !Auth::user()->can('apply-resignation')) {
            return response()->json(['message' => __('Permission denied')], 403);
        }

        try {
            $resignations->withdraw($resignation, $employee);
        } catch (HrmException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getMessage() === __('Permission denied') ? 403 : 422);
        }

        return response()->json(['ok' => true]);
    }

    // ───────────── payslips ─────────────

    public function payslips(): JsonResponse
    {
        if ($denied = $this->need('view-payslips')) {
            return $denied;
        }
        if (!$employee = $this->employee()) {
            return $this->noProfile();
        }

        return response()->json(['data' => Payslip::with('payroll:id,month,status,paid_on')->where('employee_id', $employee->id)
            ->whereHas('payroll', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->latest('id')->get()
            ->map(fn (Payslip $p) => $this->payslipSummary($p))]);
    }

    public function payslip(Payslip $payslip): JsonResponse
    {
        if (!$this->mayRead($payslip)) {
            return response()->json(['message' => __('Permission denied')], 403);
        }

        $payslip->load('payroll', 'lines');

        return response()->json(['data' => $this->payslipSummary($payslip) + ['lines' => $payslip->lines->map(fn ($l) => ['kind' => $l->kind, 'label' => $l->label, 'amount' => $l->amount, 'earning' => $l->isEarning()])]]);
    }

    public function payslipPdf(Payslip $payslip, PayslipDocument $documents): Response|JsonResponse
    {
        if (!$this->mayRead($payslip)) {
            return response()->json(['message' => __('Permission denied')], 403);
        }

        return $documents->render($payslip)->download($documents->filename($payslip));
    }

    // ───────────── announcements ─────────────

    public function announcements(): JsonResponse
    {
        if ($denied = $this->need('view-announcements')) {
            return $denied;
        }
        if (!$employee = $this->employee()) {
            return $this->noProfile();
        }

        $done = Acknowledgment::where('kind', 'announcement')->where('employee_id', $employee->id)->pluck('ref_id');

        return response()->json(['data' => Announcement::with('category:id,name')->where('created_by', creatorId())->current()->visibleTo($employee)->latest('start_date')->latest('id')->get()
            ->map(fn (Announcement $a) => [
                'id' => $a->id, 'title' => $a->title, 'body' => $a->body, 'category' => $a->category?->name, 'from' => $a->start_date->toDateString(),
                'requires_acknowledgment' => $a->requires_acknowledgment, 'acknowledged' => $done->contains($a->id),
            ])]);
    }

    public function acknowledge(Announcement $announcement): JsonResponse
    {
        $employee = $this->employee();
        $visible = $employee && Auth::user()->can('view-announcements') && $announcement->created_by === creatorId()
            && Announcement::whereKey($announcement->id)->current()->visibleTo($employee)->exists();

        if (!$visible) {
            return response()->json(['message' => __('Permission denied')], 403);
        }

        Acknowledgment::firstOrCreate(
            ['kind' => 'announcement', 'ref_id' => $announcement->id, 'employee_id' => $employee->id],
            ['acknowledged_at' => now(), 'created_by' => creatorId()],
        );

        return response()->json(['ok' => true]);
    }

    // ───────────── helpers ─────────────

    private function employee(): ?Employee
    {
        return Employee::where('user_id', Auth::id())->where('created_by', creatorId())->first();
    }

    private function noProfile(): JsonResponse
    {
        return response()->json(['message' => __('Your login has no employee profile. Ask HR to create one.'), 'code' => 'no_employee_profile'], 404);
    }

    private function need(string $permission): ?JsonResponse
    {
        return Auth::user()->can($permission) ? null : response()->json(['message' => __('Permission denied')], 403);
    }

    private function clock(Request $request, callable $action): JsonResponse
    {
        if ($denied = $this->need('clock-attendance')) {
            return $denied;
        }
        if (!$employee = $this->employee()) {
            return $this->noProfile();
        }

        try {
            $action($employee);
        } catch (HrmException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->attendance();
    }

    private function mayRead(Payslip $payslip): bool
    {
        return PayrollController::mayRead($payslip, Auth::user());
    }

    /** @return array<string, mixed> */
    private function payslipSummary(Payslip $p): array
    {
        return ['id' => $p->id, 'month' => $p->payroll->month, 'status' => $p->payroll->status, 'earnings' => $p->earnings, 'deductions' => $p->deductions, 'net_pay' => $p->net_pay, 'working_days' => $p->working_days, 'unpaid_days' => $p->unpaid_days];
    }
}
