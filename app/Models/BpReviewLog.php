<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BpReviewLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'bp_application_id',
        'actor_type',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'remarks',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo(BpApplication::class, 'bp_application_id');
    }
}
