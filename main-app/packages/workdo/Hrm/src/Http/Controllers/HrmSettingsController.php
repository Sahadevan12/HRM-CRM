<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Hrm\Services\AttendanceService;
use Workdo\Hrm\Services\WorkCalendar;

/** Company wide HR rules: the weekly working days and the late grace period. */
class HrmSettingsController extends Controller
{
    public function edit(): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-hrm-settings')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();

        return Inertia::render('Hrm/Settings/Index', [
            'workingDays' => WorkCalendar::for($tenant)->weekdays(),
            'lateGraceMinutes' => (int) (tenantSettings($tenant)['hrmLateGraceMinutes'] ?? AttendanceService::DEFAULT_GRACE),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('manage-hrm-settings')) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validate([
            'working_days' => 'required|array|min:1|max:7',
            'working_days.*' => 'integer|between:1,7|distinct',
            'late_grace_minutes' => 'required|integer|between:0,180',
        ]);

        $days = collect($data['working_days'])->map(fn ($d) => (int) $d)->sort()->values()->all();
        setSetting('hrmWorkingDays', $days, null, false);
        setSetting('hrmLateGraceMinutes', $data['late_grace_minutes'], null, false);

        return back()->with('success', __('The HR settings have been saved.'));
    }
}
