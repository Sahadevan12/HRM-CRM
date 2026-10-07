<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Exceptions\HrmException;
use Workdo\Hrm\Models\Branch;
use Workdo\Hrm\Models\Department;
use Workdo\Hrm\Models\Designation;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\Promotion;
use Workdo\Hrm\Models\Resignation;
use Workdo\Hrm\Models\Termination;
use Workdo\Hrm\Models\Transfer;
use Workdo\Hrm\Services\LifecycleService;

/**
 * Promotions, resignations, terminations and transfers share one controller and one React page: the server describes the form
 * (`fields`) and the table (`columns` + `cells`) of each kind, so a new kind is one entry here.
 */
class LifecycleController extends Controller
{
    private const KINDS = [
        'promotions' => ['model' => Promotion::class, 'title' => 'Promotions', 'workflow' => false],
        'resignations' => ['model' => Resignation::class, 'title' => 'Resignations', 'workflow' => true],
        'terminations' => ['model' => Termination::class, 'title' => 'Terminations', 'workflow' => true],
        'transfers' => ['model' => Transfer::class, 'title' => 'Transfers', 'workflow' => true],
    ];

    public const TERMINATION_TYPES = ['misconduct', 'performance', 'layoff', 'end_of_contract', 'other'];

    public function __construct(private LifecycleService $lifecycle)
    {
    }

    public function index(Request $request, string $kind): Response|RedirectResponse
    {
        $cfg = $this->kind($kind);
        if (!Auth::user()->can("manage-{$kind}")) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $model = $cfg['model'];

        $rows = $model::with($this->relations($kind))->where('created_by', $tenant)
            ->when($cfg['workflow'] && in_array($request->get('status'), ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('status', $request->get('status')))
            ->latest('id')->paginate((int) $request->get('per_page', 15))->withQueryString();

        $rows->getCollection()->transform(fn (Model $row) => $this->present($kind, $row));

        $user = Auth::user();

        return Inertia::render('Hrm/Lifecycle/Index', [
            'kind' => $kind,
            'title' => $cfg['title'],
            'workflow' => $cfg['workflow'],
            'columns' => $this->columns($kind),
            'fields' => $this->fields($kind, $tenant),
            'rows' => $rows,
            'can' => [
                'create' => $user->can("create-{$kind}"),
                'approve' => $cfg['workflow'] && $user->can("approve-{$kind}"),
                'delete' => $cfg['workflow'] && $user->can("delete-{$kind}"),
            ],
            'filters' => $request->only('status'),
        ]);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        $this->kind($kind);
        if (!Auth::user()->can("create-{$kind}")) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate($this->rules($kind, $tenant));
        $employee = Employee::where('created_by', $tenant)->findOrFail($data['employee_id']);
        $actor = Auth::id();

        try {
            match ($kind) {
                'promotions' => $this->lifecycle->promote($employee, (int) $data['designation_id'], $data['title'], $data['promotion_date'], $data['notes'] ?? null, $actor),
                'resignations' => $this->lifecycle->resign($employee, $data['notice_date'], $data['last_working_date'], $data['reason'] ?? null, $actor),
                'terminations' => $this->lifecycle->terminate($employee, $data['type'], $data['notice_date'], $data['termination_date'], $data['reason'] ?? null, $actor),
                'transfers' => $this->lifecycle->transfer($employee, $data['branch_id'] ?? null, $data['department_id'] ?? null, $data['transfer_date'], $data['reason'] ?? null, $actor),
            };
        } catch (HrmException $e) {
            return back()->withErrors(['employee_id' => $e->getMessage()]);
        }

        return back()->with('success', $kind === 'promotions' ? __('The promotion has been recorded.') : __('The request has been submitted.'));
    }

    public function approve(Request $request, string $kind, int $id): RedirectResponse
    {
        return $this->decide($request, $kind, $id, fn (Model $m, ?string $c) => $this->lifecycle->approve($m, $c, Auth::id()), __('The request has been approved.'));
    }

    public function reject(Request $request, string $kind, int $id): RedirectResponse
    {
        return $this->decide($request, $kind, $id, fn (Model $m, ?string $c) => $this->lifecycle->reject($m, $c, Auth::id()), __('The request has been rejected.'));
    }

    /** Only a request that did not change the employee can be removed: approved ones are history. */
    public function destroy(string $kind, int $id): RedirectResponse
    {
        $cfg = $this->kind($kind);
        $row = $cfg['model']::where('created_by', creatorId())->findOrFail($id);

        if (!$cfg['workflow'] || !Auth::user()->can("delete-{$kind}")) {
            return back()->with('error', __('Permission denied'));
        }
        if ($row->status === 'approved') {
            return back()->with('error', __('An approved request is part of the history and cannot be deleted.'));
        }

        $row->delete();

        return back()->with('success', __('The request has been deleted.'));
    }

    private function decide(Request $request, string $kind, int $id, callable $action, string $success): RedirectResponse
    {
        $cfg = $this->kind($kind);
        if (!$cfg['workflow'] || !Auth::user()->can("approve-{$kind}")) {
            return back()->with('error', __('Permission denied'));
        }

        $row = $cfg['model']::where('created_by', creatorId())->findOrFail($id);
        $comment = $request->validate(['decision_comment' => 'nullable|string|max:1000'])['decision_comment'] ?? null;

        try {
            $action($row, $comment);
        } catch (HrmException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    // ───────────── description of each kind ─────────────

    /** @return array{model: class-string<Model>, title: string, workflow: bool} */
    private function kind(string $kind): array
    {
        return self::KINDS[$kind] ?? abort(404);
    }

    /** @return array<int, string> */
    private function relations(string $kind): array
    {
        $base = ['employee:id,employee_code,user_id', 'employee.user:id,name'];

        return match ($kind) {
            'promotions' => [...$base, 'designation:id,name', 'previousDesignation:id,name'],
            'transfers' => [...$base, 'branch:id,name', 'department:id,name', 'fromBranch:id,name', 'fromDepartment:id,name'],
            default => $base,
        };
    }

    /** @return array<int, array{key: string, label: string}> */
    private function columns(string $kind): array
    {
        $columns = match ($kind) {
            'promotions' => ['employee' => 'Employee', 'change' => 'Designation', 'title' => 'Title', 'date' => 'Date'],
            'resignations' => ['employee' => 'Employee', 'notice' => 'Notice date', 'date' => 'Last working day', 'reason' => 'Reason'],
            'terminations' => ['employee' => 'Employee', 'type' => 'Type', 'notice' => 'Notice date', 'date' => 'Termination date', 'reason' => 'Reason'],
            'transfers' => ['employee' => 'Employee', 'change' => 'Transfer', 'date' => 'Transfer date', 'reason' => 'Reason'],
        };

        return collect($columns)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(string $kind, Model $r): array
    {
        $cells = ['employee' => $r->employee->employee_code . ' · ' . $r->employee->user->name, 'reason' => $r->reason ?? null];

        match ($kind) {
            'promotions' => $cells += ['change' => ($r->previousDesignation?->name ?? '—') . ' → ' . $r->designation->name, 'title' => $r->title, 'date' => $r->promotion_date->toDateString()],
            'resignations' => $cells += ['notice' => $r->notice_date->toDateString(), 'date' => $r->last_working_date->toDateString()],
            'terminations' => $cells += ['type' => $r->type, 'notice' => $r->notice_date->toDateString(), 'date' => $r->termination_date->toDateString()],
            'transfers' => $cells += [
                'change' => ($r->fromBranch?->name ?? '—') . ' / ' . ($r->fromDepartment?->name ?? '—') . ' → ' . ($r->branch?->name ?? '—') . ' / ' . ($r->department?->name ?? '—'),
                'date' => $r->transfer_date->toDateString(),
            ],
        };

        return [
            'id' => $r->id,
            'cells' => $cells,
            'status' => $r->status ?? 'applied',
            'applied' => $r->applied_at !== null || $kind === 'promotions',
            'comment' => $r->decision_comment ?? null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fields(string $kind, int $tenant): array
    {
        $employee = ['name' => 'employee_id', 'label' => 'Employee', 'type' => 'select', 'required' => true, 'options' => Employee::with('user:id,name')
            ->where('created_by', $tenant)->where('status', 'active')->orderBy('employee_code')->get(['id', 'employee_code', 'user_id'])
            ->map(fn ($e) => ['value' => (string) $e->id, 'label' => $e->employee_code . ' · ' . $e->user->name])->all()];
        $list = fn (string $model) => $model::where('created_by', $tenant)->orderBy('name')->get(['id', 'name'])->map(fn ($m) => ['value' => (string) $m->id, 'label' => $m->name])->all();
        $date = fn (string $name, string $label) => ['name' => $name, 'label' => $label, 'type' => 'date', 'required' => true];
        $reason = ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => false];

        return match ($kind) {
            'promotions' => [$employee, ['name' => 'designation_id', 'label' => 'New designation', 'type' => 'select', 'required' => true, 'options' => $list(Designation::class)],
                ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true], $date('promotion_date', 'Promotion date'),
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false]],
            'resignations' => [$employee, $date('notice_date', 'Notice date'), $date('last_working_date', 'Last working day'), $reason],
            'terminations' => [$employee, ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true,
                'options' => array_map(fn ($t) => ['value' => $t, 'label' => $t], self::TERMINATION_TYPES)],
                $date('notice_date', 'Notice date'), $date('termination_date', 'Termination date'), $reason],
            'transfers' => [$employee, ['name' => 'branch_id', 'label' => 'New branch', 'type' => 'select', 'required' => false, 'options' => $list(Branch::class)],
                ['name' => 'department_id', 'label' => 'New department', 'type' => 'select', 'required' => false, 'options' => $list(Department::class)],
                $date('transfer_date', 'Transfer date'), $reason],
        };
    }

    /** @return array<string, mixed> */
    private function rules(string $kind, int $tenant): array
    {
        $own = fn (string $table) => Rule::exists($table, 'id')->where('created_by', $tenant);
        $date = 'required|date';
        $employee = ['required', Rule::exists('employees', 'id')->where('created_by', $tenant)->where('status', 'active')];

        return match ($kind) {
            'promotions' => ['employee_id' => $employee, 'designation_id' => ['required', $own('designations')], 'title' => 'required|string|max:255', 'promotion_date' => $date, 'notes' => 'nullable|string|max:2000'],
            'resignations' => ['employee_id' => $employee, 'notice_date' => $date, 'last_working_date' => 'required|date|after_or_equal:notice_date', 'reason' => 'nullable|string|max:2000'],
            'terminations' => ['employee_id' => $employee, 'type' => ['required', Rule::in(self::TERMINATION_TYPES)], 'notice_date' => $date, 'termination_date' => 'required|date|after_or_equal:notice_date', 'reason' => 'nullable|string|max:2000'],
            'transfers' => ['employee_id' => $employee, 'branch_id' => ['nullable', $own('branches')], 'department_id' => ['nullable', $own('departments')], 'transfer_date' => $date, 'reason' => 'nullable|string|max:2000'],
        };
    }
}
