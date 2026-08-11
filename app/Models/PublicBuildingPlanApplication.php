<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PublicBuildingPlanApplication extends Model
{
    use HasFactory;

    protected $table = 'building_plan_applications';

    protected $fillable = [
        'application_no',
        'legacy_bp_application_id',
        'user_id',
        'applicant_name',
        'applicant_cnic',
        'applicant_email',
        'applicant_phone',
        'scheme',
        'scheme_id',
        'scheme_name',
        'phase',
        'block',
        'block_id',
        'block_name',
        'plot_ref',
        'plot_no',
        'plot_area',
        'selected_address',
        'plot_address',
        'plan_file_path',
        'cad_file_path',
        'list_document_path',
        'ownership_document_path',
        'cnic_front_path',
        'cnic_back_path',
        'affidavit_path',
        'status',
        'current_status',
        'ai_status',
        'ai_report_json',
        'ai_report_path',
        'qr_code_path',
        'submitted_at',
        'reviewed_at',
        'routed_to',
        'ad_epermit_decision',
        'ad_epermit_remarks',
    ];

    protected $casts = [
        'ai_report_json' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'plot_area' => 'float',
    ];

    public const PUBLIC_STATUSES = [
        'draft',
        'submitted_to_ad_epermit',
        'under_review',
        'observation_marked',
        'rejected_by_ad_epermit',
        'approved_by_ad_epermit',
        'pushed_to_dfps',
        'dfps_push_failed',
    ];

    public function applicant()
    {
        return $this->belongsTo(Applicant::class, 'user_id');
    }

    public function legacyBpApplication()
    {
        return $this->belongsTo(BpApplication::class, 'legacy_bp_application_id');
    }

    public function documents()
    {
        return $this->hasMany(ApplicationDocument::class, 'application_id');
    }

    public function chatMessages()
    {
        return $this->hasMany(PublicBpChatMessage::class, 'application_id')->orderBy('id');
    }

    public function statusLogs()
    {
        return $this->hasMany(ApplicationStatusLog::class, 'application_id')->orderBy('id');
    }

    public function siteReview()
    {
        return $this->hasOne(ApplicationSiteReview::class, 'application_id');
    }

    public function dfpsPushLogs()
    {
        return $this->hasMany(DfpsPushLog::class, 'application_id')->orderByDesc('id');
    }
}
