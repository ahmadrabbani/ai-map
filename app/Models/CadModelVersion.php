<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadModelVersion extends Model
{
    protected $fillable = ['name','commit_hash','metadata'];

    protected $casts = ['metadata' => 'array'];
}
