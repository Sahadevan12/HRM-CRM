<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateAward;
use Workdo\Hrm\Events\DestroyAward;
use Workdo\Hrm\Events\UpdateAward;
use Workdo\Hrm\Http\Requests\SaveAwardRequest;
use Workdo\Hrm\Models\Award;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\AwardType;

class AwardController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'employee_id', 'award_type_id', 'award_date', 'gift'];

    private const SEARCHABLE = ['gift', 'description'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-awards')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Award::with(['employee:id,employee_code,user_id', 'employee.user:id,name', 'awardType:id,name'])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/Awards/Index', [
            'awards' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
            'employeeOptions' => Employee::with('user:id,name')->where('created_by', creatorId())->orderBy('employee_code')->get(['id', 'employee_code', 'user_id'])->map(fn ($e) => ['id' => $e->id, 'name' => $e->employee_code . ' · ' . $e->user->name]),
            'awardTypeOptions' => AwardType::where('created_by', creatorId())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(SaveAwardRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-awards')) {
            return back()->with('error', __('Permission denied'));
        }

        $award = Award::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateAward::dispatch($request, $award);

        return back()->with('success', __('The award has been created successfully.'));
    }

    public function update(SaveAwardRequest $request, Award $award): RedirectResponse
    {
        if (!Auth::user()->can('edit-awards') || $award->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $award->update($request->validated());

        UpdateAward::dispatch($request, $award);

        return back()->with('success', __('The award details are updated successfully.'));
    }

    public function destroy(Request $request, Award $award): RedirectResponse
    {
        if (!Auth::user()->can('delete-awards') || $award->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyAward::dispatch($request, $award);
        $award->delete();

        return back()->with('success', __('The award has been deleted.'));
    }
}
