<?php

namespace Modules\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Hrm\Exceptions\HrmException;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Models\Payroll;
use Modules\Hrm\Models\Payslip;
use Modules\Hrm\Services\PayrollService;
use Modules\Hrm\Services\PayslipDocument;

class PayrollController extends Controller
{
    public function __construct(private PayrollService $payrolls)
    {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-payrolls')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        return Inertia::render('Hrm/Payrolls/Index', [
            'payrolls' => Payroll::where('created_by', creatorId())->withCount('payslips')->orderByDesc('month')->paginate((int) $request->get('per_page', 12))->withQueryString(),
            'suggestedMonth' => now()->format('Y-m'),
            'accountingActive' => Module_is_active('Account', creatorId()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-payrolls')) {
            return back()->with('error', __('Permission denied'));
        }

        $month = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['month'];

        try {
            $payroll = $this->payrolls->generate(creatorId(), $month, Auth::id());
        } catch (HrmException $e) {
            return back()->withErrors(['month' => $e->getMessage()]);
        }

        return redirect()->route('hrm.payrolls.show', $payroll)->with('success', __('The payroll has been generated.'));
    }

    public function show(Payroll $payroll): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-payrolls') || $payroll->created_by !== creatorId()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $user = Auth::user();

        return Inertia::render('Hrm/Payrolls/Show', [
            'payroll' => $payroll->load('payslips.employee.user:id,name', 'payslips.employee:id,employee_code,user_id'),
            'can' => [
                'create' => $user->can('create-payrolls'),
                'approve' => $user->can('approve-payrolls'),
                'pay' => $user->can('pay-payrolls'),
                'delete' => $user->can('delete-payrolls'),
            ],
            'accountingActive' => Module_is_active('Account', creatorId()),
        ]);
    }

    public function approve(Payroll $payroll): RedirectResponse
    {
        return $this->act($payroll, 'approve-payrolls', fn () => $this->payrolls->approve($payroll, Auth::id()), __('The payroll has been approved.'));
    }

    public function reopen(Payroll $payroll): RedirectResponse
    {
        return $this->act($payroll, 'approve-payrolls', fn () => $this->payrolls->reopen($payroll), __('The payroll is a draft again.'));
    }

    public function pay(Request $request, Payroll $payroll): RedirectResponse
    {
        $data = $request->validate(['paid_on' => 'required|date', 'method' => 'required|in:cash,bank']);

        return $this->act($payroll, 'pay-payrolls', fn () => $this->payrolls->pay($payroll, $data['paid_on'], $data['method'], Auth::id()), __('The payroll has been paid.'));
    }

    public function destroy(Payroll $payroll): RedirectResponse
    {
        if (!Auth::user()->can('delete-payrolls') || $payroll->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        try {
            $this->payrolls->delete($payroll);
        } catch (HrmException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('hrm.payrolls.index')->with('success', __('The payroll has been deleted.'));
    }

    // ───────────── payslips ─────────────

    /** An employee's own payslips (only of payrolls that are approved or paid). */
    public function my(): Response|RedirectResponse
    {
        if (!Auth::user()->can('view-payslips')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $employee = Employee::where('user_id', Auth::id())->where('created_by', creatorId())->first();

        $slips = $employee
            ? Payslip::with('payroll:id,month,status,paid_on')->where('employee_id', $employee->id)
                ->whereHas('payroll', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->latest('id')->get()
            : [];

        return Inertia::render('Hrm/Payslips/My', ['payslips' => $slips, 'hasProfile' => (bool) $employee]);
    }

    /** HR reads every payslip of the company; an employee only their own, and only once the payroll is approved / paid. */
    public static function mayRead(Payslip $payslip, \App\Models\User $user): bool
    {
        $payslip->loadMissing('payroll', 'employee');

        $isHr = $user->can('manage-payrolls');
        $isOwner = $payslip->employee->user_id === $user->id && $user->can('view-payslips') && in_array($payslip->payroll->status, ['approved', 'paid'], true);

        return $payslip->payroll->created_by === creatorId() && ($isHr || $isOwner);
    }

    public function pdf(Payslip $payslip, PayslipDocument $documents)
    {
        if (!self::mayRead($payslip, Auth::user())) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        return $documents->render($payslip)->download($documents->filename($payslip));
    }

    public function payslip(Payslip $payslip): Response|RedirectResponse
    {
        if (!self::mayRead($payslip, Auth::user())) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $payslip->load('payroll', 'employee.user:id,name', 'employee.designation:id,name', 'employee.department:id,name', 'lines');

        return Inertia::render('Hrm/Payslips/Show', ['payslip' => $payslip, 'company' => company_setting('company_name') ?: Auth::user()->name]);
    }

    private function act(Payroll $payroll, string $permission, callable $action, string $success): RedirectResponse
    {
        if (!Auth::user()->can($permission) || $payroll->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        try {
            $action();
        } catch (HrmException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
