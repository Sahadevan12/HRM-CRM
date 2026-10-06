<?php

namespace Workdo\Account\Services;

use Illuminate\Support\Facades\DB;
use Workdo\Account\Models\ChartOfAccount;

/** Financial statements, always summed from the journal lines of one company. */
class ReportService
{
    public function __construct(private AccountService $accounts)
    {
    }

    /**
     * Trial balance as of a date: every account with a balance, shown on its natural side. Debits always equal credits.
     *
     * @return array{rows: array<int, array<string, mixed>>, total_debit: float, total_credit: float}
     */
    public function trialBalance(int $tenantId, string $asOf): array
    {
        $rows = [];
        foreach ($this->balances($tenantId, null, $asOf) as $row) {
            $net = round($row['debit'] - $row['credit'], 2);
            if ($net == 0.0) {
                continue;
            }
            $rows[] = $row + ['balance_debit' => $net > 0 ? $net : 0.0, 'balance_credit' => $net < 0 ? -$net : 0.0];
        }

        return [
            'rows' => $rows,
            'total_debit' => round(array_sum(array_column($rows, 'balance_debit')), 2),
            'total_credit' => round(array_sum(array_column($rows, 'balance_credit')), 2),
        ];
    }

    /**
     * Profit & loss for a period: revenue - expenses.
     *
     * @return array{revenue: array, expenses: array, total_revenue: float, total_expenses: float, net_profit: float}
     */
    public function profitLoss(int $tenantId, string $from, string $to): array
    {
        $revenue = [];
        $expenses = [];
        foreach ($this->balances($tenantId, $from, $to) as $row) {
            if ($row['type'] === 'revenue') {
                $revenue[] = $row + ['amount' => round($row['credit'] - $row['debit'], 2)];
            } elseif ($row['type'] === 'expense') {
                $expenses[] = $row + ['amount' => round($row['debit'] - $row['credit'], 2)];
            }
        }

        $totalRevenue = round(array_sum(array_column($revenue, 'amount')), 2);
        $totalExpenses = round(array_sum(array_column($expenses, 'amount')), 2);

        return [
            'revenue' => $revenue, 'expenses' => $expenses,
            'total_revenue' => $totalRevenue, 'total_expenses' => $totalExpenses, 'net_profit' => round($totalRevenue - $totalExpenses, 2),
        ];
    }

    /**
     * Balance sheet as of a date. Profit not yet closed into equity is shown as "current earnings",
     * so assets = liabilities + equity always holds.
     */
    public function balanceSheet(int $tenantId, string $asOf): array
    {
        $assets = $liabilities = $equity = [];
        $earnings = 0.0;

        foreach ($this->balances($tenantId, null, $asOf) as $row) {
            $net = round($row['debit'] - $row['credit'], 2);

            match ($row['type']) {
                'asset' => $assets[] = $row + ['amount' => $net],
                'liability' => $liabilities[] = $row + ['amount' => -$net],
                'equity' => $equity[] = $row + ['amount' => -$net],
                default => $earnings += -$net, // revenue (credit) raises, expense (debit) lowers
            };
        }

        $earnings = round($earnings, 2);
        $totalAssets = round(array_sum(array_column($assets, 'amount')), 2);
        $totalLiabilities = round(array_sum(array_column($liabilities, 'amount')), 2);
        $totalEquity = round(array_sum(array_column($equity, 'amount')) + $earnings, 2);

        return [
            'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity, 'current_earnings' => $earnings,
            'total_assets' => $totalAssets, 'total_liabilities' => $totalLiabilities, 'total_equity' => $totalEquity,
            'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.005,
        ];
    }

    /**
     * General ledger of one account with a running balance (on the account's natural side).
     *
     * @return array{account: ChartOfAccount, opening: float, lines: array<int, array<string, mixed>>, closing: float}
     */
    public function ledger(int $tenantId, ChartOfAccount $account, string $from, string $to): array
    {
        $sign = $account->isDebitNormal() ? 1 : -1;

        $before = DB::table('journal_entry_items as i')->join('journal_entries as e', 'e.id', '=', 'i.journal_entry_id')
            ->where('e.created_by', $tenantId)->where('i.account_id', $account->id)->where('e.journal_date', '<', $from)
            ->selectRaw('COALESCE(SUM(i.debit),0) as debit, COALESCE(SUM(i.credit),0) as credit')->first();
        $opening = round(($before->debit - $before->credit) * $sign, 2);

        $running = $opening;
        $lines = [];
        $rows = DB::table('journal_entry_items as i')->join('journal_entries as e', 'e.id', '=', 'i.journal_entry_id')
            ->where('e.created_by', $tenantId)->where('i.account_id', $account->id)->whereBetween('e.journal_date', [$from, $to])
            ->orderBy('e.journal_date')->orderBy('e.id')->orderBy('i.id')
            ->get(['e.id as entry_id', 'e.number', 'e.journal_date', 'e.description as entry_description', 'i.description', 'i.debit', 'i.credit']);

        foreach ($rows as $row) {
            $running = round($running + ($row->debit - $row->credit) * $sign, 2);
            $lines[] = [
                'entry_id' => $row->entry_id, 'number' => $row->number, 'date' => $row->journal_date,
                'description' => $row->description ?: $row->entry_description,
                'debit' => (float) $row->debit, 'credit' => (float) $row->credit, 'balance' => $running,
            ];
        }

        return ['account' => $account, 'opening' => $opening, 'lines' => $lines, 'closing' => $running];
    }

    /**
     * Debit/credit sums per account over a period (from = null means "since the beginning").
     *
     * @return array<int, array{id: int, code: string, name: string, type: string, debit: float, credit: float}>
     */
    public function balances(int $tenantId, ?string $from, string $to): array
    {
        $this->accounts->ensureDefaults($tenantId);

        $sums = DB::table('journal_entry_items as i')->join('journal_entries as e', 'e.id', '=', 'i.journal_entry_id')
            ->where('e.created_by', $tenantId)->where('e.journal_date', '<=', $to)
            ->when($from, fn ($q) => $q->where('e.journal_date', '>=', $from))
            ->groupBy('i.account_id')
            ->selectRaw('i.account_id, SUM(i.debit) as debit, SUM(i.credit) as credit')
            ->get()->keyBy('account_id');

        return ChartOfAccount::where('created_by', $tenantId)->orderBy('code')->get()
            ->filter(fn ($a) => isset($sums[$a->id]))
            ->map(fn ($a) => [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
                'debit' => round((float) $sums[$a->id]->debit, 2), 'credit' => round((float) $sums[$a->id]->credit, 2),
            ])->values()->all();
    }
}
