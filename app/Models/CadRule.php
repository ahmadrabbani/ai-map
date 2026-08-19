<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadRule extends Model
{
    protected $fillable = ['rule_code','name','entity_type','operator','value','unit','severity','active','effective_from','effective_to','applies_to'];

    protected $casts = ['active' => 'boolean','applies_to' => 'array','effective_from' => 'date','effective_to' => 'date'];
}
