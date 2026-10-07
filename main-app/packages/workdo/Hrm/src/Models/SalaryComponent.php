<?php

namespace Workdo\Hrm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryComponent extends Model
{
    protected $table = 'salary_components';

    protected $fillable = [
        'name',
        'type',
        'calc',
        'amount',
        'description',
        'creator_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

}
