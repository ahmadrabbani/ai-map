<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadTagAudit extends Model
{
    protected $fillable = [
        'cad_submission_id', 'cad_tag_id', 'cad_prediction_id', 'user_id',
        'action', 'before_json', 'after_json', 'remarks',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
    ];
}
