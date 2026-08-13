<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DfpsPushLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'pushed_by_user_id',
        'endpoint_url',
        'request_payload_json',
        'zip_file_path',
        'response_status',
        'response_body',
        'success',
        'error_message',
    ];

    protected $casts = [
        'request_payload_json' => 'array',
        'success' => 'boolean',
    ];

    public function application()
    {
        return $this->belongsTo(PublicBuildingPlanApplication::class, 'application_id');
    }
}
