<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateAwardType;
use Workdo\Hrm\Events\DestroyAwardType;
use Workdo\Hrm\Events\UpdateAwardType;
use Workdo\Hrm\Http\Requests\SaveAwardTypeRequest;
use Workdo\Hrm\Models\AwardType;


class AwardTypeController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name'];

    private const SEARCHABLE = ['name', 'description'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-award-types')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = AwardType::with([])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/AwardTypes/Index', [
            'awardTypes' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),

        ]);
    }

    public function store(SaveAwardTypeRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-award-types')) {
            return back()->with('error', __('Permission denied'));
        }

        $awardType = AwardType::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateAwardType::dispatch($request, $awardType);

        return back()->with('success', __('The award type has been created successfully.'));
    }

    public function update(SaveAwardTypeRequest $request, AwardType $awardType): RedirectResponse
    {
        if (!Auth::user()->can('edit-award-types') || $awardType->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $awardType->update($request->validated());

        UpdateAwardType::dispatch($request, $awardType);

        return back()->with('success', __('The award type details are updated successfully.'));
    }

    public function destroy(Request $request, AwardType $awardType): RedirectResponse
    {
        if (!Auth::user()->can('delete-award-types') || $awardType->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyAwardType::dispatch($request, $awardType);
        $awardType->delete();

        return back()->with('success', __('The award type has been deleted.'));
    }
}
