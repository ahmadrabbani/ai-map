<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BpChatBrain extends Model
{
    use HasFactory;

    protected $fillable = [
        'bp_application_id',
        'learning_summary',
        'memory_json',
        'last_learned_at',
    ];

    protected $casts = [
        'memory_json' => 'array',
        'last_learned_at' => 'datetime',
    ];
}
