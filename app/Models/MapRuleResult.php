<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapRuleResult extends Model
{
    protected $fillable = [
        'map_drawing_id',
        'rule_code',
        'rule_title',
        'required_value',
        'actual_value',
        'unit',
        'status',
        'message',
        'source_layers_json',
        'source_entities_json',
    ];

    protected $casts = [
        'source_layers_json' => 'array',
        'source_entities_json' => 'array',
    ];

    public function drawing()
    {
        return $this->belongsTo(MapDrawing::class, 'map_drawing_id');
    }
}

