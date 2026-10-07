<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateLeaveType;
use Workdo\Hrm\Events\DestroyLeaveType;
use Workdo\Hrm\Events\UpdateLeaveType;
use Workdo\Hrm\Http\Requests\SaveLeaveTypeRequest;
use Workdo\Hrm\Models\LeaveType;


class LeaveTypeController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'days_per_year'];

    private const SEARCHABLE = ['name', 'description'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-leave-types')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = LeaveType::with([])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/LeaveTypes/Index', [
            'leaveTypes' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),

        ]);
    }

    public function store(SaveLeaveTypeRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-leave-types')) {
            return back()->with('error', __('Permission denied'));
        }

        $leaveType = LeaveType::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateLeaveType::dispatch($request, $leaveType);

        return back()->with('success', __('The leave type has been created successfully.'));
    }

    public function update(SaveLeaveTypeRequest $request, LeaveType $leaveType): RedirectResponse
    {
        if (!Auth::user()->can('edit-leave-types') || $leaveType->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $leaveType->update($request->validated());

        UpdateLeaveType::dispatch($request, $leaveType);

        return back()->with('success', __('The leave type details are updated successfully.'));
    }

    public function destroy(Request $request, LeaveType $leaveType): RedirectResponse
    {
        if (!Auth::user()->can('delete-leave-types') || $leaveType->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        if ($leaveType->applications()->exists()) {
            return back()->with('error', __('This leave type has leave applications and cannot be deleted.'));
        }

        DestroyLeaveType::dispatch($request, $leaveType);
        $leaveType->delete();

        return back()->with('success', __('The leave type has been deleted.'));
    }
}
