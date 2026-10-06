<?php

namespace Workdo\Account\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Account\Exceptions\AccountingException;
use Workdo\Account\Models\ChartOfAccount;
use Workdo\Account\Models\JournalEntry;
use Workdo\Account\Services\AccountService;
use Workdo\Account\Services\JournalService;

class JournalEntryController extends Controller
{
    public function __construct(private JournalService $journal, private AccountService $accounts)
    {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-journal-entries')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $entries = JournalEntry::where('created_by', creatorId())
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($s) => $s->where('number', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when(in_array($request->get('type'), ['manual', 'automatic'], true), fn ($q) => $q->where('entry_type', $request->get('type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('journal_date', '>=', $request->get('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('journal_date', '<=', $request->get('to')))
            ->latest('journal_date')->latest('id')
            ->paginate((int) $request->get('per_page', 15))
            ->withQueryString();

        return Inertia::render('Account/JournalEntries/Index', ['entries' => $entries, 'filters' => $request->only(['search', 'type', 'from', 'to'])]);
    }

    public function create(): Response|RedirectResponse
    {
        if (!Auth::user()->can('create-journal-entries')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $this->accounts->ensureDefaults($tenant);

        return Inertia::render('Account/JournalEntries/Form', [
            'accounts' => ChartOfAccount::where('created_by', $tenant)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-journal-entries')) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate([
            'journal_date' => 'required|date',
            'description' => 'required|string|max:255',
            'lines' => 'required|array|min:2|max:100',
            'lines.*.account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('created_by', $tenant)->where('is_active', true)],
            'lines.*.debit' => 'nullable|numeric|min:0|max:9999999999',
            'lines.*.credit' => 'nullable|numeric|min:0|max:9999999999',
            'lines.*.description' => 'nullable|string|max:255',
        ]);

        try {
            $entry = $this->journal->record($tenant, Auth::id(), $data['journal_date'], $data['description'], $data['lines'], null, null, 'manual');
        } catch (AccountingException $e) {
            return back()->withErrors(['lines' => $e->getMessage()]);
        }

        return redirect()->route('account.journal-entries.show', $entry->id)->with('success', __('The journal entry has been posted.'));
    }

    public function show(JournalEntry $journalEntry): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-journal-entries') || $journalEntry->created_by !== creatorId()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        return Inertia::render('Account/JournalEntries/Show', [
            'entry' => $journalEntry->load('items.account:id,code,name'),
            'canDelete' => Auth::user()->can('delete-journal-entries') && !$journalEntry->isAutomatic(),
        ]);
    }

    /** Only manual entries can be removed; automatic ones follow their document (which is immutable once posted). */
    public function destroy(JournalEntry $journalEntry): RedirectResponse
    {
        if (!Auth::user()->can('delete-journal-entries') || $journalEntry->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }
        if ($journalEntry->isAutomatic()) {
            return back()->with('error', __('Automatic entries cannot be deleted.'));
        }

        $journalEntry->delete();

        return redirect()->route('account.journal-entries.index')->with('success', __('The journal entry has been deleted.'));
    }
}
