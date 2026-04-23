<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CadSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_filename',
        'stored_dwg_path',
        'stored_dxf_path',
        'overlay_pdf_path',
        'drawing_pdf_path',
        'ruleset_key',
        'analysis_result',
    ];

    protected $casts = [
        'analysis_result' => 'array',
    ];

    public function ruleResults()
    {
        return $this->hasMany(CadRuleResult::class);
    }

    public function entityFeatures()
    {
        return $this->hasMany(CadEntityFeature::class, 'cad_submission_id');
    }

    public function trainingLabel()
    {
        return $this->hasOne(CadTrainingLabel::class, 'cad_submission_id');
    }

    public function expertLabel()
    {
        return $this->hasOne(CadExpertLabel::class, 'cad_submission_id');
    }
}
