<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

if (!function_exists('creatorId')) {
    /**
     * Tenant (company) id of the logged-in user.
     * superadmin / company => own id, any sub-user => the company that created them.
     */
    function creatorId()
    {
        $user = Auth::user();

        if (in_array($user->type, ['superadmin', 'company'])) {
            return $user->id;
        }

        return $user->created_by;
    }
}

if (!function_exists('setSetting')) {
    /** Create/update one setting for a tenant (default: current tenant) and clear its cache. */
    function setSetting(string $key, $value, $userId = null, bool $isPublic = true): void
    {
        $owner = $userId ?? creatorId();

        Setting::updateOrCreate(
            ['key' => $key, 'created_by' => $owner],
            ['value' => is_array($value) ? json_encode($value) : $value, 'is_public' => $isPublic]
        );

        Cache::forget("settings_{$owner}");
        Cache::forget("settings_{$owner}_public");
    }
}

if (!function_exists('tenantSettings')) {
    /** All settings of one tenant as [key => value]. Cached until a setting changes. */
    function tenantSettings(?int $ownerId, bool $publicOnly = false): array
    {
        if (!$ownerId) {
            return [];
        }

        return Cache::rememberForever(
            "settings_{$ownerId}" . ($publicOnly ? '_public' : ''),
            fn () => Setting::where('created_by', $ownerId)
                ->when($publicOnly, fn ($q) => $q->where('is_public', true))
                ->pluck('value', 'key')
                ->toArray()
        );
    }
}

if (!function_exists('superadminId')) {
    function superadminId(): ?int
    {
        return Cache::rememberForever('superadmin_id', fn () => User::where('type', 'superadmin')->value('id'));
    }
}

if (!function_exists('getAdminAllSetting')) {
    /** Platform (superadmin) settings. */
    function getAdminAllSetting(bool $publicOnly = false): array
    {
        return tenantSettings(superadminId(), $publicOnly);
    }
}

if (!function_exists('getCompanyAllSetting')) {
    /** Settings of the company the given (or current) user belongs to. */
    function getCompanyAllSetting($userId = null, bool $publicOnly = false): array
    {
        $user = $userId ? User::find($userId) : Auth::user();

        if (!$user) {
            return [];
        }

        $ownerId = in_array($user->type, ['company', 'superadmin']) ? $user->id : $user->created_by;

        return tenantSettings($ownerId, $publicOnly);
    }
}

if (!function_exists('admin_setting')) {
    function admin_setting(string $key, $default = null)
    {
        return getAdminAllSetting()[$key] ?? $default;
    }
}

if (!function_exists('company_setting')) {
    function company_setting(string $key, $userId = null, $default = null)
    {
        return getCompanyAllSetting($userId)[$key] ?? $default;
    }
}

if (!function_exists('companyOf')) {
    /** The company (tenant owner) a user belongs to: company/superadmin => itself, sub-user => creator. */
    function companyOf(?User $user): ?User
    {
        if (!$user) {
            return null;
        }

        return in_array($user->type, ['company', 'superadmin']) ? $user : User::find($user->created_by);
    }
}

if (!function_exists('ActivatedModule')) {
    /**
     * Names of add-on modules usable by the user (default: logged-in user).
     * superadmin => every platform-enabled module; others => their company's plan modules.
     */
    function ActivatedModule($userId = null): array
    {
        $user = $userId ? User::find($userId) : Auth::user();

        if (!$user) {
            return [];
        }

        if ($user->type === 'superadmin') {
            return (new App\Classes\Module())->allEnabled();
        }

        $company = companyOf($user);

        return $company ? app(App\Services\PlanService::class)->activeModules($company) : [];
    }
}

if (!function_exists('Module_is_active')) {
    /** Is the module installed, enabled platform-wide AND granted to the user's company? */
    function Module_is_active(string $module, $userId = null): bool
    {
        return in_array($module, ActivatedModule($userId), true);
    }
}

if (!function_exists('assignPlan')) {
    /** Put a company on a plan (default plan = the free plan). Returns false when no plan/user exists. */
    function assignPlan($planId = null, ?string $duration = null, $userId = null): bool
    {
        $company = $userId ? User::find($userId) : Auth::user();
        $plan = $planId ? App\Models\Plan::find($planId) : App\Models\Plan::where('free_plan', true)->first();

        if (!$company || !$plan) {
            return false;
        }

        app(App\Services\PlanService::class)->assign($company, $plan, $duration);

        return true;
    }
}

if (!function_exists('canCreateUser')) {
    /** Plan user-limit check for the current tenant (total_user: -1 = unlimited). */
    function canCreateUser(): array
    {
        $company = companyOf(Auth::user());
        $limit = (int) ($company->total_user ?? 0);

        if ($limit === -1 || User::where('created_by', $company->id)->count() < $limit) {
            return ['can_create' => true, 'message' => ''];
        }

        return ['can_create' => false, 'message' => __('User limit reached for your plan.')];
    }
}
if (!function_exists('availableLanguages')) {
    /** [code => name] for every lang/<code>.json file. Names come from lang/languages.json. */
    function availableLanguages(): array
    {
        $names = json_decode(File::get(lang_path('languages.json')), true) ?? [];

        return collect(File::glob(lang_path('*.json')))
            ->map(fn ($path) => basename($path, '.json'))
            ->reject(fn ($code) => $code === 'languages')
            ->mapWithKeys(fn ($code) => [$code => $names[$code] ?? strtoupper($code)])
            ->all();
    }
}

if (!function_exists('formatCurrency')) {
    /** Format an amount with the current tenant's currency settings. */
    function formatCurrency($amount): string
    {
        $symbol = company_setting('currencySymbol', null, '$');
        $decimals = (int) company_setting('currencyDecimals', null, 2);
        $number = number_format((float) $amount, $decimals);

        return company_setting('currencyPosition', null, 'before') === 'after' ? $number . $symbol : $symbol . $number;
    }
}
