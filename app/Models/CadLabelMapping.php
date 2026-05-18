<?php

namespace App\Models;

use App\Services\LayerAliasService;
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



    protected static function booted(): void
    {
        static::created(function (CadLabelMapping $mapping): void {
            if (! $mapping->user_confirmed) {
                return;
            }

            try {
                $entity = $mapping->entity;
                app(LayerAliasService::class)->learnFromEntityMapping($entity, (string) $mapping->label_key, (string) ($mapping->source ?: 'expert_mapping'));
            } catch (\Throwable $e) {
                // keep mapping flow stable
            }
        });
    }

    public function submission()
    {
        return $this->belongsTo(CadSubmission::class, 'cad_submission_id');
    }

    public function entity()
    {
        return $this->belongsTo(CadEntity::class, 'cad_entity_id');
    }
}
