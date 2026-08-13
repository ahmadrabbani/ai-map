<?php

namespace App\Services\MapApproval;

use App\Models\MapDrawing;
use App\Services\LayerAliasService;

class CadConfidenceEvaluatorService
{
    public function __construct(
        private readonly RuleToLayerSchemaService $schemaService,
        private readonly LayerAliasService $layerAliasService,
    ) {
    }

    public function evaluate(MapDrawing $drawing): array
    {
        $drawing->loadMissing(['entities', 'geometryResults']);

        $metadata = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
        $entities = $drawing->entities ?? collect();
        $rawLayers = $entities
            ->pluck('layer_name')
            ->filter()
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->values();

        $normalizedLayers = $rawLayers
            ->map(fn (string $layer) => $this->normalizeLayerName($layer))
            ->filter()
            ->values();

        $detectedStandardLayers = $this->detectedStandardLayers($entities);
        $textMetrics = $this->textMetrics($metadata);
        $textRefs = is_array(data_get($metadata, 'cad_text_references'))
            ? (array) data_get($metadata, 'cad_text_references')
            : [];
        $roomAreas = is_array(data_get($metadata, 'cad_text_room_areas'))
            ? (array) data_get($metadata, 'cad_text_room_areas')
            : [];
        $textMapping = $this->textMappingEvidence($textMetrics, $textRefs, $roomAreas);

        $groups = $this->evaluateGroups($entities, $metadata, $textMetrics, $detectedStandardLayers, $textMapping, $textRefs, $roomAreas);
        $availableGroups = array_values(array_keys(array_filter(
            $groups,
            fn (array $group) => (bool) ($group['present'] ?? false)
        )));
        $missingGroups = array_values(array_keys(array_filter(
            $groups,
            fn (array $group) => (bool) ($group['required'] ?? false) && ! (bool) ($group['present'] ?? false)
        )));

        $hasTextualDimensions = ! empty($textRefs) || $this->hasAnyNumericMetric($textMetrics);
        $geometryValues = $this->geometryValueIndex($drawing);
        $geometryAvailable = ! empty($geometryValues) || $this->hasUsableGeometryEntities($entities);
        $hasEstimatedGeometry = $this->hasEstimatedGeometry($entities);

        $dimensionSource = $this->determineDimensionSource($hasTextualDimensions, $geometryAvailable, $hasEstimatedGeometry);
        $fallbackMethodUsed = $textMapping['strong']
            ? 'textual_near_polygon_mapping'
            : $dimensionSource;

        $geometryMismatch = $this->hasGeometryMismatch($textMetrics, $geometryValues, $entities);
        $openOrIncomplete = $this->hasOpenOrIncompletePolygons($entities) && ! $textMapping['strong'];
        $unclearEntities = $this->hasUnclearEntities($entities, $availableGroups, $normalizedLayers);
        $missingTextLayer = ! $hasTextualDimensions;

        $score = 100.0;
        $penalties = [];
        $textualEvidenceBonus = 0.0;

        if ($textMapping['strong']) {
            $textualEvidenceBonus = 12.0;
        } elseif ($textMapping['moderate']) {
            $textualEvidenceBonus = 6.0;
        }

        if (! empty($missingGroups)) {
            $score -= $textMapping['strong'] ? 15 : 30;
            $penalties[] = [
                'reason' => 'Missing required marked CAD layers',
                'value' => $textMapping['strong'] ? -15 : -30,
                'detail' => implode(', ', $missingGroups),
            ];
        }

        if ($missingTextLayer) {
            $score -= 20;
            $penalties[] = [
                'reason' => 'Missing textual dimension layer',
                'value' => -20,
                'detail' => 'No reliable CAD text dimension layer or metrics were found.',
            ];
        }

        if ($dimensionSource === 'bounding_box_estimation') {
            $score -= 25;
            $penalties[] = [
                'reason' => 'Bounding box estimation only',
                'value' => -25,
                'detail' => 'Measurements were inferred from bounding boxes because text and reliable geometry signals were incomplete.',
            ];
        }

        if ($geometryMismatch || $unclearEntities) {
            $score -= 15;
            $penalties[] = [
                'reason' => 'Geometry mismatch or unclear entities',
                'value' => -15,
                'detail' => $geometryMismatch
                    ? 'Text and geometry values do not align closely.'
                    : 'Entity mapping is incomplete or unclear.',
            ];
        }

        if ($openOrIncomplete) {
            $score -= 10;
            $penalties[] = [
                'reason' => 'Open or incomplete polygons',
                'value' => -10,
                'detail' => 'One or more important polygons are open or incomplete.',
            ];
        }

        if ($textualEvidenceBonus > 0) {
            $score += $textualEvidenceBonus;
            $penalties[] = [
                'reason' => 'Textual near-polygon mapping strength',
                'value' => $textualEvidenceBonus,
                'detail' => $textMapping['strong']
                    ? 'Text-table rows, nearby dimensions, and room mappings were successfully linked to polygons.'
                    : 'Text-table rows and geometry were partially linked to polygons.',
            ];
        }

        $score = round(max(0, min(100, $score)), 2);
        $level = $this->confidenceLevel($score);

        $warnings = [];
        if ($textMapping['strong']) {
            $warnings[] = 'Text-based near-polygon mapping was detected from CAD text rows and room pairs. Measurements are derived from the detailed text mapping workflow, not generic bounding-box fallback.';
        }
        if (! empty($missingGroups)) {
            $warnings[] = $textMapping['strong']
                ? 'Some marked CAD layers were missing, but text-based near-polygon mapping supplied the measurement evidence.'
                : 'Confidence is low because required marked CAD layers were not found. Measurements were estimated using bounding box geometry.';
        }
        if ($missingTextLayer && $geometryAvailable) {
            $warnings[] = 'Textual dimension layer was not found. Dimensions were calculated from CAD geometry/bounding boxes.';
        }
        if ($missingTextLayer && $dimensionSource === 'bounding_box_estimation') {
            $warnings[] = 'CAD entities are incomplete or not mapped cleanly, so measurements are tentative and should be manually verified.';
        }
        if ($geometryMismatch) {
            $warnings[] = 'Geometry and CAD text measurements do not match closely; manual verification is recommended.';
        }
        if ($openOrIncomplete) {
            $warnings[] = 'One or more CAD polygons are open or incomplete.';
        }
        if ($score < 50) {
            $warnings[] = 'Manual AD ePermit verification is recommended before any strong approval or rejection.';
        }
        if (empty($warnings)) {
            $warnings[] = 'CAD layer coverage and measurement provenance are sufficient for a higher-confidence AI assessment.';
        }

        $availableLayers = array_values(array_unique(array_merge(
            $availableGroups,
            $detectedStandardLayers
        )));

        $summary = $this->buildSummary($score, $level, $missingGroups, $dimensionSource);

        return [
            'confidence_score' => $score,
            'confidence_level' => $level,
            'missing_layers' => $missingGroups,
            'available_layers' => $availableLayers,
            'available_layers_raw' => array_values($normalizedLayers->all()),
            'fallback_method_used' => $fallbackMethodUsed,
            'dimension_source' => $dimensionSource,
            'warnings' => array_values(array_unique($warnings)),
            'summary' => $summary,
            'score_breakdown' => [
                'base_score' => 100,
                'penalties' => $penalties,
                'final_score' => $score,
            ],
            'layer_groups' => $groups,
            'text_metrics_present' => $hasTextualDimensions,
            'text_mapping' => $textMapping,
            'geometry_available' => $geometryAvailable,
            'estimated_geometry' => $hasEstimatedGeometry,
            'open_or_incomplete_polygons' => $openOrIncomplete,
            'geometry_mismatch' => $geometryMismatch,
            'room_areas_detected' => ! empty($roomAreas),
        ];
    }

    private function evaluateGroups($entities, array $metadata, array $textMetrics, array $detectedStandardLayers, array $textMapping, array $textRefs, array $roomAreas): array
    {
        $known = [
            'plot_boundary' => [
                'label' => 'Plot boundary',
                'required' => true,
                'present' => false,
                'evidence' => [],
            ],
            'building_footprint' => [
                'label' => 'Building footprint',
                'required' => true,
                'present' => false,
                'evidence' => [],
            ],
            'setback_lines' => [
                'label' => 'Setback lines',
                'required' => true,
                'present' => false,
                'evidence' => [],
            ],
            'road_frontage_line' => [
                'label' => 'Road/frontage line',
                'required' => false,
                'present' => false,
                'evidence' => [],
            ],
            'room_covered_area_layers' => [
                'label' => 'Room/covered area layers',
                'required' => false,
                'present' => false,
                'evidence' => [],
            ],
            'dimension_text_layers' => [
                'label' => 'Dimension/text layers',
                'required' => true,
                'present' => false,
                'evidence' => [],
            ],
        ];

        $semanticNeedles = [
            'plot_boundary' => ['plot boundary', 'plot line', 'boundary wall', 'site boundary', 'plot boundary line', 'boundary'],
            'building_footprint' => ['ground floor', 'external walls', 'building footprint', 'footprint', 'covered area'],
            'setback_lines' => ['setback', 'front building line', 'rear building line', 'side building line', 'open space'],
            'road_frontage_line' => ['road', 'frontage', 'front road'],
            'room_covered_area_layers' => ['room', 'covered area', 'room area', 'space'],
            'dimension_text_layers' => ['dimension', 'dimensions', 'measurement', 'measurement text', 'text general', 'annotations'],
        ];

        foreach ($entities as $entity) {
            $layer = strtolower($this->normalizeLayerName((string) ($entity->layer_name ?? '')));
            $semantic = strtolower((string) ($entity->semantic_entity ?? ''));
            $mappingSource = strtolower((string) ($entity->mapping_source ?? ''));
            $geometry = is_array($entity->geometry_json) ? $entity->geometry_json : [];
            $synthetic = (bool) data_get($geometry, 'synthetic', false);
            $source = strtolower((string) data_get($geometry, 'source', ''));

            foreach ($semanticNeedles as $key => $needles) {
                if ($this->matchesAny($layer, $needles) || in_array($semantic, [$key, ...$needles], true)) {
                    $known[$key]['present'] = true;
                    $known[$key]['evidence'][] = $entity->layer_name;
                }
            }

            if (in_array($semantic, ['ground_floor_covered_polygon', 'first_floor_covered_polygon', 'second_floor_covered_polygon', 'basement_covered_polygon', 'building_footprint'], true)) {
                $known['building_footprint']['present'] = true;
                $known['building_footprint']['evidence'][] = $entity->layer_name;
            }

            if (in_array($semantic, ['front_setback', 'rear_setback', 'left_setback', 'right_setback', 'front_building_line', 'rear_building_line', 'side_building_line'], true)) {
                $known['setback_lines']['present'] = true;
                $known['setback_lines']['evidence'][] = $entity->layer_name;
            }

            if (in_array($semantic, ['road', 'frontage'], true) || $this->matchesAny($layer, ['road', 'frontage'])) {
                $known['road_frontage_line']['present'] = true;
                $known['road_frontage_line']['evidence'][] = $entity->layer_name;
            }

            if (in_array($semantic, ['annotations_and_dimensions'], true) || $this->matchesAny($layer, ['dimension', 'dimensions', 'measurement', 'text'])) {
                $known['dimension_text_layers']['present'] = true;
                $known['dimension_text_layers']['evidence'][] = $entity->layer_name;
            }

            if ($synthetic || in_array($mappingSource, ['boundary_layer_bbox', 'spatial_plan_block', 'floor_layer_name'], true) || in_array($source, ['recognized_boundary_layer_bbox', 'external_wall_spatial_plan_block'], true)) {
                $known['dimension_text_layers']['evidence'][] = $entity->layer_name . ' (estimated)';
            }
        }

        if (! empty($textMetrics)) {
            $known['dimension_text_layers']['present'] = true;
            $known['dimension_text_layers']['evidence'][] = 'cad_text_measurement_metrics';
        }

        if (! empty($textRefs)) {
            $known['dimension_text_layers']['present'] = true;
            $known['dimension_text_layers']['evidence'][] = 'cad_text_references';
        }

        if (! empty($roomAreas)) {
            $known['room_covered_area_layers']['present'] = true;
            $known['room_covered_area_layers']['evidence'][] = 'cad_text_room_areas';
        }

        if (! empty($textMetrics['ground_floor_covered'] ?? null) || ! empty($textMetrics['total_floor_covered'] ?? null) || ! empty($roomAreas)) {
            $known['building_footprint']['present'] = true;
            $known['building_footprint']['evidence'][] = 'cad_text_measurement_metrics';
        }

        if (! empty($textMetrics['front_setback_ft'] ?? null) || ! empty($textMetrics['rear_setback_ft'] ?? null) || ! empty($textMetrics['left_setback_ft'] ?? null) || ! empty($textMetrics['right_setback_ft'] ?? null)) {
            $known['setback_lines']['present'] = true;
            $known['setback_lines']['evidence'][] = 'cad_text_measurement_metrics';
        }

        if (! empty($textMetrics['plot_area'] ?? null) || ! empty($textMetrics['coverage_percent'] ?? null) || ! empty($textMetrics['far'] ?? null)) {
            $known['plot_boundary']['present'] = true;
            $known['plot_boundary']['evidence'][] = 'cad_text_measurement_metrics';
        }

        if ($textMapping['strong']) {
            $known['dimension_text_layers']['present'] = true;
            $known['dimension_text_layers']['evidence'][] = 'textual_near_polygon_mapping';
        }

        if (! empty($detectedStandardLayers)) {
            foreach ($detectedStandardLayers as $standardLayer) {
                $known[$standardLayer] = [
                    'label' => ucwords(str_replace('_', ' ', $standardLayer)),
                    'required' => false,
                    'present' => true,
                    'evidence' => ['standard mapping'],
                ];
            }
        }

        foreach ($known as $key => $group) {
            $known[$key]['evidence'] = array_values(array_unique(array_filter((array) ($group['evidence'] ?? []))));
        }

        return $known;
    }

    private function textMappingEvidence(array $textMetrics, array $textRefs, array $roomAreas): array
    {
        $coreKeys = [
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
        ];

        $coreCount = 0;
        foreach ($coreKeys as $key) {
            if (is_numeric($textMetrics[$key] ?? null)) {
                $coreCount++;
            }
        }

        $textRefCount = count(array_filter($textRefs, fn ($row) => is_array($row) && trim((string) data_get($row, 'text', '')) !== ''));
        $roomAreaCount = count(array_filter($roomAreas, fn ($row) => is_array($row) && is_numeric(data_get($row, 'area_sqft'))));

        $strong = $coreCount >= 6 || ($coreCount >= 4 && $roomAreaCount > 0) || ($textRefCount >= 8 && $coreCount >= 3);
        $moderate = ! $strong && ($coreCount >= 3 || $roomAreaCount > 0 || $textRefCount >= 4);

        return [
            'strong' => $strong,
            'moderate' => $moderate,
            'core_metric_count' => $coreCount,
            'text_reference_count' => $textRefCount,
            'room_area_count' => $roomAreaCount,
            'notes' => $strong
                ? ['Strong text-based near-polygon mapping detected.']
                : ($moderate
                    ? ['Partial text-based mapping detected.']
                    : ['Text-based mapping is weak or incomplete.']),
        ];
    }

    private function detectedStandardLayers($entities): array
    {
        $schema = $this->schemaService->semanticEntities();
        $detected = [];

        foreach ($entities as $entity) {
            $layer = $this->normalizeLayerName((string) ($entity->layer_name ?? ''));
            $semantic = strtolower((string) ($entity->semantic_entity ?? ''));

            foreach ($schema as $semanticKey => $definition) {
                $sourceLayers = array_map(
                    fn ($value) => $this->normalizeLayerName((string) $value),
                    is_array($definition['source_layers'] ?? null) ? $definition['source_layers'] : []
                );
                if ($semantic === strtolower((string) $semanticKey)) {
                    $detected[] = (string) $semanticKey;
                    continue;
                }
                foreach ($sourceLayers as $sourceLayer) {
                    if ($sourceLayer === '') {
                        continue;
                    }
                    if ($layer === $sourceLayer || str_contains($layer, $sourceLayer) || str_contains($sourceLayer, $layer)) {
                        $detected[] = (string) $semanticKey;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($detected));
    }

    private function geometryValueIndex(MapDrawing $drawing): array
    {
        $geometry = [];
        foreach ($drawing->geometryResults ?? [] as $result) {
            $key = (string) ($result->key ?? '');
            if ($key === '') {
                continue;
            }
            $geometry[$key] = is_numeric($result->value ?? null) ? (float) $result->value : $result->value;
        }

        return $geometry;
    }

    private function textMetrics(array $metadata): array
    {
        $metrics = is_array(data_get($metadata, 'cad_text_measurement_metrics'))
            ? (array) data_get($metadata, 'cad_text_measurement_metrics')
            : [];

        return array_filter($metrics, fn ($value) => $value !== null && $value !== '');
    }

    private function determineDimensionSource(bool $hasTextualDimensions, bool $geometryAvailable, bool $estimatedGeometry): string
    {
        if ($hasTextualDimensions) {
            return 'textual_layer';
        }

        if ($geometryAvailable && ! $estimatedGeometry) {
            return 'cad_geometry';
        }

        if ($geometryAvailable) {
            return 'bounding_box_estimation';
        }

        return 'bounding_box_estimation';
    }

    private function hasAnyNumericMetric(array $metrics): bool
    {
        foreach ($metrics as $value) {
            if (is_numeric($value)) {
                return true;
            }
        }

        return false;
    }

    private function hasUsableGeometryEntities($entities): bool
    {
        foreach ($entities as $entity) {
            $points = data_get($entity, 'geometry_json.points', []);
            if (is_array($points) && count($points) >= 3) {
                return true;
            }
            if (is_numeric($entity->area ?? null) && (float) $entity->area > 0) {
                return true;
            }
        }

        return false;
    }

    private function hasEstimatedGeometry($entities): bool
    {
        foreach ($entities as $entity) {
            $mappingSource = strtolower((string) ($entity->mapping_source ?? ''));
            $geometry = is_array($entity->geometry_json) ? $entity->geometry_json : [];
            if ((bool) data_get($geometry, 'synthetic', false)) {
                return true;
            }
            if (in_array($mappingSource, ['boundary_layer_bbox', 'spatial_plan_block', 'floor_layer_name'], true)) {
                return true;
            }
            if (in_array(strtolower((string) data_get($geometry, 'source', '')), ['recognized_boundary_layer_bbox', 'external_wall_spatial_plan_block'], true)) {
                return true;
            }
        }

        return false;
    }

    private function hasGeometryMismatch(array $textMetrics, array $geometryValues, $entities): bool
    {
        $pairs = [
            ['plot_area', 'plot_area_sqft'],
            ['ground_floor_covered', 'ground_floor_area_sqft'],
            ['total_floor_covered', 'total_covered_area_sqft'],
            ['coverage_percent', 'ground_coverage_percent'],
            ['front_setback_ft', 'front_setback_ft'],
            ['rear_setback_ft', 'rear_setback_ft'],
            ['left_setback_ft', 'left_setback_ft'],
            ['right_setback_ft', 'right_setback_ft'],
        ];

        foreach ($pairs as [$textKey, $geometryKey]) {
            if (! is_numeric($textMetrics[$textKey] ?? null) || ! is_numeric($geometryValues[$geometryKey] ?? null)) {
                continue;
            }
            $text = (float) $textMetrics[$textKey];
            $geometry = (float) $geometryValues[$geometryKey];
            $base = max(abs($text), abs($geometry), 1.0);
            if (abs($text - $geometry) / $base > 0.15) {
                return true;
            }
        }

        return false;
    }

    private function hasOpenOrIncompletePolygons($entities): bool
    {
        foreach ($entities as $entity) {
            $semantic = (string) ($entity->semantic_entity ?? '');
            if (! in_array($semantic, ['plot_boundary', 'ground_floor_covered_polygon', 'first_floor_covered_polygon', 'second_floor_covered_polygon', 'basement_covered_polygon'], true)) {
                continue;
            }

            $geometry = is_array($entity->geometry_json) ? $entity->geometry_json : [];
            $points = is_array(data_get($geometry, 'points')) ? data_get($geometry, 'points') : [];
            if (! (bool) ($entity->is_closed ?? false)) {
                return true;
            }
            if (count($points) < 4) {
                return true;
            }
            $first = $points[0] ?? null;
            $last = $points[count($points) - 1] ?? null;
            if (is_array($first) && is_array($last)) {
                $same = isset($first[0], $first[1], $last[0], $last[1])
                    && abs((float) $first[0] - (float) $last[0]) < 0.001
                    && abs((float) $first[1] - (float) $last[1]) < 0.001;
                if (! $same) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasUnclearEntities($entities, array $availableGroups, $normalizedLayers): bool
    {
        if (empty($availableGroups)) {
            return true;
        }

        $genericCount = 0;
        foreach ($normalizedLayers as $layer) {
            if (in_array($layer, ['0', 'defpoints', 'text', 'dimension', 'dimensions', 'measurement'], true)) {
                $genericCount++;
            }
        }

        return $genericCount > 0 && count($availableGroups) < 3;
    }

    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = $this->normalizeLayerName((string) $needle);
            if ($needle === '') {
                continue;
            }
            if ($haystack === $needle || str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLayerName(string $layerName): string
    {
        $normalized = $this->layerAliasService->normalize($layerName);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim((string) $normalized);
    }

    private function confidenceLevel(float $score): string
    {
        return match (true) {
            $score >= 80 => 'high',
            $score >= 50 => 'medium',
            default => 'low',
        };
    }

    private function buildSummary(float $score, string $level, array $missingLayers, string $dimensionSource): string
    {
        $missingText = empty($missingLayers) ? 'all required marked CAD layers were found' : ('missing layers: ' . implode(', ', $missingLayers));

        if ($level === 'low') {
            return 'Confidence is low because ' . $missingText . '. Measurements were evaluated using ' . $dimensionSource . '.';
        }

        return 'Confidence is ' . $level . ' with ' . $missingText . '. Measurements were evaluated using ' . $dimensionSource . '.';
    }
}
