<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PublicBpChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'role',
        'message',
        'context_json',
        'sent_at',
    ];

    protected $casts = [
        'context_json' => 'array',
        'sent_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(PublicBuildingPlanApplication::class, 'application_id');
    }
}
