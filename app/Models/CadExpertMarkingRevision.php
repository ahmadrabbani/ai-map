<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadExpertMarkingRevision extends Model
{
    protected $fillable = [
        'cad_expert_marking_id',
        'old_points_json',
        'old_measurement_json',
        'changed_by',
        'change_reason',
    ];

    protected $casts = [
        'old_points_json' => 'array',
        'old_measurement_json' => 'array',
    ];
}
