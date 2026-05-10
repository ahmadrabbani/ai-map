<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BpAiReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'bp_application_id',
        'analysis_status',
        'ai_recommendation',
        'ai_confidence_score',
        'analysis_json',
        'report_markdown',
        'report_html',
        'detected_layers_json',
        'detected_entities_json',
        'rule_results_json',
        'warnings_json',
        'expert_review_items_json',
        'generated_at',
    ];

    protected $casts = [
        'analysis_json' => 'array',
        'detected_layers_json' => 'array',
        'detected_entities_json' => 'array',
        'rule_results_json' => 'array',
        'warnings_json' => 'array',
        'expert_review_items_json' => 'array',
        'generated_at' => 'datetime',
        'ai_confidence_score' => 'float',
    ];

    public function application()
    {
        return $this->belongsTo(BpApplication::class, 'bp_application_id');
    }
}
