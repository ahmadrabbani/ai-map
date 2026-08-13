<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationSiteReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'reviewer_id',
        'latitude',
        'longitude',
        'site_condition',
        'front_road_detected',
        'side_road_detected',
        'corner_plot',
        'remarks',
        'site_review_json',
    ];

    protected $casts = [
        'front_road_detected' => 'boolean',
        'side_road_detected' => 'boolean',
        'corner_plot' => 'boolean',
        'site_review_json' => 'array',
    ];

    public function application()
    {
        return $this->belongsTo(PublicBuildingPlanApplication::class, 'application_id');
    }
}
