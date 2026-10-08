<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every Inertia page.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $locale = $this->resolveLocale($user?->lang);
        app()->setLocale($locale);

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user
                    ? array_merge($user->toArray(), [
                        'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                        'roles' => $user->getRoleNames()->values()->all(),
                        'activatedPackages' => ActivatedModule($user->id),
                    ])
                    : null,
                'lang' => $locale,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            // Guests only get public branding (login page); logged-in users get everything.
            'adminAllSetting' => getAdminAllSetting(publicOnly: !$user),
            // only the settings marked public: secrets (e.g. the CRM web form address) and internal flags must not reach every user's browser
            'companyAllSetting' => $user ? getCompanyAllSetting($user->id, publicOnly: true) : [],
            'languages' => availableLanguages(),
            'translations' => $this->translations($locale),
        ];
    }

    private function resolveLocale(?string $userLang): string
    {
        $available = array_keys(availableLanguages());

        foreach ([$userLang, admin_setting('defaultLanguage'), 'en'] as $candidate) {
            if ($candidate && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return 'en';
    }

    private function translations(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        return File::exists($path) ? (json_decode(File::get($path), true) ?? []) : [];
    }
}
