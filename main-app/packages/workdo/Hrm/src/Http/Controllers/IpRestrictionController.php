<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateIpRestriction;
use Workdo\Hrm\Events\DestroyIpRestriction;
use Workdo\Hrm\Events\UpdateIpRestriction;
use Workdo\Hrm\Http\Requests\SaveIpRestrictionRequest;
use Workdo\Hrm\Models\IpRestriction;


class IpRestrictionController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'ip_address', 'description'];

    private const SEARCHABLE = ['ip_address', 'description'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-ip-restrictions')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = IpRestriction::with([])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/IpRestrictions/Index', [
            'ipRestrictions' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),

        ]);
    }

    public function store(SaveIpRestrictionRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-ip-restrictions')) {
            return back()->with('error', __('Permission denied'));
        }

        $ipRestriction = IpRestriction::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateIpRestriction::dispatch($request, $ipRestriction);

        return back()->with('success', __('The ip restriction has been created successfully.'));
    }

    public function update(SaveIpRestrictionRequest $request, IpRestriction $ipRestriction): RedirectResponse
    {
        if (!Auth::user()->can('edit-ip-restrictions') || $ipRestriction->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $ipRestriction->update($request->validated());

        UpdateIpRestriction::dispatch($request, $ipRestriction);

        return back()->with('success', __('The ip restriction details are updated successfully.'));
    }

    public function destroy(Request $request, IpRestriction $ipRestriction): RedirectResponse
    {
        if (!Auth::user()->can('delete-ip-restrictions') || $ipRestriction->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyIpRestriction::dispatch($request, $ipRestriction);
        $ipRestriction->delete();

        return back()->with('success', __('The ip restriction has been deleted.'));
    }
}
