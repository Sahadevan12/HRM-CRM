<?php

namespace Workdo\Hrm\Services;

use Illuminate\Support\Carbon;
use Workdo\Hrm\Models\Holiday;

/**
 * Which days count as working days of a company: the weekly pattern (setting `hrmWorkingDays`, ISO weekdays 1 = Monday
 * ... 7 = Sunday; default Monday-Saturday) minus the company holidays. Attendance, leave and payroll all use this.
 */
class WorkCalendar
{
    public const DEFAULT_WEEKDAYS = [1, 2, 3, 4, 5, 6];

    /** @var array<string, array<string, string>> holidays per requested range */
    private array $holidayCache = [];

    private ?array $weekdays = null;

    public function __construct(private int $tenantId)
    {
    }

    public static function for(int $tenantId): self
    {
        return new self($tenantId);
    }

    /** @return array<int, int> ISO weekday numbers that are working days */
    public function weekdays(): array
    {
        if ($this->weekdays !== null) {
            return $this->weekdays;
        }

        $stored = json_decode((string) (tenantSettings($this->tenantId)['hrmWorkingDays'] ?? ''), true);
        $days = is_array($stored) ? array_values(array_unique(array_filter(array_map('intval', $stored), fn ($d) => $d >= 1 && $d <= 7))) : [];

        return $this->weekdays = $days ?: self::DEFAULT_WEEKDAYS;
    }

    /** @return array<string, string> [Y-m-d => holiday name] for every holiday day inside the range */
    public function holidays(string $from, string $to): array
    {
        return $this->holidayCache["{$from}|{$to}"] ??= $this->loadHolidays($from, $to);
    }

    public function isWorkingDay(string $date): bool
    {
        return in_array(Carbon::parse($date)->isoWeekday(), $this->weekdays(), true) && !isset($this->holidays($date, $date)[$date]);
    }

    /** @return array<int, string> every working day (Y-m-d) of the range, both ends included */
    public function workingDays(string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }

        $holidays = $this->holidays($from, $to);
        $days = [];
        for ($day = Carbon::parse($from); $day->toDateString() <= $to; $day->addDay()) {
            if (in_array($day->isoWeekday(), $this->weekdays(), true) && !isset($holidays[$day->toDateString()])) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    public function countWorkingDays(string $from, string $to): int
    {
        return count($this->workingDays($from, $to));
    }

    /** @return array<string, string> */
    private function loadHolidays(string $from, string $to): array
    {
        $out = [];
        foreach (Holiday::where('created_by', $this->tenantId)->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get() as $holiday) {
            for ($day = Carbon::parse(max($holiday->start_date->toDateString(), $from)); $day->toDateString() <= min($holiday->end_date->toDateString(), $to); $day->addDay()) {
                $out[$day->toDateString()] = $holiday->name;
            }
        }

        return $out;
    }
}
