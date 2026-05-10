<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadLabelMapping extends Model
{
    protected $fillable = [
        'cad_submission_id',
        'label_key',
        'label_name',
        'cad_entity_id',
        'cad_handle',
        'source',
        'confidence',
        'user_confirmed',
    ];

    protected $casts = [
        'confidence' => 'float',
        'user_confirmed' => 'boolean',
    ];

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }

    public function entity()
    {
        return $this->belongsTo(CadEntity::class, 'cad_entity_id');
    }
}
