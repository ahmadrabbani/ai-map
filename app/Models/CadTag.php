<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CadTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'cad_submission_id', 'cad_prediction_id', 'label_key', 'label_name',
        'geometry_type', 'geometry_json', 'attributes', 'cad_handles', 'cad_layer',
        'floor', 'width', 'length', 'perimeter', 'area_sq_ft', 'area_sq_m',
        'is_closed', 'unit', 'scale', 'unit_confirmed', 'status',
        'verification_level', 'source', 'ai_label_key', 'ai_confidence',
        'model_version', 'dataset_split', 'drawing_hash', 'locked', 'remarks',
        'created_by', 'updated_by', 'verified_by', 'gold_verified_by',
        'verified_at', 'gold_verified_at',
    ];

    protected $casts = [
        'geometry_json' => 'array',
        'attributes' => 'array',
        'cad_handles' => 'array',
        'is_closed' => 'boolean',
        'unit_confirmed' => 'boolean',
        'locked' => 'boolean',
        'verified_at' => 'datetime',
        'gold_verified_at' => 'datetime',
    ];

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }

    public function prediction()
    {
        return $this->belongsTo(CadPrediction::class, 'cad_prediction_id');
    }

    public function audits()
    {
        return $this->hasMany(CadTagAudit::class, 'cad_tag_id')->orderByDesc('id');
    }
}
