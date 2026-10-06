<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Superadmin: the companies (tenants) that use the platform. */
class CompanyController extends Controller
{
    public function __construct(private PlanService $plans)
    {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-companies')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $planNames = Plan::pluck('name', 'id');

        $companies = User::where('type', 'company')
            ->withCount('members')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($s) => $s->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->latest()
            ->paginate((int) $request->get('per_page', 10))
            ->withQueryString()
            ->through(fn (User $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'plan_id' => (int) $c->active_plan ?: null,
                'plan_name' => $planNames[$c->active_plan] ?? null,
                'plan_expire_date' => $c->plan_expire_date?->toDateString(),
                'trial_expire_date' => $c->trial_expire_date?->toDateString(),
                'expired' => $this->plans->isExpired($c),
                'is_enable_login' => $c->is_enable_login,
                'members_count' => $c->members_count,
                'total_user' => $c->total_user,
                'created_at' => $c->created_at->toDateString(),
            ]);

        return Inertia::render('Companies/Index', [
            'companies' => $companies,
            'plans' => Plan::orderBy('monthly_price')->get(['id', 'name', 'free_plan', 'trial', 'is_disable']),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-companies')) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email',
            'password' => ['required', Password::defaults()],
        ]);

        $company = User::create($validated + [
            'type' => 'company',
            'email_verified_at' => now(),
            'creator_id' => Auth::id(),
            'created_by' => Auth::id(),
        ]);
        $company->assignRole('company');
        assignPlan(userId: $company->id); // free plan when one exists

        return back()->with('success', __('The company has been created successfully.'));
    }

    public function update(Request $request, User $company): RedirectResponse
    {
        if (!Auth::user()->can('edit-companies') || $company->type !== 'company') {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($company->id)],
        ]);

        $company->update($validated);

        return back()->with('success', __('The company details are updated successfully.'));
    }

    /** Block / unblock every login of the company and its staff. */
    public function toggleLogin(User $company): RedirectResponse
    {
        if (!Auth::user()->can('edit-companies') || $company->type !== 'company') {
            return back()->with('error', __('Permission denied'));
        }

        $company->update(['is_enable_login' => !$company->is_enable_login]);

        return back()->with('success', $company->is_enable_login ? __('Company login enabled.') : __('Company login disabled.'));
    }

    public function assignPlan(Request $request, User $company): RedirectResponse
    {
        if (!Auth::user()->can('edit-companies') || $company->type !== 'company') {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'duration' => ['required', Rule::in(['month', 'year', 'trial', 'lifetime'])],
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);
        $duration = $validated['duration'];

        if ($duration === 'trial' && !$plan->trial) {
            return back()->withErrors(['duration' => __('This plan does not offer a trial.')]);
        }
        if ($plan->free_plan) {
            $duration = 'lifetime'; // free plans never expire
        }

        $this->plans->assign($company, $plan, $duration === 'lifetime' ? null : $duration);

        return back()->with('success', __('The plan has been assigned.'));
    }

    public function destroy(User $company): RedirectResponse
    {
        if (!Auth::user()->can('delete-companies') || $company->type !== 'company') {
            return back()->with('error', __('Permission denied'));
        }

        $company->delete(); // staff, settings, orders ... cascade via created_by / user_id foreign keys

        return back()->with('success', __('The company has been deleted.'));
    }
}
