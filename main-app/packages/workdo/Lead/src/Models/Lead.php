<?php

namespace Workdo\Lead\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'subject', 'name', 'email', 'phone', 'notes', 'follow_up_date', 'pipeline_id', 'lead_stage_id', 'order', 'is_active', 'is_converted',
        'creator_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['follow_up_date' => 'date:Y-m-d', 'is_active' => 'boolean', 'is_converted' => 'boolean'];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(LeadStage::class, 'lead_stage_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_leads');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivityLog::class)->latest('id');
    }

    /** The company owner (and users with `view-all-leads`) see every lead, everybody else only the leads they created or work on. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->id === creatorId() || $user->can('view-all-leads')) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('leads.creator_id', $user->id)->orWhereHas('users', fn ($u) => $u->whereKey($user->id)));
    }
}
