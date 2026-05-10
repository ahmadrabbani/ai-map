<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CadApprovalApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'applicant_name',
        'identification_number',
        'contact_number',
        'mobile_number',
        'email',
        'application_type',
        'plot_number',
        'scheme',
        'phase',
        'block',
        'plot_size_category',
        'plot_area_sqft',
        'building_type',
        'property_type',
        'submitted_floors',
        'has_basement',
        'remarks',
        'verified_data_json',
        'verification_answers_json',
        'ruleset',
        'current_step',
        'status',
        'final_report_json',
        'final_report_pdf_path',
        'submitted_at',
        'draft_saved_at',
    ];

    protected $casts = [
        'plot_area_sqft' => 'decimal:2',
        'submitted_floors' => 'array',
        'has_basement' => 'boolean',
        'verified_data_json' => 'array',
        'verification_answers_json' => 'array',
        'final_report_json' => 'array',
        'submitted_at' => 'datetime',
        'draft_saved_at' => 'datetime',
    ];

    public function plans()
    {
        return $this->hasMany(CadApprovalPlan::class)->orderBy('id');
    }

    public function events()
    {
        return $this->hasMany(CadApprovalEvent::class)->orderByDesc('id');
    }

    public function expertMarkings()
    {
        return $this->hasMany(CadExpertMarking::class)->orderByDesc('id');
    }
}
