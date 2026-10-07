<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateSalaryComponent;
use Workdo\Hrm\Events\DestroySalaryComponent;
use Workdo\Hrm\Events\UpdateSalaryComponent;
use Workdo\Hrm\Http\Requests\SaveSalaryComponentRequest;
use Workdo\Hrm\Models\SalaryComponent;


class SalaryComponentController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'type', 'calc', 'amount'];

    private const SEARCHABLE = ['name', 'type', 'calc', 'description'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-salary-components')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = SalaryComponent::with([])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/SalaryComponents/Index', [
            'salaryComponents' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),

        ]);
    }

    public function store(SaveSalaryComponentRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-salary-components')) {
            return back()->with('error', __('Permission denied'));
        }

        $salaryComponent = SalaryComponent::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateSalaryComponent::dispatch($request, $salaryComponent);

        return back()->with('success', __('The salary component has been created successfully.'));
    }

    public function update(SaveSalaryComponentRequest $request, SalaryComponent $salaryComponent): RedirectResponse
    {
        if (!Auth::user()->can('edit-salary-components') || $salaryComponent->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $salaryComponent->update($request->validated());

        UpdateSalaryComponent::dispatch($request, $salaryComponent);

        return back()->with('success', __('The salary component details are updated successfully.'));
    }

    public function destroy(Request $request, SalaryComponent $salaryComponent): RedirectResponse
    {
        if (!Auth::user()->can('delete-salary-components') || $salaryComponent->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroySalaryComponent::dispatch($request, $salaryComponent);
        $salaryComponent->delete();

        return back()->with('success', __('The salary component has been deleted.'));
    }
}
