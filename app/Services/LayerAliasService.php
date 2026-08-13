<?php

namespace App\Services;

use App\Models\CadEntity;
use App\Models\LayerAlias;

class LayerAliasService
{
    public function normalize(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $value);
        $value = trim((string) $value);
        $value = preg_replace('/^\d+\s*[\.\-_\):\s]+\s*/', '', $value);
        $value = preg_replace('/[-_]+/', ' ', (string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return strtolower(trim((string) $value));
    }

    public function resolveOfficialLayer(string $rawLayerName): ?string
    {
        $norm = $this->normalize($rawLayerName);
        if ($norm === '') {
            return null;
        }

        $row = LayerAlias::query()
            ->where('is_active', true)
            ->where('alias_name_normalized', $norm)
            ->orderByDesc('hit_count')
            ->orderByDesc('confidence_score')
            ->first();

        return $row?->official_layer_name;
    }

    public function learnFromEntityMapping(?CadEntity $entity, string $labelKey, string $source = 'expert_mapping'): void
    {
        if (! $entity || ! $entity->layer_name) {
            return;
        }

        $alias = (string) $entity->layer_name;
        $aliasNorm = $this->normalize($alias);
        if ($aliasNorm === '') {
            return;
        }

        $official = $this->guessOfficialLayerName($alias, $labelKey);
        $officialNorm = $this->normalize($official);

        $row = LayerAlias::query()->firstOrNew([
            'alias_name_normalized' => $aliasNorm,
            'official_layer_name_normalized' => $officialNorm,
        ]);

        $row->alias_name = $alias;
        $row->official_layer_name = $official;
        $row->semantic_label = $labelKey;
        $row->source = $source;
        $row->is_active = true;
        $row->hit_count = (int) ($row->hit_count ?: 0) + 1;
        $row->confidence_score = min(100, max((int) ($row->confidence_score ?: 75), 85));
        $row->save();
    }

    private function guessOfficialLayerName(string $aliasLayer, string $labelKey): string
    {
        $byLabel = [
            'plot_boundary' => 'Plot Boundary',
            'ground_floor_covered_polygon' => 'External walls',
            'first_floor_covered_polygon' => 'External walls',
            'second_floor_covered_polygon' => 'External walls',
            'setback_lines' => 'Setback Line',
            'front_building_line' => 'Front building line',
            'annotations_and_dimensions' => 'Text General',
            'utilities' => 'Sewer line',
        ];

        return $byLabel[$labelKey] ?? $aliasLayer;
    }
}
