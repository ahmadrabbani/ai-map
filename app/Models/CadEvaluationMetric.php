<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadEvaluationMetric extends Model
{
    protected $fillable = ['evaluation_run_id', 'metric_scope', 'entity_type', 'metrics'];

    protected $casts = ['metrics' => 'array'];

    public function run()
    {
        return $this->belongsTo(CadEvaluationRun::class, 'evaluation_run_id');
    }
}
