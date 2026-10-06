<?php

namespace App\Services;

use App\Classes\Module;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserActiveModule;
use App\Models\UserCoupon;
use Illuminate\Support\Facades\DB;

class PlanService
{
    /** Modules every company gets regardless of plan (e.g. shared catalogue modules). Extend as modules appear. */
    public const ALWAYS_ACTIVE = ['ProductService'];

    /** Plan state of a company user: no plan / past expiry date / past trial end => expired. */
    public function isExpired(User $company): bool
    {
        if ((int) $company->active_plan === 0) {
            return true;
        }

        if ($company->plan_expire_date) {
            return $company->plan_expire_date->endOfDay()->isPast();
        }

        if ($company->trial_expire_date) {
            return $company->trial_expire_date->endOfDay()->isPast();
        }

        return false; // free / lifetime plan
    }

    /**
     * Put a company on a plan: sets limits + expiry and replaces its module grants with the plan's modules.
     * $duration: 'month' | 'year' | 'trial' | null (free/lifetime).
     */
    public function assign(User $company, Plan $plan, ?string $duration = null): User
    {
        return DB::transaction(function () use ($company, $plan, $duration) {
            $company->active_plan = $plan->id;
            $company->total_user = $plan->max_users;
            $company->plan_expire_date = null;
            $company->trial_expire_date = null;

            match ($duration) {
                'month' => $company->plan_expire_date = now()->addMonth()->toDateString(),
                'year' => $company->plan_expire_date = now()->addYear()->toDateString(),
                'trial' => $company->trial_expire_date = now()->addDays($plan->trial_days)->toDateString(),
                default => null,
            };

            if ($duration === 'trial') {
                $company->is_trial_done = true;
            }
            $company->save();

            $modules = array_values(array_intersect($plan->modules ?? [], (new Module())->installed()));
            UserActiveModule::where('user_id', $company->id)->whereNotIn('module', $modules)->delete();
            foreach ($modules as $module) {
                UserActiveModule::firstOrCreate(['user_id' => $company->id, 'module' => $module]);
            }

            return $company->refresh();
        });
    }

    /** Modules a company may use right now (platform-enabled ∩ granted by plan, + always-active). */
    public function activeModules(User $company): array
    {
        $granted = UserActiveModule::where('user_id', $company->id)->pluck('module')->all();
        $enabled = (new Module())->allEnabled();

        return array_values(array_unique(array_merge(
            array_intersect($enabled, self::ALWAYS_ACTIVE),
            array_intersect($enabled, $granted),
        )));
    }

    /**
     * Validate a coupon for a user. Returns the Coupon, or a string error message.
     */
    public function validateCoupon(string $code, User $company): Coupon|string
    {
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon || !$coupon->is_active) {
            return __('Invalid coupon code.');
        }
        if ($coupon->expiry_date && $coupon->expiry_date->endOfDay()->isPast()) {
            return __('This coupon has expired.');
        }
        if ($coupon->usage_limit !== null && $coupon->usages()->count() >= $coupon->usage_limit) {
            return __('This coupon has reached its usage limit.');
        }
        if ($coupon->usages()->where('user_id', $company->id)->exists()) {
            return __('You have already used this coupon.');
        }

        return $coupon;
    }

    /**
     * Price breakdown for a plan + duration (+ optional coupon).
     *
     * @return array{price: float, discount: float, final_price: float, coupon: ?Coupon}|string  string = error
     */
    public function quote(Plan $plan, string $duration, ?string $couponCode, User $company): array|string
    {
        $price = $plan->priceFor($duration);
        $coupon = null;
        $discount = 0.0;

        if ($couponCode) {
            $coupon = $this->validateCoupon($couponCode, $company);
            if (is_string($coupon)) {
                return $coupon;
            }
            $discount = $coupon->discountFor($price);
        }

        return ['price' => $price, 'discount' => $discount, 'final_price' => round($price - $discount, 2), 'coupon' => $coupon];
    }

    /** Mark an order paid: activate the plan and burn the coupon. */
    public function completeOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order->update(['payment_status' => 'paid']);

            if ($order->plan && $order->user) {
                $duration = in_array($order->duration, ['month', 'year', 'trial'], true) ? $order->duration : null;
                $this->assign($order->user, $order->plan, $duration);
            }

            if ($order->coupon_code && ($coupon = Coupon::where('code', $order->coupon_code)->first())) {
                UserCoupon::firstOrCreate(['user_id' => $order->user_id, 'coupon_id' => $coupon->id, 'order_id' => $order->id]);
            }
        });
    }
}
