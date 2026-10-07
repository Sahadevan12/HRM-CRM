<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Events\CreateComplaint;
use Workdo\Hrm\Events\DestroyComplaint;
use Workdo\Hrm\Events\UpdateComplaint;
use Workdo\Hrm\Http\Requests\SaveComplaintRequest;
use Workdo\Hrm\Models\Complaint;
use Workdo\Hrm\Models\Employee;

class ComplaintController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'from_employee_id', 'against_employee_id', 'subject', 'complaint_date', 'status'];

    private const SEARCHABLE = ['subject', 'description', 'status', 'resolution'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-complaints')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Complaint::with(['fromEmployee:id,employee_code,user_id', 'fromEmployee.user:id,name', 'againstEmployee:id,employee_code,user_id', 'againstEmployee.user:id,name'])->where('created_by', creatorId());

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

        return Inertia::render('Hrm/Complaints/Index', [
            'complaints' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
            'employeeOptions' => Employee::with('user:id,name')->where('created_by', creatorId())->orderBy('employee_code')->get(['id', 'employee_code', 'user_id'])->map(fn ($e) => ['id' => $e->id, 'name' => $e->employee_code . ' · ' . $e->user->name]),
        ]);
    }

    public function store(SaveComplaintRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-complaints')) {
            return back()->with('error', __('Permission denied'));
        }

        $complaint = Complaint::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateComplaint::dispatch($request, $complaint);

        return back()->with('success', __('The complaint has been created successfully.'));
    }

    public function update(SaveComplaintRequest $request, Complaint $complaint): RedirectResponse
    {
        if (!Auth::user()->can('edit-complaints') || $complaint->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $complaint->update($request->validated());

        UpdateComplaint::dispatch($request, $complaint);

        return back()->with('success', __('The complaint details are updated successfully.'));
    }

    public function destroy(Request $request, Complaint $complaint): RedirectResponse
    {
        if (!Auth::user()->can('delete-complaints') || $complaint->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyComplaint::dispatch($request, $complaint);
        $complaint->delete();

        return back()->with('success', __('The complaint has been deleted.'));
    }
}
