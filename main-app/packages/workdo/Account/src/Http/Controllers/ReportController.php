<?php

namespace Workdo\Account\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Account\Models\ChartOfAccount;
use Workdo\Account\Services\AccountService;
use Workdo\Account\Services\ReportService;

class ReportController extends Controller
{
    private const REPORTS = ['trial-balance', 'profit-loss', 'balance-sheet', 'ledger'];

    public function __construct(private ReportService $reports, private AccountService $accounts)
    {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-account-reports')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $this->accounts->ensureDefaults($tenant);

        $report = in_array($request->get('report'), self::REPORTS, true) ? $request->get('report') : 'trial-balance';
        $from = $this->date($request->get('from'), now()->startOfYear()->toDateString());
        $to = $this->date($request->get('to'), now()->toDateString());
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $accounts = ChartOfAccount::where('created_by', $tenant)->orderBy('code')->get(['id', 'code', 'name', 'type']);

        $data = match ($report) {
            'trial-balance' => $this->reports->trialBalance($tenant, $to),
            'profit-loss' => $this->reports->profitLoss($tenant, $from, $to),
            'balance-sheet' => $this->reports->balanceSheet($tenant, $to),
            'ledger' => $this->ledger($tenant, $request, $from, $to, $accounts),
        };

        return Inertia::render('Account/Reports/Index', [
            'report' => $report,
            'data' => $data,
            'accounts' => $accounts,
            'params' => ['from' => $from, 'to' => $to, 'account' => $request->get('account')],
        ]);
    }

    /** Ledger of the chosen account (defaults to Accounts Receivable). */
    private function ledger(int $tenant, Request $request, string $from, string $to, $accounts): array
    {
        $account = $accounts->firstWhere('id', (int) $request->get('account')) ?? $accounts->firstWhere('code', AccountService::RECEIVABLE) ?? $accounts->first();

        return $this->reports->ledger($tenant, ChartOfAccount::findOrFail($account->id), $from, $to);
    }

    /** A valid Y-m-d date or the fallback. */
    private function date(?string $value, string $fallback): string
    {
        return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : $fallback;
    }
}
