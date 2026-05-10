<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CadExpertMarking extends Model
{
    use HasFactory;

    protected $fillable = [
        'cad_submission_id',
        'label_key',
        'label_name',
        'geometry_type',
        'points_json',
        'measurement_json',
        'status',
        'source',
        'updated_by',
        'cad_approval_application_id',
        'cad_approval_plan_id',
        'floor_type',
        'marking_type',
        'geometry_json',
        'measured_area',
        'measured_length',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'geometry_json' => 'array',
        'points_json' => 'array',
        'measurement_json' => 'array',
    ];

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }

    public function application()
    {
        return $this->belongsTo(CadApprovalApplication::class, 'cad_approval_application_id');
    }

    public function plan()
    {
        return $this->belongsTo(CadApprovalPlan::class, 'cad_approval_plan_id');
    }
}
