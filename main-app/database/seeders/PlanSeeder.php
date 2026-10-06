<?php

namespace Database\Seeders;

use App\Classes\Module;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $modules = new Module();
        $modules->sync();
        $installed = $modules->installed();

        $admin = User::where('type', 'superadmin')->first();

        $free = Plan::firstOrCreate(['name' => 'Free'], [
            'description' => 'Get started with the essentials.',
            'monthly_price' => 0, 'yearly_price' => 0, 'max_users' => 3,
            'free_plan' => true, 'modules' => [], 'created_by' => $admin?->id,
        ]);

        Plan::firstOrCreate(['name' => 'Pro'], [
            'description' => 'All add-on modules for a growing business.',
            'monthly_price' => 29, 'yearly_price' => 290, 'max_users' => 25,
            'trial' => true, 'trial_days' => 14, 'modules' => $installed, 'created_by' => $admin?->id,
        ]);

        // The seeded demo company starts on the free plan.
        $company = User::where('email', 'company@example.com')->first();
        if ($company && (int) $company->active_plan === 0) {
            app(PlanService::class)->assign($company, $free);
        }
    }
}
