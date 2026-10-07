<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Workdo\Hrm\Http\Requests\SaveEmployeeRequest;
use Workdo\Hrm\Models\Branch;
use Workdo\Hrm\Models\Department;
use Workdo\Hrm\Models\Designation;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\EmployeeDocumentType;
use Workdo\Hrm\Services\EmployeeService;

class EmployeeController extends Controller
{
    private const SORTABLE = ['employee_code', 'date_of_joining', 'created_at'];

    public function __construct(private EmployeeService $employees)
    {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-employees')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $sort = in_array($request->get('sort'), self::SORTABLE, true) ? $request->get('sort') : 'employee_code';

        $employees = Employee::with(['user:id,name,email,is_enable_login', 'branch:id,name', 'department:id,name', 'designation:id,name'])
            ->where('created_by', $tenant)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($s) => $s->where('employee_code', 'like', $term)->orWhere('phone', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->when($request->filled('branch'), fn ($q) => $q->where('branch_id', $request->get('branch')))
            ->when($request->filled('department'), fn ($q) => $q->where('department_id', $request->get('department')))
            ->when(in_array($request->get('status'), Employee::STATUSES, true), fn ($q) => $q->where('status', $request->get('status')))
            ->orderBy($sort, $request->get('direction') === 'desc' ? 'desc' : 'asc')
            ->paginate((int) $request->get('per_page', 15))
            ->withQueryString();

        return Inertia::render('Hrm/Employees/Index', [
            'employees' => $employees,
            'branches' => Branch::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'statuses' => Employee::STATUSES,
            'filters' => $request->only(['search', 'branch', 'department', 'status']),
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        if (!Auth::user()->can('create-employees')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        return Inertia::render('Hrm/Employees/Form', $this->formLookups() + ['employee' => null, 'nextCode' => $this->employees->nextCode(creatorId())]);
    }

    public function store(SaveEmployeeRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-employees')) {
            return back()->with('error', __('Permission denied'));
        }

        $seat = canCreateUser();
        if (!$seat['can_create']) {
            return back()->with('error', $seat['message']);
        }

        $employee = $this->employees->create($request->validated(), creatorId(), Auth::id());

        return redirect()->route('hrm.employees.show', $employee->id)->with('success', __('The employee has been created successfully.'));
    }

    public function show(Employee $employee): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-employees') || $employee->created_by !== creatorId()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $employee->load(['user:id,name,email,is_enable_login', 'branch:id,name', 'department:id,name', 'designation:id,name', 'documents.type:id,name'])
            ->makeVisible(['account_number', 'bank_code', 'tax_id']); // only people allowed to manage employees get here

        return Inertia::render('Hrm/Employees/Show', [
            'employee' => $employee,
            'role' => $employee->user->getRoleNames()->first(),
            'documentTypes' => EmployeeDocumentType::where('created_by', creatorId())->orderBy('name')->get(['id', 'name', 'is_required']),
            'canEdit' => Auth::user()->can('edit-employees'),
            'canDelete' => Auth::user()->can('delete-employees'),
            'canDocuments' => Auth::user()->can('manage-employee-documents'),
        ]);
    }

    public function edit(Employee $employee): Response|RedirectResponse
    {
        if (!Auth::user()->can('edit-employees') || $employee->created_by !== creatorId()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $employee->load('user:id,name,email,is_enable_login')->makeVisible(['account_number', 'bank_code', 'tax_id']);

        return Inertia::render('Hrm/Employees/Form', $this->formLookups() + ['employee' => $employee, 'role' => $employee->user->getRoleNames()->first(), 'nextCode' => null]);
    }

    public function update(SaveEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        if (!Auth::user()->can('edit-employees') || $employee->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $this->employees->update($employee, $request->validated());

        return redirect()->route('hrm.employees.show', $employee->id)->with('success', __('The employee details are updated successfully.'));
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        if (!Auth::user()->can('delete-employees') || $employee->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }
        if ($employee->user_id === Auth::id()) {
            return back()->with('error', __('You cannot delete your own account.'));
        }

        $this->employees->delete($employee);

        return redirect()->route('hrm.employees.index')->with('success', __('The employee has been deleted.'));
    }

    /** @return array<string, mixed> dropdown data for the form */
    private function formLookups(): array
    {
        $tenant = creatorId();

        return [
            'branches' => Branch::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('created_by', $tenant)->orderBy('name')->get(['id', 'name', 'branch_id']),
            'designations' => Designation::where('created_by', $tenant)->orderBy('name')->get(['id', 'name', 'department_id']),
            'roles' => Role::where('created_by', $tenant)->orWhere(fn ($q) => $q->whereNull('created_by')->where('name', 'staff'))->orderBy('label')->get(['name', 'label']),
            'options' => [
                'genders' => Employee::GENDERS,
                'employmentTypes' => Employee::EMPLOYMENT_TYPES,
                'statuses' => Employee::STATUSES,
            ],
        ];
    }
}
