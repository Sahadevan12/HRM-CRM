<?php

namespace App\Http\Middleware;

use App\Services\PlanService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The mobile API twin of PlanModuleCheck (that one logs out and redirects, an API answers with JSON):
 *  - the user and their company must be enabled, the company's plan must not be expired (else 403 and the token is revoked / refused),
 *  - with a module argument (`api.access:Hrm` or `Hrm-Lead` = any of) the module must be active for the company.
 * Put it AFTER `auth:sanctum`.
 */
class ApiAccess
{
    public function __construct(private PlanService $plans)
    {
    }

    public function handle(Request $request, Closure $next, ?string $modules = null): Response
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->type !== 'superadmin') {
            $company = companyOf($user);

            if (!$user->is_enable_login || !$company || !$company->is_enable_login) {
                $user->currentAccessToken()?->delete();

                return response()->json(['message' => __('Your account has been disabled. Please contact the administrator.'), 'code' => 'account_disabled'], 403);
            }

            if ($this->plans->isExpired($company)) {
                return response()->json(['message' => __('The plan of your company has expired.'), 'code' => 'plan_expired'], 403);
            }
        }

        if ($modules !== null) {
            foreach (explode('-', $modules) as $module) {
                if (Module_is_active($module)) {
                    return $next($request);
                }
            }

            return response()->json(['message' => __('This feature is not part of your plan.'), 'code' => 'module_inactive'], 403);
        }

        return $next($request);
    }
}
