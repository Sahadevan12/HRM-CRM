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
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\LeaveApplication;
use Workdo\Hrm\Models\LeaveType;
use Workdo\Hrm\Services\LeaveService;

class LeaveApplicationController extends Controller
{
    public function __construct(private LeaveService $leaves)
    {
    }

    /** HR (manage-leave-applications) sees everybody, an employee (apply-leave) only their own applications. */
    public function index(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();
        $hr = $user->can('manage-leave-applications');

        if (!$hr && !$user->can('apply-leave')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $me = $this->me();

        $applications = LeaveApplication::with(['employee:id,employee_code,user_id', 'employee.user:id,name', 'type:id,name,is_paid', 'approver:id,name'])
            ->where('created_by', $tenant)
            ->when(!$hr, fn ($q) => $q->where('employee_id', $me?->id ?? 0))
            ->when($hr && $request->filled('employee'), fn ($q) => $q->where('employee_id', $request->get('employee')))
            ->when(in_array($request->get('status'), LeaveApplication::STATUSES, true), fn ($q) => $q->where('status', $request->get('status')))
            ->latest('start_date')->latest('id')
            ->paginate((int) $request->get('per_page', 15))->withQueryString();

        return Inertia::render('Hrm/LeaveApplications/Index', [
            'applications' => $applications,
            'leaveTypes' => LeaveType::where('created_by', $tenant)->orderBy('name')->get(['id', 'name', 'days_per_year', 'is_paid']),
            'employees' => $hr ? $this->employeeOptions($tenant) : [],
            'myEmployeeId' => $me?->id,
            'can' => [
                'manage' => $hr,
                'approve' => $user->can('approve-leave-applications'),
                'delete' => $user->can('delete-leave-applications'),
                'apply' => $user->can('apply-leave') || $hr,
            ],
            'balances' => $me ? $this->leaves->balance($me, (int) now()->format('Y')) : [],
            'filters' => $request->only(['employee', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $hr = $user->can('manage-leave-applications');

        if (!$hr && !$user->can('apply-leave')) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate([
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->where('created_by', $tenant)],
            'leave_type_id' => ['required', Rule::exists('leave_types', 'id')->where('created_by', $tenant)],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:1000',
        ]);

        // an employee can only apply for themselves; HR may apply for anybody
        $employee = $hr && !empty($data['employee_id']) ? Employee::findOrFail($data['employee_id']) : $this->me();
        if (!$employee || (!$hr && !empty($data['employee_id']) && (int) $data['employee_id'] !== $employee->id)) {
            return back()->with('error', __('Your login has no employee profile. Ask HR to create one.'));
        }

        try {
            $this->leaves->apply($employee, LeaveType::findOrFail($data['leave_type_id']), $data['start_date'], $data['end_date'], $data['reason'] ?? null, Auth::id());
        } catch (HrmException $e) {
            return back()->withErrors(['start_date' => $e->getMessage()]);
        }

        return back()->with('success', __('The leave application has been submitted.'));
    }

    public function approve(Request $request, LeaveApplication $leaveApplication): RedirectResponse
    {
        return $this->decide($request, $leaveApplication, fn ($comment) => $this->leaves->approve($leaveApplication, $comment, Auth::id()), __('The leave has been approved.'));
    }

    public function reject(Request $request, LeaveApplication $leaveApplication): RedirectResponse
    {
        return $this->decide($request, $leaveApplication, fn ($comment) => $this->leaves->reject($leaveApplication, $comment, Auth::id()), __('The leave has been rejected.'));
    }

    /** Owner can withdraw a PENDING application; HR with delete permission can remove any. */
    public function destroy(LeaveApplication $leaveApplication): RedirectResponse
    {
        $user = Auth::user();
        $own = $leaveApplication->employee->user_id === $user->id && $leaveApplication->status === 'pending';

        if ($leaveApplication->created_by !== creatorId() || !($own || $user->can('delete-leave-applications'))) {
            return back()->with('error', __('Permission denied'));
        }

        $leaveApplication->delete();

        return back()->with('success', __('The leave application has been deleted.'));
    }

    /** Leave balance of one employee for a year (HR: anybody, employees: themselves). */
    public function balance(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();
        $hr = $user->can('manage-leave-applications');

        if (!$hr && !$user->can('apply-leave')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $me = $this->me();
        $employee = $hr && $request->filled('employee') ? Employee::where('created_by', $tenant)->find($request->get('employee')) : $me;
        $year = max(2000, min(2100, (int) $request->get('year', now()->format('Y'))));

        return Inertia::render('Hrm/LeaveBalance/Index', [
            'balances' => $employee ? $this->leaves->balance($employee, $year) : [],
            'employee' => $employee ? ['id' => $employee->id, 'name' => $employee->user->name, 'code' => $employee->employee_code] : null,
            'employees' => $hr ? $this->employeeOptions($tenant) : [],
            'year' => $year,
        ]);
    }

    private function decide(Request $request, LeaveApplication $leave, callable $action, string $success): RedirectResponse
    {
        if (!Auth::user()->can('approve-leave-applications') || $leave->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $comment = $request->validate(['approver_comment' => 'nullable|string|max:1000'])['approver_comment'] ?? null;

        try {
            $action($comment);
        } catch (HrmException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    private function me(): ?Employee
    {
        return Employee::with('user:id,name')->where('user_id', Auth::id())->where('created_by', creatorId())->first();
    }

    private function employeeOptions(int $tenant)
    {
        return Employee::with('user:id,name')->where('created_by', $tenant)->where('status', 'active')->orderBy('employee_code')->get(['id', 'employee_code', 'user_id'])
            ->map(fn ($e) => ['id' => $e->id, 'code' => $e->employee_code, 'name' => $e->user->name]);
    }
}
