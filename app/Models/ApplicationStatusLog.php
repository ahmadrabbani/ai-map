<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationStatusLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'action_by_user_id',
        'action_by_role',
        'old_status',
        'new_status',
        'remarks',
        'payload_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo(PublicBuildingPlanApplication::class, 'application_id');
    }
}
