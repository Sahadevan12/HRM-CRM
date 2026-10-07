<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Exceptions\HrmException;
use Workdo\Hrm\Models\Attendance;
use Workdo\Hrm\Models\Department;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Services\AttendanceService;
use Workdo\Hrm\Services\AttendanceSummary;
use Workdo\Hrm\Services\WorkCalendar;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceService $attendance, private AttendanceSummary $summary)
    {
    }

    // ───────────── HR: list / manual entries ─────────────

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-attendances')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $from = $this->date($request->get('from'), now()->startOfMonth()->toDateString());
        $to = $this->date($request->get('to'), now()->toDateString());

        $records = Attendance::with(['employee:id,employee_code,user_id', 'employee.user:id,name', 'shift:id,name'])
            ->where('created_by', $tenant)->whereBetween('date', [$from, $to])
            ->when($request->filled('employee'), fn ($q) => $q->where('employee_id', $request->get('employee')))
            ->when($request->filled('department'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->get('department'))))
            ->when(in_array($request->get('status'), Attendance::STATUSES, true), fn ($q) => $q->where('status', $request->get('status')))
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate((int) $request->get('per_page', 20))->withQueryString();

        return Inertia::render('Hrm/Attendances/Index', [
            'records' => $records,
            'employees' => $this->employeeOptions($tenant),
            'departments' => Department::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'statuses' => Attendance::STATUSES,
            'filters' => ['from' => $from, 'to' => $to] + $request->only(['employee', 'department', 'status']),
            'tz' => $this->attendance->tz($tenant),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-attendances')) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $this->validated($request);

        try {
            $this->attendance->saveManual(Employee::where('created_by', creatorId())->findOrFail($data['employee_id']), $data, Auth::id());
        } catch (HrmException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        return back()->with('success', __('The attendance has been saved.'));
    }

    public function update(Request $request, Attendance $attendance): RedirectResponse
    {
        if (!Auth::user()->can('edit-attendances') || $attendance->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $this->validated($request);
        // an existing record always stays with its employee
        $data['employee_id'] = $attendance->employee_id;

        try {
            $this->attendance->saveManual($attendance->employee, $data, Auth::id(), $attendance);
        } catch (HrmException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        return back()->with('success', __('The attendance has been updated.'));
    }

    public function destroy(Attendance $attendance): RedirectResponse
    {
        if (!Auth::user()->can('delete-attendances') || $attendance->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $attendance->delete();

        return back()->with('success', __('The attendance has been deleted.'));
    }

    // ───────────── HR: monthly summary ─────────────

    public function summary(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-attendances')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->get('month')) ? $request->get('month') : now()->format('Y-m');
        $from = "{$month}-01";
        $to = \Illuminate\Support\Carbon::parse($from)->endOfMonth()->toDateString();

        $rows = Employee::with('user:id,name')->where('created_by', $tenant)->where('status', 'active')
            ->when($request->filled('department'), fn ($q) => $q->where('department_id', $request->get('department')))
            ->orderBy('employee_code')->get()
            ->map(fn (Employee $e) => ['id' => $e->id, 'code' => $e->employee_code, 'name' => $e->user->name] + $this->summary->forEmployee($e, $from, $to)['totals']);

        $calendar = WorkCalendar::for($tenant);

        return Inertia::render('Hrm/Attendances/Summary', [
            'rows' => $rows,
            'month' => $month,
            'workingDays' => $calendar->countWorkingDays($from, $to),
            'holidays' => $calendar->holidays($from, $to),
            'departments' => Department::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('department'),
        ]);
    }

    // ───────────── self service ─────────────

    public function my(): Response|RedirectResponse
    {
        if (!Auth::user()->can('clock-attendance')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $employee = $this->me();
        $tenant = creatorId();

        return Inertia::render('Hrm/Attendances/My', [
            'employee' => $employee?->load('shift:id,name,start_time,end_time'),
            'open' => $employee ? Attendance::where('employee_id', $employee->id)->whereNotNull('clock_in')->whereNull('clock_out')->latest('clock_in')->first() : null,
            'recent' => $employee ? Attendance::where('employee_id', $employee->id)->orderByDesc('date')->limit(14)->get() : [],
            'tz' => $this->attendance->tz($tenant),
        ]);
    }

    public function clockIn(Request $request): RedirectResponse
    {
        return $this->clock($request, fn (Employee $e) => $this->attendance->clockIn($e, $request->ip()), __('You are clocked in. Have a good day!'));
    }

    public function clockOut(Request $request): RedirectResponse
    {
        return $this->clock($request, fn (Employee $e) => $this->attendance->clockOut($e, $request->ip()), __('You are clocked out.'));
    }

    // ───────────── helpers ─────────────

    private function clock(Request $request, callable $action, string $success): RedirectResponse
    {
        if (!Auth::user()->can('clock-attendance')) {
            return back()->with('error', __('Permission denied'));
        }

        $employee = $this->me();
        if (!$employee) {
            return back()->with('error', __('Your login has no employee profile. Ask HR to create one.'));
        }

        try {
            $action($employee);
        } catch (HrmException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    /** The HR profile of the logged-in user (null for owners / users without a profile). */
    private function me(): ?Employee
    {
        return Employee::where('user_id', Auth::id())->where('created_by', creatorId())->first();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('created_by', creatorId())],
            'date' => 'required|date',
            'clock_in' => 'nullable|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
            'status' => ['nullable', Rule::in(Attendance::STATUSES)],
            'notes' => 'nullable|string|max:1000',
        ]);
    }

    private function employeeOptions(int $tenant)
    {
        return Employee::with('user:id,name')->where('created_by', $tenant)->orderBy('employee_code')->get(['id', 'employee_code', 'user_id', 'status'])
            ->map(fn ($e) => ['id' => $e->id, 'code' => $e->employee_code, 'name' => $e->user->name, 'status' => $e->status]);
    }

    private function date(?string $value, string $fallback): string
    {
        return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : $fallback;
    }
}
