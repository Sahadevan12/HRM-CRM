<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateDepartment;
use Workdo\Hrm\Events\DestroyDepartment;
use Workdo\Hrm\Events\UpdateDepartment;
use Workdo\Hrm\Http\Requests\SaveDepartmentRequest;
use Workdo\Hrm\Models\Department;
use Workdo\Hrm\Models\Branch;

class DepartmentController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'branch_id'];

    private const SEARCHABLE = ['name'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-departments')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Department::with(['branch:id,name'])->where('created_by', creatorId());

        if ($request->filled('search') && self::SEARCHABLE) {
            $term = '%' . $request->get('search') . '%';
            $query->where(function ($q) use ($term) {
                foreach (self::SEARCHABLE as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }

        $sortField = in_array($request->get('sort'), self::SORTABLE, true) ? $request->get('sort') : 'created_at';
        $sortDirection = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        return Inertia::render('Hrm/Departments/Index', [
            'departments' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
            'branchOptions' => Branch::where('created_by', creatorId())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(SaveDepartmentRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-departments')) {
            return back()->with('error', __('Permission denied'));
        }

        $department = Department::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateDepartment::dispatch($request, $department);

        return back()->with('success', __('The department has been created successfully.'));
    }

    public function update(SaveDepartmentRequest $request, Department $department): RedirectResponse
    {
        if (!Auth::user()->can('edit-departments') || $department->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $department->update($request->validated());

        UpdateDepartment::dispatch($request, $department);

        return back()->with('success', __('The department details are updated successfully.'));
    }

    public function destroy(Request $request, Department $department): RedirectResponse
    {
        if (!Auth::user()->can('delete-departments') || $department->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyDepartment::dispatch($request, $department);
        $department->delete();

        return back()->with('success', __('The department has been deleted.'));
    }
}
