<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadEntity extends Model
{
    protected $fillable = [
        'cad_submission_id',
        'handle',
        'layer_name',
        'normalized_layer_name',
        'entity_type',
        'geometry_type',
        'geometry_json',
        'bbox_json',
        'measurement_json',
        'text_content',
    ];

    protected $casts = [
        'geometry_json' => 'array',
        'bbox_json' => 'array',
        'measurement_json' => 'array',
    ];

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }

    public function mappings()
    {
        return $this->hasMany(CadLabelMapping::class, 'cad_entity_id');
    }
}
