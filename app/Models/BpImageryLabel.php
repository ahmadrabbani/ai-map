<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BpImageryLabel extends Model
{
    use HasFactory;

    protected $fillable = [
        'bp_application_id',
        'labeled_by_user_id',
        'label',
        'label_source',
        'notes',
        'labeled_at',
    ];

    protected $casts = [
        'labeled_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(BpApplication::class, 'bp_application_id');
    }

    public function labeledBy()
    {
        return $this->belongsTo(User::class, 'labeled_by_user_id');
    }
}
