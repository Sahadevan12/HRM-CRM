<?php

namespace Workdo\Lead\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deal extends Model
{
    protected $fillable = [
        'name', 'price', 'phone', 'notes', 'pipeline_id', 'deal_stage_id', 'order', 'status', 'is_active', 'lead_id', 'creator_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['price' => 'float', 'is_active' => 'boolean'];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'deal_stage_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_deals');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_deals');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'deal_sources');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'deal_labels');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(\Workdo\ProductService\Models\Product::class, 'deal_products');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(DealTask::class)->orderBy('due_date');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(DealCall::class)->latest('id');
    }

    public function emails(): HasMany
    {
        return $this->hasMany(DealEmail::class)->latest('id');
    }

    public function discussions(): HasMany
    {
        return $this->hasMany(DealDiscussion::class)->latest('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DealFile::class)->latest('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(DealActivityLog::class)->latest('id');
    }

    public const STATUSES = ['active', 'won', 'lost'];

    /** The company owner (and users with `view-all-deals`) see every deal, everybody else only the deals they created or work on. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->id === creatorId() || $user->can('view-all-deals')) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('deals.creator_id', $user->id)->orWhereHas('users', fn ($u) => $u->whereKey($user->id)));
    }
}
