<?php

namespace App\Http\Controllers;

use App\Models\BankTransferPayment;
use App\Models\Order;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BankTransferPaymentController extends Controller
{
    public function __construct(private PlanService $plans)
    {
    }

    /** Superadmin: review queue. */
    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-bank-transfers')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        return Inertia::render('BankTransfers/Index', [
            'payments' => BankTransferPayment::with(['order:id,order_number,plan_name,duration,final_price', 'user:id,name,email'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
                ->latest()
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only('status'),
        ]);
    }

    /** Company: subscribe to a paid plan by bank transfer (creates a pending order). */
    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('subscribe-plans')) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'duration' => ['required', Rule::in(['month', 'year'])],
            'coupon_code' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:1000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:4096',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        if ($plan->is_disable || $plan->free_plan) {
            return back()->with('error', __('This plan cannot be purchased.'));
        }

        $company = companyOf(Auth::user());
        $quote = $this->plans->quote($plan, $validated['duration'], $validated['coupon_code'] ?? null, $company);
        if (is_string($quote)) {
            return back()->withErrors(['coupon_code' => $quote]);
        }

        if (Order::where('user_id', $company->id)->where('payment_type', 'bank_transfer')->where('payment_status', 'pending')->exists()) {
            return back()->with('error', __('You already have a bank transfer waiting for approval.'));
        }

        DB::transaction(function () use ($request, $validated, $plan, $company, $quote) {
            $order = Order::create([
                'order_number' => Order::generateNumber(),
                'user_id' => $company->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'duration' => $validated['duration'],
                'price' => $quote['price'],
                'discount' => $quote['discount'],
                'final_price' => $quote['final_price'],
                'coupon_code' => $quote['coupon']?->code,
                'payment_type' => 'bank_transfer',
                'payment_status' => 'pending',
            ]);

            BankTransferPayment::create([
                'order_id' => $order->id,
                'user_id' => $company->id,
                'amount' => $quote['final_price'],
                'notes' => $validated['notes'] ?? null,
                'attachment' => $request->file('attachment')?->store('bank-transfers', 'local'),
            ]);
        });

        return redirect()->route('orders.index')->with('success', __('Your payment is submitted and waiting for approval.'));
    }

    public function approve(BankTransferPayment $payment): RedirectResponse
    {
        if (!Auth::user()->can('manage-bank-transfers') || $payment->status !== 'pending') {
            return back()->with('error', __('Permission denied'));
        }

        DB::transaction(function () use ($payment) {
            $payment->update(['status' => 'approved']);
            $this->plans->completeOrder($payment->order);
        });

        return back()->with('success', __('The payment has been approved and the plan activated.'));
    }

    public function reject(Request $request, BankTransferPayment $payment): RedirectResponse
    {
        if (!Auth::user()->can('manage-bank-transfers') || $payment->status !== 'pending') {
            return back()->with('error', __('Permission denied'));
        }

        $note = $request->validate(['response_note' => 'nullable|string|max:500'])['response_note'] ?? null;

        DB::transaction(function () use ($payment, $note) {
            $payment->update(['status' => 'rejected', 'response_note' => $note]);
            $payment->order->update(['payment_status' => 'rejected']);
        });

        return back()->with('success', __('The payment has been rejected.'));
    }

    /** Superadmin: view/download the uploaded proof. */
    public function attachment(BankTransferPayment $payment)
    {
        if (!Auth::user()->can('manage-bank-transfers') || !$payment->attachment || !Storage::disk('local')->exists($payment->attachment)) {
            abort(404);
        }

        return Storage::disk('local')->download($payment->attachment);
    }
}
