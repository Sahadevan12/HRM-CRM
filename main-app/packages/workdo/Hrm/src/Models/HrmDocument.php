<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;

class HrmDocument extends Model
{
    protected $table = 'hrm_documents';

    protected $fillable = ['title', 'description', 'file_path', 'file_name', 'file_size', 'requires_acknowledgment', 'creator_id', 'created_by'];

    /** the storage path is internal */
    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'requires_acknowledgment' => 'boolean'];
    }
}
