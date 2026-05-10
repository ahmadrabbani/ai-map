<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapGeometryResult extends Model
{
    protected $fillable = [
        'map_drawing_id',
        'key',
        'value',
        'unit',
        'source_semantic_entities_json',
        'calculation_method',
        'status',
    ];

    protected $casts = [
        'source_semantic_entities_json' => 'array',
    ];

    public function drawing()
    {
        return $this->belongsTo(MapDrawing::class, 'map_drawing_id');
    }
}

