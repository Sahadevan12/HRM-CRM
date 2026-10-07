<?php

namespace Workdo\Lead\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    protected $fillable = ['name', 'creator_id', 'created_by'];

    public function leadStages(): HasMany
    {
        return $this->hasMany(LeadStage::class)->orderBy('order');
    }

    public function dealStages(): HasMany
    {
        return $this->hasMany(DealStage::class)->orderBy('order');
    }
}
