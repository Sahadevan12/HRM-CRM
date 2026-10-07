<?php

namespace Workdo\Lead\Models;

use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    protected $fillable = ['name', 'creator_id', 'created_by'];
}
