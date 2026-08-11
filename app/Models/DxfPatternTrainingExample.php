<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DxfPatternTrainingExample extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_plan_application_id',
        'legacy_bp_application_id',
        'bp_ai_report_id',
        'cad_submission_id',
        'map_drawing_id',
        'ad_status_log_id',
        'ai_recommendation',
        'ai_confidence_score',
        'ad_decision',
        'ad_outcome',
        'ad_status',
        'ad_remarks',
        'dxf_pattern_profile_json',
        'cad_confidence_assessment_json',
        'rule_results_json',
        'feature_snapshot_json',
        'label_source',
        'captured_at',
    ];

    protected $casts = [
        'ai_confidence_score' => 'float',
        'dxf_pattern_profile_json' => 'array',
        'cad_confidence_assessment_json' => 'array',
        'rule_results_json' => 'array',
        'feature_snapshot_json' => 'array',
        'captured_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(PublicBuildingPlanApplication::class, 'building_plan_application_id');
    }

    public function legacyApplication()
    {
        return $this->belongsTo(BpApplication::class, 'legacy_bp_application_id');
    }
}
