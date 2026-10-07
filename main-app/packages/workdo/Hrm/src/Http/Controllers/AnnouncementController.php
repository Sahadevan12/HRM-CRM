<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Models\Acknowledgment;
use Workdo\Hrm\Models\Announcement;
use Workdo\Hrm\Models\AnnouncementCategory;
use Workdo\Hrm\Models\Department;
use Workdo\Hrm\Models\Employee;

class AnnouncementController extends Controller
{
    /** HR: every announcement with who has acknowledged it. */
    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-announcements')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $active = Employee::where('created_by', $tenant)->where('status', 'active');

        $rows = Announcement::with(['category:id,name', 'departments:id,name'])->where('created_by', $tenant)
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%' . $request->get('search') . '%'))
            ->latest('start_date')->latest('id')->paginate((int) $request->get('per_page', 10))->withQueryString();

        $rows->getCollection()->transform(function (Announcement $a) use ($active) {
            $ids = $a->departments->pluck('id');
            $audience = (clone $active)->when($ids->isNotEmpty(), fn ($q) => $q->whereIn('department_id', $ids))->count();

            return $a->setAttribute('department_ids', $ids)->setAttribute('audience', $audience)
                ->setAttribute('acknowledged', Acknowledgment::where('kind', 'announcement')->where('ref_id', $a->id)->count());
        });

        return Inertia::render('Hrm/Announcements/Index', [
            'announcements' => $rows,
            'categories' => AnnouncementCategory::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-announcements')) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $announcement = Announcement::create(collect($data)->except('department_ids')->all() + ['creator_id' => Auth::id(), 'created_by' => creatorId()]);
            $announcement->departments()->sync($data['department_ids'] ?? []);
        });

        return back()->with('success', __('The announcement has been published.'));
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        if (!Auth::user()->can('edit-announcements') || $announcement->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $this->validated($request);

        DB::transaction(function () use ($announcement, $data) {
            $announcement->update(collect($data)->except('department_ids')->all());
            $announcement->departments()->sync($data['department_ids'] ?? []);
        });

        return back()->with('success', __('The announcement has been updated.'));
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        if (!Auth::user()->can('delete-announcements') || $announcement->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        Acknowledgment::where('kind', 'announcement')->where('ref_id', $announcement->id)->delete();
        $announcement->delete();

        return back()->with('success', __('The announcement has been deleted.'));
    }

    /** Employee: the announcements that are running today and meant for them. */
    public function my(): Response|RedirectResponse
    {
        if (!Auth::user()->can('view-announcements')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $employee = Employee::where('user_id', Auth::id())->where('created_by', creatorId())->first();

        $items = $employee
            ? Announcement::with('category:id,name')->where('created_by', creatorId())->current()->visibleTo($employee)->latest('start_date')->latest('id')->get()
            : collect();

        $done = $employee ? Acknowledgment::where('kind', 'announcement')->where('employee_id', $employee->id)->pluck('ref_id') : collect();
        $items->each(fn ($a) => $a->setAttribute('acknowledged', $done->contains($a->id)));

        return Inertia::render('Hrm/Announcements/My', ['announcements' => $items, 'hasProfile' => (bool) $employee]);
    }

    public function acknowledge(Announcement $announcement): RedirectResponse
    {
        $employee = Employee::where('user_id', Auth::id())->where('created_by', creatorId())->first();
        $visible = $employee && Auth::user()->can('view-announcements') && $announcement->created_by === creatorId()
            && Announcement::whereKey($announcement->id)->current()->visibleTo($employee)->exists();

        if (!$visible) {
            return back()->with('error', __('Permission denied'));
        }

        Acknowledgment::firstOrCreate(
            ['kind' => 'announcement', 'ref_id' => $announcement->id, 'employee_id' => $employee->id],
            ['acknowledged_at' => now(), 'created_by' => creatorId()],
        );

        return back()->with('success', __('Thank you, it is noted.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $tenant = creatorId();

        return $request->validate([
            'title' => 'required|string|max:255',
            'announcement_category_id' => ['nullable', Rule::exists('announcement_categories', 'id')->where('created_by', $tenant)],
            'body' => 'required|string|max:10000',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'requires_acknowledgment' => 'boolean',
            'department_ids' => 'array|max:200',
            'department_ids.*' => ['integer', 'distinct', Rule::exists('departments', 'id')->where('created_by', $tenant)],
        ]);
    }
}
