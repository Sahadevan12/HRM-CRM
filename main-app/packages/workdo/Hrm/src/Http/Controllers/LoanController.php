<?php

namespace Workdo\Hrm\Http\Controllers;

use App\Events\LoanDisbursed;
use App\Events\LoanDisbursementCancelled;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\Loan;

/** Staff loans / advances, recovered through the payslips (an instalment per month until repaid). */
class LoanController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-loans')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();

        return Inertia::render('Hrm/Loans/Index', [
            'loans' => Loan::with('employee:id,employee_code,user_id', 'employee.user:id,name')->where('created_by', $tenant)
                ->when(in_array($request->get('status'), Loan::STATUSES, true), fn ($q) => $q->where('status', $request->get('status')))
                ->latest('id')->paginate((int) $request->get('per_page', 15))->withQueryString(),
            'employees' => Employee::with('user:id,name')->where('created_by', $tenant)->where('status', 'active')->orderBy('employee_code')->get(['id', 'employee_code', 'user_id'])
                ->map(fn ($e) => ['id' => $e->id, 'code' => $e->employee_code, 'name' => $e->user->name]),
            'accountingActive' => Module_is_active('Account', $tenant),
            'filters' => $request->only('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-loans')) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('created_by', $tenant)->where('status', 'active')],
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01|max:9999999999',
            'installment' => 'required|numeric|min:0.01|lte:amount',
            'start_month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'notes' => 'nullable|string|max:2000',
            // optional: put the money that left the company in its books (needs the Accounting module)
            'paid_from' => ['nullable', 'in:cash,bank', 'required_with:paid_on'],
            'paid_on' => 'nullable|date|required_with:paid_from',
        ]);

        DB::transaction(function () use ($data, $tenant) {
            $loan = Loan::create(collect($data)->except(['paid_from', 'paid_on', 'start_month'])->all() + [
                'start_month' => $data['start_month'] . '-01', 'status' => 'active', 'repaid' => 0, 'creator_id' => Auth::id(), 'created_by' => $tenant,
                'disbursed_from' => $data['paid_from'] ?? null, 'disbursed_on' => $data['paid_on'] ?? null,
            ]);

            if ($loan->disbursed_from) {
                LoanDisbursed::dispatch($tenant, $loan->id, $loan->title, $loan->employee->user->name, (float) $loan->amount, $loan->disbursed_on->toDateString(), $loan->disbursed_from, Auth::id());
            }
        });

        return back()->with('success', __('The loan has been created.'));
    }

    /** Only an unrepaid loan can be cancelled (the instalments already taken stay on the paid payslips). */
    public function cancel(Loan $loan): RedirectResponse
    {
        if (!Auth::user()->can('edit-loans') || $loan->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }
        if ($loan->status !== 'active') {
            return back()->with('error', __('Only an active loan can be cancelled.'));
        }

        $loan->update(['status' => 'cancelled']);

        return back()->with('success', __('The loan has been cancelled.'));
    }

    public function destroy(Loan $loan): RedirectResponse
    {
        if (!Auth::user()->can('delete-loans') || $loan->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }
        if ($loan->repaid > 0) {
            return back()->with('error', __('A loan with repayments cannot be deleted. Cancel it instead.'));
        }

        DB::transaction(function () use ($loan) {
            // the entry booked for the disbursement goes with it
            LoanDisbursementCancelled::dispatch($loan->created_by, $loan->id);
            $loan->delete();
        });

        return back()->with('success', __('The loan has been deleted.'));
    }
}
