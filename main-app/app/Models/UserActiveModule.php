<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserActiveModule extends Model
{
    protected $fillable = ['user_id', 'module'];
}
