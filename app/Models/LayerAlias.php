<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LayerAlias extends Model
{
    protected $fillable = [
        'alias_name',
        'alias_name_normalized',
        'official_layer_name',
        'official_layer_name_normalized',
        'semantic_label',
        'hit_count',
        'confidence_score',
        'is_active',
        'source',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'hit_count' => 'integer',
        'confidence_score' => 'integer',
    ];
}
