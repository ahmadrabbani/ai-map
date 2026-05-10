<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CadApprovalEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'cad_approval_application_id',
        'cad_approval_plan_id',
        'event_type',
        'message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo(CadApprovalApplication::class, 'cad_approval_application_id');
    }

    public function plan()
    {
        return $this->belongsTo(CadApprovalPlan::class, 'cad_approval_plan_id');
    }
}
