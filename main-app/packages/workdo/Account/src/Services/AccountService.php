<?php

namespace Workdo\Account\Services;

use Workdo\Account\Models\ChartOfAccount;

/** Default chart of accounts per company + lookup by code. */
class AccountService
{
    // Well-known account codes used by the automatic postings
    public const CASH = '1000';
    public const BANK = '1010';
    public const RECEIVABLE = '1100';
    public const INVENTORY = '1200';
    public const TAX_RECEIVABLE = '1300';
    public const PAYABLE = '2000';
    public const TAX_PAYABLE = '2210';
    public const EQUITY = '3000';
    public const SALES = '4100';
    public const SALES_RETURNS = '4200';
    public const COGS = '5000';
    public const SERVICE_EXPENSE = '5200';

    /** @var array<int, array{0: string, 1: string, 2: string, 3?: bool}> code, name, type, is_bank */
    public const DEFAULTS = [
        [self::CASH, 'Cash', 'asset', true],
        [self::BANK, 'Bank Account', 'asset', true],
        [self::RECEIVABLE, 'Accounts Receivable', 'asset'],
        [self::INVENTORY, 'Inventory', 'asset'],
        [self::TAX_RECEIVABLE, 'Tax Receivable (Input Tax)', 'asset'],
        [self::PAYABLE, 'Accounts Payable', 'liability'],
        [self::TAX_PAYABLE, 'Tax Payable (Output Tax)', 'liability'],
        [self::EQUITY, "Owner's Equity", 'equity'],
        [self::SALES, 'Sales Revenue', 'revenue'],
        [self::SALES_RETURNS, 'Sales Returns & Allowances', 'revenue'],
        [self::COGS, 'Cost of Goods Sold', 'expense'],
        [self::SERVICE_EXPENSE, 'Services & Other Purchases', 'expense'],
    ];

    /** @var array<int, array<string, ChartOfAccount>> per-request cache: tenant => code => account */
    private array $cache = [];

    /** Create the default accounts of a company (idempotent; existing accounts are left untouched). */
    public function ensureDefaults(int $tenantId): void
    {
        if (isset($this->cache[$tenantId])) {
            return;
        }

        foreach (self::DEFAULTS as $row) {
            [$code, $name, $type] = $row;
            $isBank = $row[3] ?? false;

            $account = ChartOfAccount::firstOrNew(['created_by' => $tenantId, 'code' => $code]);

            if (!$account->exists) {
                $account->forceFill(['name' => $name, 'type' => $type, 'is_bank' => $isBank, 'is_active' => true, 'is_system' => true, 'creator_id' => $tenantId])->save();
            }
        }

        $this->cache[$tenantId] = ChartOfAccount::where('created_by', $tenantId)->get()->keyBy('code')->all();
    }

    public function forgetCache(): void
    {
        $this->cache = [];
    }

    public function account(int $tenantId, string $code): ChartOfAccount
    {
        $this->ensureDefaults($tenantId);

        return $this->cache[$tenantId][$code]
            ?? ChartOfAccount::where('created_by', $tenantId)->where('code', $code)->firstOrFail();
    }
}
