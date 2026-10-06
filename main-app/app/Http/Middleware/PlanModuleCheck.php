<?php

namespace App\Http\Middleware;

use App\Services\PlanService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 1. Blocks companies whose plan is missing/expired (they may only reach the plan/billing routes).
 * 2. Logs out sub-users whose company plan has expired.
 * 3. With a module argument (PlanModuleCheck:Hrm or Hrm-Lead = any of) requires an active module.
 */
class PlanModuleCheck
{
    /** Routes a company with an expired plan can still use. */
    private const ALLOWED_WHEN_EXPIRED = [
        'plans.*', 'bank-transfers.store', 'profile.*', 'languages.change', 'logout',
    ];

    public function __construct(private PlanService $plans)
    {
    }

    public function handle(Request $request, Closure $next, ?string $modules = null): Response
    {
        $user = Auth::user();

        if (!$user) {
            return $next($request);
        }

        if ($user->type !== 'superadmin') {
            $company = companyOf($user);

            if ($user->type === 'company') {
                if ($this->plans->isExpired($user) && !$request->routeIs(self::ALLOWED_WHEN_EXPIRED)) {
                    return redirect()->route('plans.index')
                        ->with('error', __('Your plan has expired. Please renew your subscription.'));
                }
            } elseif (!$company || $this->plans->isExpired($company)) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')
                    ->with('status', __('Company plan has expired. Please contact your administrator.'));
            }
        }

        if ($modules !== null) {
            foreach (explode('-', $modules) as $module) {
                if (Module_is_active($module)) {
                    return $next($request);
                }
            }

            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        return $next($request);
    }
}
