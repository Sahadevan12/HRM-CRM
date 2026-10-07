<?php

namespace Workdo\Hrm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventType extends Model
{
    protected $table = 'event_types';

    protected $fillable = [
        'name',
        'color',
        'creator_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [

        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

}
