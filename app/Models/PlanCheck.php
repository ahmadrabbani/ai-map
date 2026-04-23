<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanCheck extends Model
{
    use HasFactory;

    protected $table = 'plan_checks';

    protected $fillable = [
        'original_filename',
        'stored_path',
        'required_setback_ft',
        'global_min_setback_ft',
        'left_setback_ft',
        'right_setback_ft',
        'meets_requirement',
        'raw_result',
    ];

    protected $casts = [
        'raw_result' => 'array',
        'meets_requirement' => 'boolean',
    ];
}
