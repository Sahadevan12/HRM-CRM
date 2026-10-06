<?php

namespace Workdo\Account\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Account\Events\CreateChartOfAccount;
use Workdo\Account\Events\DestroyChartOfAccount;
use Workdo\Account\Events\UpdateChartOfAccount;
use Workdo\Account\Http\Requests\SaveChartOfAccountRequest;
use Workdo\Account\Models\ChartOfAccount;
use Workdo\Account\Models\Payment;
use Workdo\Account\Services\AccountService;
use Workdo\Account\Services\ReportService;

class ChartOfAccountController extends Controller
{
    public function __construct(private AccountService $accounts, private ReportService $reports)
    {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-chart-of-accounts')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $this->accounts->ensureDefaults($tenant); // a company gets its default chart the first time it opens Accounting

        $accounts = ChartOfAccount::where('created_by', $tenant)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($s) => $s->where('code', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->when(in_array($request->get('type'), ChartOfAccount::TYPES, true), fn ($q) => $q->where('type', $request->get('type')))
            ->orderBy('code')
            ->paginate((int) $request->get('per_page', 25))
            ->withQueryString();

        // current balance of every account on its natural side
        $balances = collect($this->reports->balances($tenant, null, now()->toDateString()))->mapWithKeys(
            fn ($b) => [$b['id'] => in_array($b['type'], ChartOfAccount::DEBIT_NORMAL, true) ? round($b['debit'] - $b['credit'], 2) : round($b['credit'] - $b['debit'], 2)]
        );

        return Inertia::render('Account/ChartOfAccounts/Index', [
            'accounts' => $accounts,
            'balances' => $balances,
            'types' => ChartOfAccount::TYPES,
            'filters' => $request->only(['search', 'type']),
        ]);
    }

    public function store(SaveChartOfAccountRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-chart-of-accounts')) {
            return back()->with('error', __('Permission denied'));
        }

        $account = ChartOfAccount::create($request->validated() + ['creator_id' => Auth::id(), 'created_by' => creatorId()]);

        CreateChartOfAccount::dispatch($request, $account);

        return back()->with('success', __('The account has been created successfully.'));
    }

    public function update(SaveChartOfAccountRequest $request, ChartOfAccount $chartOfAccount): RedirectResponse
    {
        if (!Auth::user()->can('edit-chart-of-accounts') || $chartOfAccount->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validated();

        // the automatic postings depend on the default accounts: they can be renamed but nothing else
        if ($chartOfAccount->is_system) {
            $data = ['name' => $data['name'], 'description' => $data['description'] ?? null];
        } elseif ($data['type'] !== $chartOfAccount->type && $this->isUsed($chartOfAccount)) {
            return back()->withErrors(['type' => __('The type of an account with bookings cannot be changed.')]);
        }

        $chartOfAccount->update($data);

        UpdateChartOfAccount::dispatch($request, $chartOfAccount);

        return back()->with('success', __('The account details are updated successfully.'));
    }

    public function destroy(Request $request, ChartOfAccount $chartOfAccount): RedirectResponse
    {
        if (!Auth::user()->can('delete-chart-of-accounts') || $chartOfAccount->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }
        if ($chartOfAccount->is_system) {
            return back()->with('error', __('System accounts cannot be deleted.'));
        }
        if ($this->isUsed($chartOfAccount)) {
            return back()->with('error', __('This account has bookings and cannot be deleted. Deactivate it instead.'));
        }

        DestroyChartOfAccount::dispatch($request, $chartOfAccount);
        $chartOfAccount->delete();

        return back()->with('success', __('The account has been deleted.'));
    }

    private function isUsed(ChartOfAccount $account): bool
    {
        return $account->items()->exists() || Payment::where('account_id', $account->id)->exists();
    }
}
