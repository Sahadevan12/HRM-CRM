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
use Workdo\Account\Models\Payment;
use Workdo\Account\Services\AccountService;
use Workdo\Account\Services\PaymentService;
use Workdo\SalesPurchase\Models\Document;

/** Customer and vendor payments share this controller; the route default `kind` selects which. */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments, private AccountService $accounts)
    {
    }

    public function index(Request $request, string $kind): Response|RedirectResponse
    {
        if (!Auth::user()->can("manage-{$kind}-payments")) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $this->accounts->ensureDefaults($tenant);

        $payments = Payment::with(['party:id,name', 'document:id,number,total_amount,paid_amount', 'account:id,code,name'])
            ->where('created_by', $tenant)->where('kind', $kind)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($s) => $s->where('reference', 'like', $term)->orWhereHas('party', fn ($p) => $p->where('name', 'like', $term))
                    ->orWhereHas('document', fn ($d) => $d->where('number', 'like', $term)));
            })
            ->latest('payment_date')->latest('id')
            ->paginate((int) $request->get('per_page', 10))
            ->withQueryString();

        // invoices that can still receive a payment (posted, with an outstanding amount)
        $invoices = Document::with('party:id,name')->where('created_by', $tenant)->where('type', PaymentService::invoiceType($kind))
            ->where('status', 'posted')->whereColumn('paid_amount', '<', 'total_amount')->orderBy('doc_date')->limit(200)->get()
            ->map(fn (Document $d) => ['id' => $d->id, 'number' => $d->number, 'party' => $d->party?->name, 'total_amount' => $d->total_amount, 'outstanding' => $this->payments->outstanding($d)])
            ->filter(fn ($d) => $d['outstanding'] > 0)->values();

        return Inertia::render('Account/Payments/Index', [
            'payments' => $payments,
            'invoices' => $invoices,
            'accounts' => ChartOfAccount::where('created_by', $tenant)->where('is_bank', true)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'kind' => $kind,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        if (!Auth::user()->can("create-{$kind}-payments")) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate([
            'document_id' => ['required', Rule::exists('documents', 'id')->where('created_by', $tenant)->where('type', PaymentService::invoiceType($kind))->where('status', 'posted')],
            'account_id' => ['required', Rule::exists('chart_of_accounts', 'id')->where('created_by', $tenant)->where('is_bank', true)->where('is_active', true)],
            'amount' => 'required|numeric|gt:0|max:9999999999',
            'payment_date' => 'required|date',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $this->payments->create(
                $kind, Document::findOrFail($data['document_id']), (int) $data['account_id'], (float) $data['amount'],
                $data['payment_date'], $data['reference'] ?? null, $data['notes'] ?? null, Auth::id(),
            );
        } catch (AccountingException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', __('The payment has been recorded.'));
    }

    public function destroy(Payment $payment, string $kind): RedirectResponse
    {
        if (!Auth::user()->can("delete-{$kind}-payments") || $payment->created_by !== creatorId() || $payment->kind !== $kind) {
            return back()->with('error', __('Permission denied'));
        }

        $this->payments->delete($payment);

        return back()->with('success', __('The payment has been deleted.'));
    }
}
