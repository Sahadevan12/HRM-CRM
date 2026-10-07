<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Announcement extends Model
{
    protected $fillable = ['title', 'announcement_category_id', 'body', 'start_date', 'end_date', 'requires_acknowledgment', 'creator_id', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'requires_acknowledgment' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AnnouncementCategory::class, 'announcement_category_id');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'announcement_department');
    }

    /** running today: started and not yet ended */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->whereDate('start_date', '<=', now()->toDateString())->where(fn ($w) => $w->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString()));
    }

    /** meant for this employee: no department list at all, or the employee's department is on it */
    public function scopeVisibleTo(Builder $q, Employee $employee): Builder
    {
        return $q->where(fn ($w) => $w->whereDoesntHave('departments')
            ->when($employee->department_id, fn ($x) => $x->orWhereHas('departments', fn ($d) => $d->whereKey($employee->department_id))));
    }
}
