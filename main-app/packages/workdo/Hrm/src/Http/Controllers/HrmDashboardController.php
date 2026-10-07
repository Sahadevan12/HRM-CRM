<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Models\Announcement;
use Workdo\Hrm\Models\Attendance;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\Event;
use Workdo\Hrm\Models\LeaveApplication;
use Workdo\Hrm\Models\Payroll;
use Workdo\Hrm\Models\Resignation;
use Workdo\Hrm\Models\Termination;
use Workdo\Hrm\Models\Transfer;
use Workdo\Hrm\Services\AttendanceService;
use Workdo\Hrm\Services\DefaultData;

class HrmDashboardController extends Controller
{
    public function __invoke(DefaultData $defaults, AttendanceService $attendance): Response|RedirectResponse
    {
        if (!Auth::user()->can('view-hrm-dashboard')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $defaults->ensure($tenant);

        $today = now($attendance->tz($tenant))->toDateString();
        $employees = fn () => Employee::where('created_by', $tenant);
        $active = fn () => $employees()->where('status', 'active');

        $present = Attendance::where('created_by', $tenant)->whereDate('date', $today)->whereIn('status', ['present', 'half_day'])->count();
        $onLeave = LeaveApplication::where('created_by', $tenant)->where('status', 'approved')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->count();

        $kpis = [
            'employees' => $employees()->count(),
            'active' => $active()->count(),
            'present_today' => $present,
            'on_leave_today' => $onLeave,
            'new_this_month' => $employees()->whereDate('date_of_joining', '>=', now()->startOfMonth()->toDateString())->count(),
            'pending_leaves' => LeaveApplication::where('created_by', $tenant)->where('status', 'pending')->count(),
            'pending_requests' => collect([Resignation::class, Termination::class, Transfer::class])->sum(fn ($m) => $m::where('created_by', $tenant)->where('status', 'pending')->count()),
        ];

        $byDepartment = Employee::where('employees.created_by', $tenant)->where('employees.status', 'active')->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->selectRaw('COALESCE(departments.name, ?) as name, COUNT(*) as value', [__('No department')])->groupBy('departments.name')->orderByDesc('value')->get();

        $count = fn (string $column) => $active()->selectRaw("COALESCE($column, 'unknown') as name, COUNT(*) as value")->groupBy($column)->get();

        // present / half day per day for the last 7 days
        $days = collect(range(6, 0))->map(fn ($i) => now($attendance->tz($tenant))->subDays($i)->toDateString());
        $perDay = Attendance::where('created_by', $tenant)->whereIn('date', $days)->whereIn('status', ['present', 'half_day'])
            ->selectRaw('date, COUNT(*) as c')->groupBy('date')->pluck('c', 'date');
        $attendanceTrend = $days->map(fn ($d) => ['date' => substr($d, 5), 'present' => (int) ($perDay[$d] ?? $perDay[$d . ' 00:00:00'] ?? 0)])->values();

        // joiners per month, last 6 months
        $months = collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $joiners = $months->map(fn (Carbon $m) => [
            'month' => $m->format('M'),
            'joined' => $employees()->whereBetween('date_of_joining', [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()])->count(),
        ])->values();

        $lastPayroll = Payroll::where('created_by', $tenant)->whereIn('status', ['approved', 'paid'])->orderByDesc('month')->first(['month', 'total_net', 'status']);

        return Inertia::render('Hrm/Dashboard/Index', [
            'kpis' => $kpis,
            'byDepartment' => $byDepartment,
            'byGender' => $count('gender'),
            'byType' => $count('employment_type'),
            'attendanceTrend' => $attendanceTrend,
            'joiners' => $joiners,
            'lastPayroll' => $lastPayroll,
            'events' => Event::with('type:id,name,color')->where('created_by', $tenant)->whereDate('end_date', '>=', $today)->orderBy('start_date')->limit(5)->get(),
            'announcements' => Announcement::where('created_by', $tenant)->current()->latest('start_date')->limit(5)->get(['id', 'title', 'start_date']),
            'birthdays' => $this->birthdays($tenant),
        ]);
    }

    /** the next 30 days (active employees with a date of birth) */
    private function birthdays(int $tenant): array
    {
        $today = now()->startOfDay();

        return Employee::with('user:id,name')->where('created_by', $tenant)->where('status', 'active')->whereNotNull('date_of_birth')->get()
            ->map(function (Employee $e) use ($today) {
                $next = $e->date_of_birth->copy()->year($today->year);
                if ($next->lt($today)) {
                    $next->addYear();
                }

                return ['name' => $e->user->name, 'date' => $next->toDateString(), 'in_days' => (int) $today->diffInDays($next)];
            })
            ->filter(fn ($b) => $b['in_days'] <= 30)->sortBy('in_days')->take(8)->values()->all();
    }
}
