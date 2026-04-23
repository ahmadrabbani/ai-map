<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadEntityFeature extends Model
{
    protected $table = 'cad_entity_features';

    protected $fillable = [
        'cad_submission_id','entity_handle','entity_type','layer',
        'is_closed','num_vertices','area',
        'bbox_x0','bbox_y0','bbox_x1','bbox_y1','bbox_w','bbox_h',
        'rectangularity','centroid_x','centroid_y','points_xy'
    ];

    protected $casts = [
        'is_closed' => 'boolean',
        'points_xy' => 'array',
    ];

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }
}
