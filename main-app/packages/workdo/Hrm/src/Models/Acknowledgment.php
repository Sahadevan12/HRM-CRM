<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;

class Acknowledgment extends Model
{
    public const KINDS = ['announcement', 'document'];

    public $timestamps = false;

    protected $fillable = ['kind', 'ref_id', 'employee_id', 'acknowledged_at', 'created_by'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }
}
