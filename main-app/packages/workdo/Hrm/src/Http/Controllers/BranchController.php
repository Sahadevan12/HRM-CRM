<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateBranch;
use Workdo\Hrm\Events\DestroyBranch;
use Workdo\Hrm\Events\UpdateBranch;
use Workdo\Hrm\Http\Requests\SaveBranchRequest;
use Workdo\Hrm\Models\Branch;


class BranchController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'city', 'phone'];

    private const SEARCHABLE = ['name', 'address', 'city', 'phone'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-branches')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Branch::with([])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/Branches/Index', [
            'branches' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),

        ]);
    }

    public function store(SaveBranchRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-branches')) {
            return back()->with('error', __('Permission denied'));
        }

        $branch = Branch::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateBranch::dispatch($request, $branch);

        return back()->with('success', __('The branch has been created successfully.'));
    }

    public function update(SaveBranchRequest $request, Branch $branch): RedirectResponse
    {
        if (!Auth::user()->can('edit-branches') || $branch->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $branch->update($request->validated());

        UpdateBranch::dispatch($request, $branch);

        return back()->with('success', __('The branch details are updated successfully.'));
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        if (!Auth::user()->can('delete-branches') || $branch->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyBranch::dispatch($request, $branch);
        $branch->delete();

        return back()->with('success', __('The branch has been deleted.'));
    }
}
