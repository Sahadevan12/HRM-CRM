<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const STATUSES = ['present', 'half_day', 'absent'];

    protected $table = 'attendances';

    protected $fillable = [
        'employee_id', 'shift_id', 'date', 'clock_in', 'clock_out', 'total_hours', 'overtime_hours', 'late_minutes',
        'status', 'source', 'ip_address', 'notes', 'creator_id', 'created_by',
    ];

    /** clock times as H:i in the company's time zone (the columns themselves are stored in the app time zone) */
    protected $appends = ['in_time', 'out_time'];

    public function getInTimeAttribute(): ?string
    {
        return $this->localTime($this->clock_in);
    }

    public function getOutTimeAttribute(): ?string
    {
        return $this->localTime($this->clock_out);
    }

    private function localTime(?\Illuminate\Support\Carbon $at): ?string
    {
        return $at?->copy()->setTimezone(app(\Workdo\Hrm\Services\AttendanceService::class)->tz($this->created_by))->format('H:i');
    }

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'clock_in' => 'datetime',
            'clock_out' => 'datetime',
            'total_hours' => 'float',
            'overtime_hours' => 'float',
            'late_minutes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Clocked in but not out yet. */
    public function isOpen(): bool
    {
        return $this->clock_in !== null && $this->clock_out === null;
    }
}
