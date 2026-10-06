<?php

namespace Workdo\Notes\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    protected $table = 'notes';

    protected $fillable = [
        'title',
        'body',
        'priority',
        'budget',
        'due_on',
        'is_pinned',
        'creator_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'budget' => 'float',
            'due_on' => 'date:Y-m-d',
            'is_pinned' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
