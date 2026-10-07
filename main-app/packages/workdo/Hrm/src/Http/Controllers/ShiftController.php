<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateShift;
use Workdo\Hrm\Events\DestroyShift;
use Workdo\Hrm\Events\UpdateShift;
use Workdo\Hrm\Http\Requests\SaveShiftRequest;
use Workdo\Hrm\Models\Shift;


class ShiftController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'start_time', 'end_time', 'break_minutes'];

    private const SEARCHABLE = ['name'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-shifts')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Shift::with([])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/Shifts/Index', [
            'shifts' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),

        ]);
    }

    public function store(SaveShiftRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-shifts')) {
            return back()->with('error', __('Permission denied'));
        }

        $shift = Shift::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateShift::dispatch($request, $shift);

        return back()->with('success', __('The shift has been created successfully.'));
    }

    public function update(SaveShiftRequest $request, Shift $shift): RedirectResponse
    {
        if (!Auth::user()->can('edit-shifts') || $shift->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $shift->update($request->validated());

        UpdateShift::dispatch($request, $shift);

        return back()->with('success', __('The shift details are updated successfully.'));
    }

    public function destroy(Request $request, Shift $shift): RedirectResponse
    {
        if (!Auth::user()->can('delete-shifts') || $shift->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyShift::dispatch($request, $shift);
        $shift->delete();

        return back()->with('success', __('The shift has been deleted.'));
    }
}
