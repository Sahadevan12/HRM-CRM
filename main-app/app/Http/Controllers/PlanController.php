<?php

namespace App\Http\Controllers;

use App\Classes\Module;
use App\Models\AddOn;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function __construct(private PlanService $plans)
    {
    }

    /** Superadmin: manage plans. Company: browse plans / see current subscription. */
    public function index(): Response|RedirectResponse
    {
        $user = Auth::user();

        if (!$user->can('manage-plans')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        if ($user->isSuperadmin()) {
            return Inertia::render('Plans/Index', [
                'plans' => Plan::withCount(['companies'])->orderBy('monthly_price')->get(),
                // always-active modules are free for everyone, so they are not part of any plan
                'modules' => AddOn::where('for_admin', false)->whereNotIn('module', PlanService::ALWAYS_ACTIVE)->orderBy('priority')->get(['module', 'name']),
            ]);
        }

        $company = companyOf($user);

        return Inertia::render('Plans/Browse', [
            'plans' => Plan::where('is_disable', false)->orderBy('monthly_price')->get(),
            'currentPlan' => [
                'id' => $company->active_plan,
                'name' => Plan::find($company->active_plan)?->name,
                'expires_at' => ($company->plan_expire_date ?? $company->trial_expire_date)?->toDateString(),
                'is_trial' => !$company->plan_expire_date && $company->trial_expire_date,
                'expired' => $this->plans->isExpired($company),
            ],
            'moduleNames' => AddOn::pluck('name', 'module'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-plans')) {
            return back()->with('error', __('Permission denied'));
        }

        Plan::create($this->validated($request) + ['created_by' => Auth::id()]);

        return back()->with('success', __('The plan has been created successfully.'));
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        if (!Auth::user()->can('edit-plans')) {
            return back()->with('error', __('Permission denied'));
        }

        $plan->update($this->validated($request));

        return back()->with('success', __('The plan details are updated successfully.'));
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if (!Auth::user()->can('delete-plans')) {
            return back()->with('error', __('Permission denied'));
        }

        if (User::where('active_plan', $plan->id)->exists()) {
            return back()->with('error', __('Companies are subscribed to this plan. Disable it instead of deleting.'));
        }

        $plan->delete();

        return back()->with('success', __('The plan has been deleted.'));
    }

    public function subscribe(Plan $plan): Response|RedirectResponse
    {
        if (!Auth::user()->can('subscribe-plans') || $plan->is_disable) {
            return redirect()->route('plans.index')->with('error', __('Permission denied'));
        }

        return Inertia::render('Plans/Subscribe', [
            'plan' => $plan,
            'bankTransferDetails' => admin_setting('bankTransferDetails'),
            'moduleNames' => AddOn::pluck('name', 'module'),
        ]);
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        if (!Auth::user()->can('subscribe-plans')) {
            return response()->json(['error' => __('Permission denied')], 403);
        }

        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'duration' => ['required', Rule::in(['month', 'year'])],
            'coupon_code' => 'required|string|max:50',
        ]);

        $quote = $this->plans->quote(Plan::findOrFail($validated['plan_id']), $validated['duration'], $validated['coupon_code'], companyOf(Auth::user()));

        if (is_string($quote)) {
            return response()->json(['error' => $quote], 422);
        }

        return response()->json(['price' => $quote['price'], 'discount' => $quote['discount'], 'final_price' => $quote['final_price']]);
    }

    /** Take the free plan (no payment). */
    public function assignFree(Plan $plan): RedirectResponse
    {
        if (!Auth::user()->can('subscribe-plans') || !$plan->free_plan || $plan->is_disable) {
            return back()->with('error', __('Permission denied'));
        }

        $this->recordOrder($plan, 'free', 'free');
        $this->plans->assign(companyOf(Auth::user()), $plan);

        return redirect()->route('dashboard')->with('success', __('The plan has been activated.'));
    }

    /** One-time trial of a plan. */
    public function startTrial(Plan $plan): RedirectResponse
    {
        $company = companyOf(Auth::user());

        if (!Auth::user()->can('subscribe-plans') || !$plan->trial || $plan->is_disable) {
            return back()->with('error', __('Permission denied'));
        }
        if ($company->is_trial_done) {
            return back()->with('error', __('You have already used your trial.'));
        }

        $this->recordOrder($plan, 'trial', 'trial');
        $this->plans->assign($company, $plan, 'trial');

        return redirect()->route('dashboard')->with('success', __('Your trial has started.'));
    }

    private function recordOrder(Plan $plan, string $duration, string $paymentType): Order
    {
        return Order::create([
            'order_number' => Order::generateNumber(),
            'user_id' => companyOf(Auth::user())->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'duration' => $duration,
            'price' => 0,
            'discount' => 0,
            'final_price' => 0,
            'payment_type' => $paymentType,
            'payment_status' => 'paid',
        ]);
    }

    private function validated(Request $request): array
    {
        $installed = (new Module())->installed();

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'monthly_price' => 'required|numeric|min:0|max:99999999',
            'yearly_price' => 'required|numeric|min:0|max:99999999',
            'max_users' => 'required|integer|min:-1',
            'free_plan' => 'boolean',
            'trial' => 'boolean',
            'trial_days' => 'required_if:trial,true|nullable|integer|min:0|max:365',
            'is_disable' => 'boolean',
            'modules' => 'array',
            'modules.*' => ['string', Rule::in($installed)],
        ]);

        $data['modules'] = array_values($data['modules'] ?? []);
        $data['trial_days'] = $data['trial_days'] ?? 0;

        return $data;
    }
}
