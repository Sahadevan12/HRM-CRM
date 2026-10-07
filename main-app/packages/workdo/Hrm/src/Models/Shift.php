<?php

namespace Workdo\Hrm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    protected $table = 'shifts';

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'is_night_shift',
        'creator_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'is_night_shift' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

}
