<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadTrainingLabel extends Model
{
    protected $table = 'cad_training_labels';

    protected $fillable = [
        'cad_submission_id',
        'plot_boundary_handle','building_footprint_handle','floor_handles','front_side',
        'layer_map','notes','verified_by','verified_at'
    ];

    protected $casts = [
        'layer_map' => 'array',
        'floor_handles' => 'array',
        'verified_at' => 'datetime',
    ];

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }
}
