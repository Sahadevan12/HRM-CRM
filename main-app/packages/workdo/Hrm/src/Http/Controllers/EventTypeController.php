<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateEventType;
use Workdo\Hrm\Events\DestroyEventType;
use Workdo\Hrm\Events\UpdateEventType;
use Workdo\Hrm\Http\Requests\SaveEventTypeRequest;
use Workdo\Hrm\Models\EventType;


class EventTypeController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'color'];

    private const SEARCHABLE = ['name', 'color'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-event-types')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = EventType::with([])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/EventTypes/Index', [
            'eventTypes' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),

        ]);
    }

    public function store(SaveEventTypeRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-event-types')) {
            return back()->with('error', __('Permission denied'));
        }

        $eventType = EventType::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateEventType::dispatch($request, $eventType);

        return back()->with('success', __('The event type has been created successfully.'));
    }

    public function update(SaveEventTypeRequest $request, EventType $eventType): RedirectResponse
    {
        if (!Auth::user()->can('edit-event-types') || $eventType->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $eventType->update($request->validated());

        UpdateEventType::dispatch($request, $eventType);

        return back()->with('success', __('The event type details are updated successfully.'));
    }

    public function destroy(Request $request, EventType $eventType): RedirectResponse
    {
        if (!Auth::user()->can('delete-event-types') || $eventType->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyEventType::dispatch($request, $eventType);
        $eventType->delete();

        return back()->with('success', __('The event type has been deleted.'));
    }
}
