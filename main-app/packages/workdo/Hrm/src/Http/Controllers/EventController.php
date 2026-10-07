<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Models\Department;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\Event;
use Workdo\Hrm\Models\EventType;

/** Company events shown on a month calendar. HR sees and edits all of them, employees the ones meant for their department. */
class EventController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();
        $manage = $user->can('manage-events');

        if (!$manage && !$user->can('view-events')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->get('month')) ? $request->get('month') : now()->format('Y-m');
        $first = Carbon::parse("{$month}-01");
        $from = $first->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $to = $first->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $employee = Employee::where('user_id', $user->id)->where('created_by', $tenant)->first();

        $events = Event::with(['type:id,name,color', 'departments:id,name'])->where('created_by', $tenant)->between($from, $to)
            ->when(!$manage, fn ($q) => $employee ? $q->visibleTo($employee) : $q->whereDoesntHave('departments'))
            ->orderBy('start_date')->orderBy('start_time')->get()
            ->each(fn (Event $e) => $e->setAttribute('department_ids', $e->departments->pluck('id')));

        return Inertia::render('Hrm/Events/Index', [
            'events' => $events,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'types' => EventType::where('created_by', $tenant)->orderBy('name')->get(['id', 'name', 'color']),
            'departments' => Department::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'can' => ['create' => $user->can('create-events'), 'edit' => $user->can('edit-events'), 'delete' => $user->can('delete-events')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-events')) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $event = Event::create(collect($data)->except('department_ids')->all() + ['creator_id' => Auth::id(), 'created_by' => creatorId()]);
            $event->departments()->sync($data['department_ids'] ?? []);
        });

        return back()->with('success', __('The event has been created.'));
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        if (!Auth::user()->can('edit-events') || $event->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($event, $data) {
            $event->update(collect($data)->except('department_ids')->all());
            $event->departments()->sync($data['department_ids'] ?? []);
        });

        return back()->with('success', __('The event has been updated.'));
    }

    public function destroy(Event $event): RedirectResponse
    {
        if (!Auth::user()->can('delete-events') || $event->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $event->delete();

        return back()->with('success', __('The event has been deleted.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $tenant = creatorId();

        return $request->validate([
            'title' => 'required|string|max:255',
            'event_type_id' => ['nullable', Rule::exists('event_types', 'id')->where('created_by', $tenant)],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'start_time' => 'nullable|date_format:H:i',
            'location' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'department_ids' => 'array|max:200',
            'department_ids.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('created_by', $tenant)],
        ]);
    }
}
