<?php

namespace Workdo\Lead\Models;

use Illuminate\Database\Eloquent\Model;

class CrmPreference extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $fillable = ['user_id', 'default_pipeline_id'];
}
