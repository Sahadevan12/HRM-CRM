<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Token login for the mobile apps. Only company owners and their staff use the app (clients and vendors have no mobile features).
 * A token is a personal access token of Laravel Sanctum: it lives 60 days, belongs to one device name and is revoked on logout.
 */
class AuthController extends Controller
{
    private const TOKEN_DAYS = 60;

    public function login(Request $request, PlanService $plans): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:255',
            'device_name' => 'required|string|max:100',
        ]);

        // five wrong passwords for one e-mail from one address: wait a minute (the route has its own coarse throttle as well)
        $throttle = 'api-login:' . strtolower($data['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            return response()->json(['message' => __('Too many attempts. Try again in :s seconds.', ['s' => RateLimiter::availableIn($throttle)])], 429);
        }

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttle, 60);

            return response()->json(['message' => __('These credentials do not match our records.')], 422);
        }
        RateLimiter::clear($throttle);

        if (!in_array($user->type, ['company', 'staff'], true)) {
            return response()->json(['message' => __('This account type cannot use the mobile app.'), 'code' => 'not_allowed'], 403);
        }

        $company = companyOf($user);
        if (!$user->is_enable_login || !$company || !$company->is_enable_login) {
            return response()->json(['message' => __('Your account has been disabled. Please contact the administrator.'), 'code' => 'account_disabled'], 403);
        }
        if ($plans->isExpired($company)) {
            return response()->json(['message' => __('The plan of your company has expired.'), 'code' => 'plan_expired'], 403);
        }

        $token = $user->createToken($data['device_name'], ['*'], now()->addDays(self::TOKEN_DAYS));

        return response()->json(['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at, 'user' => $this->profile($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /** What the app needs to decide which screens to show. */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'type' => $user->type,
            'company' => companyOf($user)?->name,
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'modules' => ActivatedModule($user->id),
        ];
    }
}
