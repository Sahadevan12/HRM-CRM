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

if (!function_exists('ActivatedModule')) {
    /**
     * Names of add-on modules available to the user.
     * PHASE 2 PLACEHOLDER: every folder in packages/workdo that has a module.json.
     * Phase 3 replaces this with add_ons + user_active_modules (plans).
     */
    function ActivatedModule($userId = null): array
    {
        return collect(File::directories(base_path('packages/workdo')))
            ->filter(fn ($dir) => File::exists($dir . '/module.json'))
            ->map(fn ($dir) => basename($dir))
            ->values()
            ->all();
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
