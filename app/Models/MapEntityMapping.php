<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapEntityMapping extends Model
{
    protected $fillable = [
        'map_drawing_id',
        'semantic_entity',
        'entity_handle',
        'mapping_source',
        'confidence_score',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'confidence_score' => 'float',
    ];

    public function drawing()
    {
        return $this->belongsTo(MapDrawing::class, 'map_drawing_id');
    }
}

