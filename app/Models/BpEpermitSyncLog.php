<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BpEpermitSyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'bp_application_id',
        'sync_type',
        'endpoint',
        'request_payload_json',
        'response_status',
        'response_body',
        'is_success',
        'external_case_id',
        'external_application_no',
        'error_message',
        'synced_at',
    ];

    protected $casts = [
        'request_payload_json' => 'array',
        'is_success' => 'boolean',
        'synced_at' => 'datetime',
    ];
}
