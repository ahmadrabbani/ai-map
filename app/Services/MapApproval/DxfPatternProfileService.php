<?php

namespace App\Services\MapApproval;

use App\Models\MapDrawing;

class DxfPatternProfileService
{
    public function profile(MapDrawing $drawing): array
    {
        $drawing->loadMissing('entities');

        $metadata = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
        $entities = $drawing->entities ?? collect();
        $textMetrics = is_array(data_get($metadata, 'cad_text_measurement_metrics'))
            ? (array) data_get($metadata, 'cad_text_measurement_metrics')
            : [];
        $roomAreas = is_array(data_get($metadata, 'cad_text_room_areas'))
            ? (array) data_get($metadata, 'cad_text_room_areas')
            : [];
        $textRefs = is_array(data_get($metadata, 'cad_text_references'))
            ? (array) data_get($metadata, 'cad_text_references')
            : [];

        $layerCounts = [];
        $entityTypeCounts = [];
        $textEntities = 0;
        $closedPolygons = 0;

        foreach ($entities as $entity) {
            $layer = $this->normalizeLayerName((string) ($entity->layer_name ?? 'unknown'));
            $layerCounts[$layer] = ($layerCounts[$layer] ?? 0) + 1;

            $type = strtoupper((string) ($entity->entity_type ?? 'UNKNOWN'));
            $entityTypeCounts[$type] = ($entityTypeCounts[$type] ?? 0) + 1;

            $text = trim((string) data_get($entity->geometry_json, 'text_content', ''));
            if ($text !== '') {
                $textEntities++;
            }
            if ((bool) ($entity->is_closed ?? false)) {
                $closedPolygons++;
            }
        }

        arsort($layerCounts);
        arsort($entityTypeCounts);

        $coreMetricCount = 0;
        foreach ([
            'plot_area',
            'ground_floor_covered',
            'total_floor_covered',
            'number_of_floors',
            'provided_height_ft',
            'front_setback_ft',
            'rear_setback_ft',
            'left_setback_ft',
            'right_setback_ft',
            'coverage_percent',
            'far',
        ] as $metricKey) {
            if (is_numeric($textMetrics[$metricKey] ?? null)) {
                $coreMetricCount++;
            }
        }

        $textRefCount = count(array_filter($textRefs, fn ($row) => is_array($row) && trim((string) data_get($row, 'text', '')) !== ''));
        $roomAreaCount = count(array_filter($roomAreas, fn ($row) => is_array($row) && is_numeric(data_get($row, 'area_sqft'))));
        $totalEntities = max(1, $entities->count());
        $textDensity = round($textEntities / $totalEntities, 4);
        $polygonDensity = round($closedPolygons / $totalEntities, 4);

        $patternFamily = 'generic_dxf';
        $patternStrength = 0.25;

        if ($coreMetricCount >= 6 && $roomAreaCount > 0) {
            $patternFamily = 'text_table_near_polygon';
            $patternStrength = 0.92;
        } elseif ($coreMetricCount >= 4 && $textRefCount >= 8) {
            $patternFamily = 'text_table_measurement_plan';
            $patternStrength = 0.84;
        } elseif ($polygonDensity > 0.45 && $textDensity > 0.15) {
            $patternFamily = 'mixed_polygon_text_plan';
            $patternStrength = 0.7;
        } elseif ($textRefCount >= 4 || $roomAreaCount > 0) {
            $patternFamily = 'text_enriched_plan';
            $patternStrength = 0.62;
        } elseif ($polygonDensity > 0.45) {
            $patternFamily = 'geometry_dominant_plan';
            $patternStrength = 0.52;
        }

        $signals = [
            'has_text_table' => $textRefCount >= 4,
            'has_room_pairs' => $roomAreaCount > 0,
            'has_boundary_polygon' => ! empty($this->layerCountMatch($layerCounts, ['plot boundary', 'boundary wall', 'site boundary'])),
            'has_footprint_polygons' => ! empty($this->layerCountMatch($layerCounts, ['external walls', 'ground floor', 'footprint'])),
            'has_dimension_layers' => ! empty($this->layerCountMatch($layerCounts, ['dimension', 'measurement', 'text general'])),
            'text_metric_count' => $coreMetricCount,
            'text_reference_count' => $textRefCount,
            'room_area_count' => $roomAreaCount,
            'text_density' => $textDensity,
            'polygon_density' => $polygonDensity,
        ];

        return [
            'pattern_family' => $patternFamily,
            'pattern_strength' => round($patternStrength, 4),
            'signals' => $signals,
            'layer_frequency' => array_slice($layerCounts, 0, 20, true),
            'entity_type_frequency' => array_slice($entityTypeCounts, 0, 20, true),
            'text_metric_count' => $coreMetricCount,
            'text_reference_count' => $textRefCount,
            'room_area_count' => $roomAreaCount,
            'text_density' => $textDensity,
            'polygon_density' => $polygonDensity,
            'recognized_at' => now()->toIso8601String(),
        ];
    }

    public function persist(MapDrawing $drawing, array $profile): void
    {
        $metadata = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
        $metadata['dxf_pattern_profile'] = $profile;
        $drawing->metadata_json = $metadata;
        $drawing->save();
    }

    private function normalizeLayerName(string $layerName): string
    {
        $normalized = strtolower(trim($layerName));
        $normalized = preg_replace('/^\d+\s*[\.\-_\):\s]+\s*/', '', $normalized) ?? $normalized;
        $normalized = str_replace(['_', '-', '.'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function layerCountMatch(array $layers, array $needles): array
    {
        $matches = [];
        foreach ($layers as $layer => $count) {
            foreach ($needles as $needle) {
                $needle = strtolower($needle);
                if ($needle !== '' && (str_contains($layer, $needle) || str_contains($needle, $layer))) {
                    $matches[$layer] = $count;
                    break;
                }
            }
        }

        return $matches;
    }
}
