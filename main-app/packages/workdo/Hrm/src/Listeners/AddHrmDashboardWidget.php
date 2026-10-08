<?php

namespace Workdo\Hrm\Listeners;

use App\Events\CollectDashboardWidgets;
use Workdo\Hrm\Models\Attendance;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\LeaveApplication;
use Workdo\Hrm\Services\AttendanceService;

/** The HRM card of the company dashboard (only for users who may open the HRM dashboard). */
class AddHrmDashboardWidget
{
    public function handle(CollectDashboardWidgets $event): void
    {
        $tenant = creatorId();

        if (!Module_is_active('Hrm', $tenant) || !$event->user->can('view-hrm-dashboard')) {
            return;
        }

        $today = now(app(AttendanceService::class)->tz($tenant))->toDateString();

        $event->add([
            'key' => 'hrm', 'title' => 'HRM', 'href' => route('hrm.dashboard'), 'order' => 20,
            'stats' => [
                ['label' => 'Employees', 'value' => Employee::where('created_by', $tenant)->where('status', 'active')->count(), 'format' => 'number'],
                ['label' => 'Present today', 'value' => Attendance::where('created_by', $tenant)->whereDate('date', $today)->whereIn('status', ['present', 'half_day'])->count(), 'format' => 'number'],
                ['label' => 'Pending leave requests', 'value' => LeaveApplication::where('created_by', $tenant)->where('status', 'pending')->count(), 'format' => 'number'],
                ['label' => 'New this month', 'value' => Employee::where('created_by', $tenant)->whereDate('date_of_joining', '>=', now()->startOfMonth()->toDateString())->count(), 'format' => 'number'],
            ],
        ]);
    }
}
