<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateWarning;
use Workdo\Hrm\Events\DestroyWarning;
use Workdo\Hrm\Events\UpdateWarning;
use Workdo\Hrm\Http\Requests\SaveWarningRequest;
use Workdo\Hrm\Models\Warning;
use Workdo\Hrm\Models\Employee;

class WarningController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'employee_id', 'subject', 'severity', 'warning_date'];

    private const SEARCHABLE = ['subject', 'severity', 'description'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-warnings')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Warning::with(['employee:id,employee_code,user_id', 'employee.user:id,name'])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/Warnings/Index', [
            'warnings' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
            'employeeOptions' => Employee::with('user:id,name')->where('created_by', creatorId())->orderBy('employee_code')->get(['id', 'employee_code', 'user_id'])->map(fn ($e) => ['id' => $e->id, 'name' => $e->employee_code . ' · ' . $e->user->name]),
        ]);
    }

    public function store(SaveWarningRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-warnings')) {
            return back()->with('error', __('Permission denied'));
        }

        $warning = Warning::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateWarning::dispatch($request, $warning);

        return back()->with('success', __('The warning has been created successfully.'));
    }

    public function update(SaveWarningRequest $request, Warning $warning): RedirectResponse
    {
        if (!Auth::user()->can('edit-warnings') || $warning->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $warning->update($request->validated());

        UpdateWarning::dispatch($request, $warning);

        return back()->with('success', __('The warning details are updated successfully.'));
    }

    public function destroy(Request $request, Warning $warning): RedirectResponse
    {
        if (!Auth::user()->can('delete-warnings') || $warning->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyWarning::dispatch($request, $warning);
        $warning->delete();

        return back()->with('success', __('The warning has been deleted.'));
    }
}
