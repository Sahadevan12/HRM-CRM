<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Exceptions\HrmException;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\Resignation;
use Workdo\Hrm\Services\SelfResignation;

/** "My resignation": an employee hands in their own resignation and follows it. HR decides in Employee Lifecycle. */
class SelfResignationController extends Controller
{
    public function __construct(private SelfResignation $resignations)
    {
    }

    public function index(): Response|RedirectResponse
    {
        if (!Auth::user()->can('apply-resignation')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $employee = $this->employee();
        $history = $employee ? $this->resignations->history($employee) : [];

        return Inertia::render('Hrm/Resignation/My', [
            'hasProfile' => (bool) $employee,
            'history' => $history,
            'canApply' => $employee && $employee->isActive() && !collect($history)->contains('status', 'pending'),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('apply-resignation')) {
            return back()->with('error', __('Permission denied'));
        }
        if (!$employee = $this->employee()) {
            return back()->with('error', __('Your login has no employee profile. Ask HR to create one.'));
        }

        $data = $request->validate(['last_working_date' => 'required|date', 'reason' => 'nullable|string|max:2000']);

        try {
            $this->resignations->apply($employee, $data['last_working_date'], $data['reason'] ?? null);
        } catch (HrmException $e) {
            return back()->withErrors(['last_working_date' => $e->getMessage()]);
        }

        return back()->with('success', __('Your resignation has been submitted. HR will get back to you.'));
    }

    public function withdraw(Resignation $resignation): RedirectResponse
    {
        if (!Auth::user()->can('apply-resignation') || !($employee = $this->employee())) {
            return back()->with('error', __('Permission denied'));
        }

        try {
            $this->resignations->withdraw($resignation, $employee);
        } catch (HrmException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Your resignation has been withdrawn.'));
    }

    private function employee(): ?Employee
    {
        return Employee::where('user_id', Auth::id())->where('created_by', creatorId())->first();
    }
}
