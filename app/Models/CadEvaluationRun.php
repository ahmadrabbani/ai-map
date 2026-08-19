<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadEvaluationRun extends Model
{
    protected $fillable = [
        'cad_submission_id', 'model_version_id', 'name', 'dataset_split',
        'locked_ground_truth', 'params', 'summary',
    ];

    protected $casts = [
        'locked_ground_truth' => 'boolean',
        'params' => 'array',
        'summary' => 'array',
    ];

    public function metrics()
    {
        return $this->hasMany(CadEvaluationMetric::class, 'evaluation_run_id');
    }
}
