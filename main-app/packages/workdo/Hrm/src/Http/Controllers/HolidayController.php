<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateHoliday;
use Workdo\Hrm\Events\DestroyHoliday;
use Workdo\Hrm\Events\UpdateHoliday;
use Workdo\Hrm\Http\Requests\SaveHolidayRequest;
use Workdo\Hrm\Models\Holiday;


class HolidayController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'start_date', 'end_date'];

    private const SEARCHABLE = ['name', 'description'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-holidays')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Holiday::with([])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/Holidays/Index', [
            'holidays' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),

        ]);
    }

    public function store(SaveHolidayRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-holidays')) {
            return back()->with('error', __('Permission denied'));
        }

        $holiday = Holiday::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateHoliday::dispatch($request, $holiday);

        return back()->with('success', __('The holiday has been created successfully.'));
    }

    public function update(SaveHolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        if (!Auth::user()->can('edit-holidays') || $holiday->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $holiday->update($request->validated());

        UpdateHoliday::dispatch($request, $holiday);

        return back()->with('success', __('The holiday details are updated successfully.'));
    }

    public function destroy(Request $request, Holiday $holiday): RedirectResponse
    {
        if (!Auth::user()->can('delete-holidays') || $holiday->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyHoliday::dispatch($request, $holiday);
        $holiday->delete();

        return back()->with('success', __('The holiday has been deleted.'));
    }
}
