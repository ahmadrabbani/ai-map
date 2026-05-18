<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'document_type',
        'attachment_type',
        'original_name',
        'file_path',
        'mime_type',
        'size',
        'validation_status',
        'validation_message',
    ];

    public function application()
    {
        return $this->belongsTo(PublicBuildingPlanApplication::class, 'application_id');
    }
}
