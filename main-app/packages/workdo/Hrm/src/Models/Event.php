<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Event extends Model
{
    protected $fillable = ['title', 'event_type_id', 'start_date', 'end_date', 'start_time', 'location', 'description', 'creator_id', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EventType::class, 'event_type_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'event_department');
    }

    public function scopeVisibleTo(Builder $q, Employee $employee): Builder
    {
        return $q->where(fn ($w) => $w->whereDoesntHave('departments')
            ->when($employee->department_id, fn ($x) => $x->orWhereHas('departments', fn ($d) => $d->whereKey($employee->department_id))));
    }

    /** overlaps the given date range */
    public function scopeBetween(Builder $q, string $from, string $to): Builder
    {
        return $q->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from);
    }
}
