<?php

namespace Workdo\Lead\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealFile extends Model
{
    protected $fillable = ['deal_id', 'file_name', 'file_path', 'file_size', 'creator_id', 'created_by'];

    /** the storage path is internal */
    protected $hidden = ['file_path'];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
