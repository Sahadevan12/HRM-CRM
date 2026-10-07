<?php

namespace Workdo\Hrm\Services;

use Workdo\Hrm\Models\AnnouncementCategory;
use Workdo\Hrm\Models\AwardType;
use Workdo\Hrm\Models\EmployeeDocumentType;
use Workdo\Hrm\Models\EventType;
use Workdo\Hrm\Models\LeaveType;
use Workdo\Hrm\Models\Shift;

/**
 * Starter lists of a new company (leave types, shift, document / award / event types, announcement categories).
 * Done ONCE per company (setting `hrmDefaultsSeeded`) the first time the HRM dashboard is opened, so a company that deletes a default
 * never gets it back. Existing rows with the same name are left alone.
 */
class DefaultData
{
    private const FLAG = 'hrmDefaultsSeeded';

    public function ensure(int $tenantId): bool
    {
        if ((tenantSettings($tenantId)[self::FLAG] ?? null) === '1') {
            return false;
        }

        $own = ['creator_id' => $tenantId, 'created_by' => $tenantId];
        $make = fn (string $model, array $rows) => collect($rows)->each(fn (array $row) => $model::firstOrCreate(['created_by' => $tenantId, 'name' => $row['name']], $row + $own));

        $make(LeaveType::class, [
            ['name' => 'Casual Leave', 'days_per_year' => 12, 'is_paid' => true],
            ['name' => 'Sick Leave', 'days_per_year' => 12, 'is_paid' => true],
            ['name' => 'Unpaid Leave', 'days_per_year' => 0, 'is_paid' => false],
        ]);
        $make(Shift::class, [['name' => 'General Shift', 'start_time' => '09:00', 'end_time' => '18:00', 'break_minutes' => 60, 'is_night_shift' => false]]);
        $make(EmployeeDocumentType::class, [
            ['name' => 'ID Proof', 'is_required' => true], ['name' => 'Address Proof', 'is_required' => false], ['name' => 'Resume', 'is_required' => false], ['name' => 'Contract', 'is_required' => true],
        ]);
        $make(AwardType::class, [['name' => 'Employee of the Month'], ['name' => 'Best Performer']]);
        $make(AnnouncementCategory::class, [['name' => 'General'], ['name' => 'Policy'], ['name' => 'Celebration']]);
        $make(EventType::class, [['name' => 'Meeting', 'color' => '#2563eb'], ['name' => 'Training', 'color' => '#16a34a'], ['name' => 'Celebration', 'color' => '#db2777']]);

        setSetting(self::FLAG, '1', $tenantId, false);

        return true;
    }
}
