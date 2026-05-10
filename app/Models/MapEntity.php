<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapEntity extends Model
{
    protected $fillable = [
        'map_drawing_id',
        'handle',
        'layer_name',
        'entity_type',
        'semantic_entity',
        'processing_role',
        'geometry_json',
        'bbox_json',
        'area',
        'perimeter',
        'is_closed',
        'confidence_score',
        'mapping_status',
        'mapping_source',
        'is_ignored',
    ];

    protected $casts = [
        'geometry_json' => 'array',
        'bbox_json' => 'array',
        'is_closed' => 'boolean',
        'is_ignored' => 'boolean',
        'area' => 'float',
        'perimeter' => 'float',
        'confidence_score' => 'float',
    ];

    public function drawing()
    {
        return $this->belongsTo(MapDrawing::class, 'map_drawing_id');
    }
}

