<?php

namespace Modules\Lead\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    protected $table = 'crm_workflows';

    protected $fillable = ['name', 'module', 'trigger', 'match', 'conditions', 'actions', 'is_active', 'creator_id', 'created_by'];

    /** @var array<string, string[]> the events a rule can start from, per record type */
    public const TRIGGERS = [
        'lead' => ['created', 'stage_changed'],
        'deal' => ['created', 'stage_changed', 'won', 'lost'],
    ];

    protected function casts(): array
    {
        return ['conditions' => 'array', 'actions' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class, 'workflow_id');
    }
}
