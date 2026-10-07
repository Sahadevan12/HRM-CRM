<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateEmployeeDocumentType;
use Workdo\Hrm\Events\DestroyEmployeeDocumentType;
use Workdo\Hrm\Events\UpdateEmployeeDocumentType;
use Workdo\Hrm\Http\Requests\SaveEmployeeDocumentTypeRequest;
use Workdo\Hrm\Models\EmployeeDocumentType;


class EmployeeDocumentTypeController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name'];

    private const SEARCHABLE = ['name'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-employee-document-types')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = EmployeeDocumentType::with([])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/EmployeeDocumentTypes/Index', [
            'employeeDocumentTypes' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),

        ]);
    }

    public function store(SaveEmployeeDocumentTypeRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-employee-document-types')) {
            return back()->with('error', __('Permission denied'));
        }

        $employeeDocumentType = EmployeeDocumentType::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateEmployeeDocumentType::dispatch($request, $employeeDocumentType);

        return back()->with('success', __('The employee document type has been created successfully.'));
    }

    public function update(SaveEmployeeDocumentTypeRequest $request, EmployeeDocumentType $employeeDocumentType): RedirectResponse
    {
        if (!Auth::user()->can('edit-employee-document-types') || $employeeDocumentType->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $employeeDocumentType->update($request->validated());

        UpdateEmployeeDocumentType::dispatch($request, $employeeDocumentType);

        return back()->with('success', __('The employee document type details are updated successfully.'));
    }

    public function destroy(Request $request, EmployeeDocumentType $employeeDocumentType): RedirectResponse
    {
        if (!Auth::user()->can('delete-employee-document-types') || $employeeDocumentType->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyEmployeeDocumentType::dispatch($request, $employeeDocumentType);
        $employeeDocumentType->delete();

        return back()->with('success', __('The employee document type has been deleted.'));
    }
}
