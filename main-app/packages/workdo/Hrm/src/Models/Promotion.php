<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promotion extends Model
{
    protected $fillable = ['employee_id', 'previous_designation_id', 'designation_id', 'title', 'promotion_date', 'notes', 'creator_id', 'created_by'];

    protected function casts(): array
    {
        return ['promotion_date' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'designation_id');
    }

    public function previousDesignation(): BelongsTo
    {
        return $this->belongsTo(Designation::class, 'previous_designation_id');
    }
}
