<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadExpertLabel extends Model
{
    protected $fillable = [
        'cad_submission_id',
        'plot_layer',
        'building_layer',
        'dimension_layer',
        'text_layer',
        'plot_entity_handle',
        'building_entity_handle',
        'front_side',
        'notes',
        'labeled_by',
        'layer_map_json',
    ];

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }
}
