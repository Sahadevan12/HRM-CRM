<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public const THEME_COLORS = ['slate', 'blue', 'green', 'violet', 'rose', 'orange'];

    public function index(): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-settings')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        return Inertia::render('Settings/Index', [
            'settings' => Auth::user()->isSuperadmin() ? getAdminAllSetting() : getCompanyAllSetting(),
            'themeColors' => self::THEME_COLORS,
        ]);
    }

    public function updateBrand(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('edit-settings')) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'titleText' => 'required|string|max:60',
            'footerText' => 'nullable|string|max:150',
            'themeColor' => ['required', Rule::in(self::THEME_COLORS)],
            'themeMode' => ['required', Rule::in(['light', 'dark', 'system'])],
        ]);

        $this->save($validated);

        return back()->with('success', __('Brand settings updated successfully.'));
    }

    public function updateSystem(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('edit-settings')) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'defaultLanguage' => ['required', Rule::in(array_keys(availableLanguages()))],
            'dateFormat' => ['required', Rule::in(['Y-m-d', 'd-m-Y', 'm/d/Y', 'd M Y'])],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $this->save($validated);

        return back()->with('success', __('System settings updated successfully.'));
    }

    public function updateCurrency(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('edit-settings')) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'currencyCode' => 'required|string|size:3|alpha',
            'currencySymbol' => 'required|string|max:5',
            'currencyPosition' => ['required', Rule::in(['before', 'after'])],
            'currencyDecimals' => 'required|integer|between:0,4',
        ]);
        $validated['currencyCode'] = strtoupper($validated['currencyCode']);

        $this->save($validated);

        return back()->with('success', __('Currency settings updated successfully.'));
    }

    private function save(array $values): void
    {
        foreach ($values as $key => $value) {
            setSetting($key, $value);
        }
    }
}
