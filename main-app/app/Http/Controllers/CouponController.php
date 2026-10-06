<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CouponController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-coupons')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        return Inertia::render('Coupons/Index', [
            'coupons' => Coupon::withCount('usages')->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-coupons')) {
            return back()->with('error', __('Permission denied'));
        }

        Coupon::create($this->validated($request) + ['created_by' => Auth::id()]);

        return back()->with('success', __('The coupon has been created successfully.'));
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        if (!Auth::user()->can('edit-coupons')) {
            return back()->with('error', __('Permission denied'));
        }

        $coupon->update($this->validated($request, $coupon));

        return back()->with('success', __('The coupon details are updated successfully.'));
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        if (!Auth::user()->can('delete-coupons')) {
            return back()->with('error', __('Permission denied'));
        }

        $coupon->delete();

        return back()->with('success', __('The coupon has been deleted.'));
    }

    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'type' => ['required', Rule::in(['percentage', 'flat'])],
            'discount' => 'required|numeric|min:0.01|max:99999999',
            'usage_limit' => 'nullable|integer|min:1',
            'expiry_date' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        if ($data['type'] === 'percentage' && $data['discount'] > 100) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'discount' => __('A percentage discount cannot exceed 100.'),
            ]);
        }

        $data['code'] = strtoupper($data['code']);

        return $data;
    }
}
