<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateDesignation;
use Workdo\Hrm\Events\DestroyDesignation;
use Workdo\Hrm\Events\UpdateDesignation;
use Workdo\Hrm\Http\Requests\SaveDesignationRequest;
use Workdo\Hrm\Models\Designation;
use Workdo\Hrm\Models\Department;

class DesignationController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'department_id'];

    private const SEARCHABLE = ['name'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-designations')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Designation::with(['department:id,name'])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/Designations/Index', [
            'designations' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
            'departmentOptions' => Department::where('created_by', creatorId())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(SaveDesignationRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-designations')) {
            return back()->with('error', __('Permission denied'));
        }

        $designation = Designation::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateDesignation::dispatch($request, $designation);

        return back()->with('success', __('The designation has been created successfully.'));
    }

    public function update(SaveDesignationRequest $request, Designation $designation): RedirectResponse
    {
        if (!Auth::user()->can('edit-designations') || $designation->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $designation->update($request->validated());

        UpdateDesignation::dispatch($request, $designation);

        return back()->with('success', __('The designation details are updated successfully.'));
    }

    public function destroy(Request $request, Designation $designation): RedirectResponse
    {
        if (!Auth::user()->can('delete-designations') || $designation->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyDesignation::dispatch($request, $designation);
        $designation->delete();

        return back()->with('success', __('The designation has been deleted.'));
    }
}
