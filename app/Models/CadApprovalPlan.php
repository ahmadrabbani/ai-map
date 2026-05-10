<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CadApprovalPlan extends Model
{
    use HasFactory;

    public const FLOOR_TYPES = [
        'basement',
        'ground',
        'first',
        'second',
        'roof',
        'site',
        'services',
    ];

    protected $fillable = [
        'cad_approval_application_id',
        'cad_submission_id',
        'floor_type',
        'label',
        'is_required',
        'is_uploaded',
        'status',
        'original_file_path',
        'uploaded_extension',
        'overlay_pdf_path',
        'drawing_pdf_path',
        'analysis_result',
        'layer_validation_json',
        'detected_layers_json',
        'confidence_score',
        'expert_notes',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_uploaded' => 'boolean',
        'analysis_result' => 'array',
        'layer_validation_json' => 'array',
        'detected_layers_json' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo(CadApprovalApplication::class, 'cad_approval_application_id');
    }

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }

    public function events()
    {
        return $this->hasMany(CadApprovalEvent::class, 'cad_approval_plan_id')->orderByDesc('id');
    }

    public function expertMarkings()
    {
        return $this->hasMany(CadExpertMarking::class, 'cad_approval_plan_id')->orderByDesc('id');
    }
}
