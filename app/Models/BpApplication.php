<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BpApplication extends Model
{
    use HasFactory;

    public const STATUSES = [
        'Draft',
        'Uploaded',
        'AI Analysis In Progress',
        'AI Report Generated',
        'User Chat Completed',
        'Submitted to AD ePermit',
        'Under AD ePermit Review',
        'Forwarded to DDTP',
        'Under DDTP Review',
        'Approved',
        'Rejected',
        'Returned for Correction',
        'Needs Expert Review',
    ];

    protected $fillable = [
        'application_number',
        'status',
        'applicant_name',
        'applicant_email',
        'applicant_phone',
        'uploaded_file_name',
        'uploaded_file_path',
        'uploaded_file_type',
        'uploaded_file_size',
        'metadata_doc_name',
        'metadata_doc_path',
        'applicant_data_json',
        'plot_data_json',
        'layer_table_json',
        'qr_token',
        'verification_url',
        'qr_code_url',
        'cad_submission_id',
        'map_drawing_id',
        'submitted_to_ad_at',
        'forwarded_to_ddtp_at',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'submitted_to_ad_at' => 'datetime',
        'forwarded_to_ddtp_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'applicant_data_json' => 'array',
        'plot_data_json' => 'array',
        'layer_table_json' => 'array',
    ];

    public function aiReport()
    {
        return $this->hasOne(BpAiReport::class, 'bp_application_id');
    }

    public function chatMessages()
    {
        return $this->hasMany(BpChatMessage::class, 'bp_application_id')->orderBy('id');
    }

    public function reviewLogs()
    {
        return $this->hasMany(BpReviewLog::class, 'bp_application_id')->orderBy('id');
    }

    public function cadSubmission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }

    public function mapDrawing()
    {
        return $this->belongsTo(MapDrawing::class, 'map_drawing_id');
    }
}
