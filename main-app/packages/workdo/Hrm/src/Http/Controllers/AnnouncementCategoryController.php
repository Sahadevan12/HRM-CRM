<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateAnnouncementCategory;
use Workdo\Hrm\Events\DestroyAnnouncementCategory;
use Workdo\Hrm\Events\UpdateAnnouncementCategory;
use Workdo\Hrm\Http\Requests\SaveAnnouncementCategoryRequest;
use Workdo\Hrm\Models\AnnouncementCategory;


class AnnouncementCategoryController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name'];

    private const SEARCHABLE = ['name'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-announcement-categories')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = AnnouncementCategory::with([])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/AnnouncementCategories/Index', [
            'announcementCategories' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),

        ]);
    }

    public function store(SaveAnnouncementCategoryRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-announcement-categories')) {
            return back()->with('error', __('Permission denied'));
        }

        $announcementCategory = AnnouncementCategory::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateAnnouncementCategory::dispatch($request, $announcementCategory);

        return back()->with('success', __('The announcement category has been created successfully.'));
    }

    public function update(SaveAnnouncementCategoryRequest $request, AnnouncementCategory $announcementCategory): RedirectResponse
    {
        if (!Auth::user()->can('edit-announcement-categories') || $announcementCategory->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $announcementCategory->update($request->validated());

        UpdateAnnouncementCategory::dispatch($request, $announcementCategory);

        return back()->with('success', __('The announcement category details are updated successfully.'));
    }

    public function destroy(Request $request, AnnouncementCategory $announcementCategory): RedirectResponse
    {
        if (!Auth::user()->can('delete-announcement-categories') || $announcementCategory->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyAnnouncementCategory::dispatch($request, $announcementCategory);
        $announcementCategory->delete();

        return back()->with('success', __('The announcement category has been deleted.'));
    }
}
