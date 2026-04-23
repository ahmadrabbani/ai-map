<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CadRuleResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'cad_submission_id',
        'source',
        'rule_id',
        'rule_type',
        'title',
        'required_value',
        'measured_value',
        'unit',
        'operator',
        'is_compliant',
        'details',
    ];

    protected $casts = [
        'is_compliant' => 'boolean',
    ];

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }
}
