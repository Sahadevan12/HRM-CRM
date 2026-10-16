<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentGateways;
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
            // the gateway secrets stay on the server: the page only learns THAT a secret is saved
            'settings' => array_diff_key(Auth::user()->isSuperadmin() ? getAdminAllSetting() : getCompanyAllSetting(), array_flip(PaymentGateways::SECRET_KEYS)),
            'themeColors' => self::THEME_COLORS,
            'gateways' => Auth::user()->isSuperadmin() ? $this->gatewayState() : null,
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

    /** Instructions shown to companies paying by bank transfer (platform setting, set by superadmin). */
    public function updatePayment(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('edit-settings')) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate(['bankTransferDetails' => 'nullable|string|max:2000']);

        $this->save($validated);

        return back()->with('success', __('Payment settings updated successfully.'));
    }

    /** Platform owner: switch the online payment gateways on and enter their keys. Empty secret fields keep what is saved. */
    public function updateGateways(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('edit-settings') || !Auth::user()->isSuperadmin()) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validate([
            'stripeEnabled' => 'required|boolean',
            'stripeSecretKey' => ['nullable', 'string', 'max:255', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
            'stripeWebhookSecret' => ['nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9]+$/'],
            'razorpayEnabled' => 'required|boolean',
            'razorpayKeyId' => ['nullable', 'string', 'max:100', 'regex:/^rzp_(test|live)_[A-Za-z0-9]+$/'],
            'razorpayKeySecret' => 'nullable|string|max:255',
            'razorpayWebhookSecret' => 'nullable|string|max:255',
        ]);

        $saved = fn (string $key) => filled(tenantSettings(superadminId())[$key] ?? null);

        // a gateway can only be switched on when it has the keys it needs (the ones in the form or the ones already saved)
        $need = ['stripe' => ['stripeSecretKey'], 'razorpay' => ['razorpayKeyId', 'razorpayKeySecret']];
        foreach (['stripe', 'razorpay'] as $gateway) {
            if ($data[$gateway . 'Enabled']) {
                foreach ($need[$gateway] as $key) {
                    if (blank($data[$key] ?? null) && !$saved($key)) {
                        return back()->withErrors([$key => __('Enter this key before switching the gateway on.')]);
                    }
                }
            }
        }

        // the switches are public (the checkout page needs to know), the keys are not
        foreach (['stripeEnabled', 'razorpayEnabled'] as $key) {
            setSetting($key, $data[$key] ? '1' : '0', null, false);
        }
        foreach (['stripeSecretKey', 'stripeWebhookSecret', 'razorpayKeyId', 'razorpayKeySecret', 'razorpayWebhookSecret'] as $key) {
            if (filled($data[$key] ?? null)) {
                setSetting($key, $data[$key], null, false);
            }
        }

        return back()->with('success', __('Payment gateways updated successfully.'));
    }

    /** @return array<string, mixed> what the settings page may know about the gateways (never a secret) */
    private function gatewayState(): array
    {
        $s = tenantSettings(superadminId());
        $has = fn (string $key) => filled($s[$key] ?? null);

        return [
            'currency' => strtoupper($s['currencyCode'] ?? 'USD'),
            'stripe' => ['enabled' => ($s['stripeEnabled'] ?? '0') === '1', 'hasSecret' => $has('stripeSecretKey'), 'hasWebhookSecret' => $has('stripeWebhookSecret'), 'webhookUrl' => route('payments.webhook.stripe')],
            'razorpay' => [
                'enabled' => ($s['razorpayEnabled'] ?? '0') === '1', 'keyId' => $s['razorpayKeyId'] ?? '', 'hasSecret' => $has('razorpayKeySecret'),
                'hasWebhookSecret' => $has('razorpayWebhookSecret'), 'webhookUrl' => route('payments.webhook.razorpay'),
            ],
        ];
    }

    private function save(array $values): void
    {
        foreach ($values as $key => $value) {
            setSetting($key, $value);
        }
    }
}
