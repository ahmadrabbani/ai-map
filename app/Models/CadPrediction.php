<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadPrediction extends Model
{
    protected $fillable = [
        'cad_submission_id', 'label_key', 'label_name', 'geometry_type',
        'geometry_json', 'confidence', 'model_version', 'cad_handle', 'cad_layer',
        'floor', 'status', 'final_label_key', 'review_action', 'reviewed_by',
        'reviewed_at', 'metadata',
    ];

    protected $casts = [
        'geometry_json' => 'array',
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }

    public function tag()
    {
        return $this->hasOne(CadTag::class, 'cad_prediction_id');
    }
}
