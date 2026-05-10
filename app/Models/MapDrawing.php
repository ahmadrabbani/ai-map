<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapDrawing extends Model
{
    protected $fillable = [
        'application_id',
        'original_file_path',
        'dxf_file_path',
        'status',
        'mapping_status',
        'validation_status',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function entities()
    {
        return $this->hasMany(MapEntity::class);
    }

    public function entityMappings()
    {
        return $this->hasMany(MapEntityMapping::class);
    }

    public function geometryResults()
    {
        return $this->hasMany(MapGeometryResult::class);
    }

    public function ruleResults()
    {
        return $this->hasMany(MapRuleResult::class);
    }
}

