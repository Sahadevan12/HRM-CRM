<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\SalaryComponent;

/** Who gets which allowance / deduction, plus the basic salary and hourly rate, in one place. */
class SalarySetupController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-salary-setup')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();

        return Inertia::render('Hrm/SalarySetup/Index', [
            'employees' => Employee::with(['user:id,name', 'salaryComponents'])->where('created_by', $tenant)
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('employee_code', 'like', '%' . $request->get('search') . '%')
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', '%' . $request->get('search') . '%'))))
                ->orderBy('employee_code')->paginate((int) $request->get('per_page', 15))->withQueryString(),
            'components' => SalaryComponent::where('created_by', $tenant)->orderBy('type')->orderBy('name')->get(['id', 'name', 'type', 'calc', 'amount']),
            'filters' => $request->only('search'),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        if (!Auth::user()->can('manage-salary-setup') || $employee->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate([
            'basic_salary' => 'required|numeric|min:0|max:9999999999',
            'hourly_rate' => 'nullable|numeric|min:0|max:9999999',
            'components' => 'array|max:50',
            'components.*.salary_component_id' => ['required', 'distinct', Rule::exists('salary_components', 'id')->where('created_by', $tenant)],
            'components.*.value' => 'required|numeric|min:0|max:9999999999',
        ]);

        // a percentage cannot exceed 100
        $percent = SalaryComponent::where('created_by', $tenant)->where('calc', 'percent')->pluck('id')->all();
        foreach ($data['components'] ?? [] as $i => $row) {
            if (in_array((int) $row['salary_component_id'], $percent, true) && $row['value'] > 100) {
                return back()->withErrors(["components.$i.value" => __('A percentage cannot be more than 100.')]);
            }
        }

        DB::transaction(function () use ($employee, $data, $tenant) {
            $employee->update(['basic_salary' => $data['basic_salary'], 'hourly_rate' => $data['hourly_rate'] ?? 0]);
            $employee->salaryComponents()->delete();

            foreach ($data['components'] ?? [] as $row) {
                $employee->salaryComponents()->create(['salary_component_id' => $row['salary_component_id'], 'value' => $row['value'], 'created_by' => $tenant]);
            }
        });

        return back()->with('success', __('The salary details have been saved.'));
    }
}
