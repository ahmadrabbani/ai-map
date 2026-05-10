<?php

namespace App\Services\MapApproval;

use App\Models\MapDrawing;
use App\Models\MapEntity;

class LayerReviewSuggestionService
{
    private const TARGETS = [
        'PLOT_BOUNDARY' => ['semantic' => 'plot_boundary', 'required' => true, 'optional' => false, 'expect_closed' => true],
        'GROUND_FLOOR_FOOTPRINT' => ['semantic' => 'ground_floor_covered_polygon', 'required' => true, 'optional' => false, 'expect_closed' => true],
        'BASEMENT_FOOTPRINT' => ['semantic' => 'basement_covered_polygon', 'required' => false, 'optional' => true, 'expect_closed' => true],
        'FIRST_FLOOR_FOOTPRINT' => ['semantic' => 'first_floor_covered_polygon', 'required' => false, 'optional' => true, 'expect_closed' => true],
        'ROAD' => ['semantic' => 'road', 'required' => false, 'optional' => true, 'expect_closed' => false],
        'FRONT_SETBACK' => ['semantic' => 'front_setback', 'required' => false, 'optional' => true, 'expect_closed' => false],
        'REAR_SETBACK' => ['semantic' => 'rear_setback', 'required' => false, 'optional' => true, 'expect_closed' => false],
        'LEFT_SIDE_SETBACK' => ['semantic' => 'left_side_setback', 'required' => false, 'optional' => true, 'expect_closed' => false],
        'RIGHT_SIDE_SETBACK' => ['semantic' => 'right_side_setback', 'required' => false, 'optional' => true, 'expect_closed' => false],
    ];

    public function __construct(
        private readonly RuleToLayerSchemaService $schemaService
    ) {
    }

    public function buildSuggestions(MapDrawing $drawing): array
    {
        $drawing->load('entities');
        $entities = $drawing->entities;
        $schema = $this->schemaService->load();
        $semanticDefs = $schema['semantic_entities'] ?? [];

        $plot = $entities
            ->whereIn('mapping_status', ['auto_mapped', 'manual_mapped', 'expert_verified'])
            ->firstWhere('semantic_entity', 'plot_boundary');
        $plotBbox = $plot?->bbox_json;

        $result = [];
        foreach (self::TARGETS as $publicName => $cfg) {
            $semantic = $cfg['semantic'];
            $def = $semanticDefs[$semantic] ?? [];
            $sourceLayers = $def['source_layers'] ?? [];
            $allowedTypes = $def['allowed_entity_types'] ?? [];
            $normalizedSourceLayers = array_values(array_filter(array_map(
                fn ($name) => $this->normalizeLayerName((string) $name),
                $sourceLayers
            )));
            $candidates = [];

            foreach ($entities as $entity) {
                if ($entity->mapping_status === 'ignored') {
                    continue;
                }
                $normalizedEntityLayer = $this->normalizeLayerName((string) $entity->layer_name);
                if (! empty($normalizedSourceLayers) && ! $this->matchesSourceLayers($normalizedEntityLayer, $normalizedSourceLayers)) {
                    continue;
                }
                if (! empty($allowedTypes) && ! in_array($entity->entity_type, $allowedTypes, true)) {
                    continue;
                }

                $score = 0;
                $reasons = [];
                $normLayer = strtolower((string) $entity->layer_name);
                $normSemantic = strtolower($publicName . '_' . $semantic);

                $score += 35;
                $reasons[] = 'layer match';

                if (($cfg['expect_closed'] ?? false) && $entity->is_closed) {
                    $score += 20;
                    $reasons[] = 'closed geometry';
                } elseif (($cfg['expect_closed'] ?? false) && ! $entity->is_closed) {
                    $score -= 20;
                    $reasons[] = 'expected closed polygon';
                }

                if (is_numeric($entity->area) && (float) $entity->area > 0) {
                    $score += 10;
                    $reasons[] = 'has area';
                }

                if ($plotBbox && $this->isInsidePlot($entity->bbox_json, $plotBbox)) {
                    $score += 12;
                    $reasons[] = 'inside plot';
                }

                if (str_contains($normLayer, 'road') || str_contains($normLayer, 'setback') || str_contains($normLayer, 'site')) {
                    $score += 8;
                    $reasons[] = 'name similarity';
                }
                if (str_contains($normLayer, 'gf') && str_contains($normSemantic, 'ground')) {
                    $score += 8;
                    $reasons[] = 'floor keyword match';
                }
                if (str_contains($normLayer, 'ff') && str_contains($normSemantic, 'first')) {
                    $score += 8;
                    $reasons[] = 'floor keyword match';
                }
                if (str_contains($normLayer, 'bsm') && str_contains($normSemantic, 'basement')) {
                    $score += 8;
                    $reasons[] = 'floor keyword match';
                }

                $score = max(0, min(100, $score));
                $candidates[] = [
                    'entity_handle' => $entity->handle,
                    'layer_name' => $entity->layer_name,
                    'entity_type' => $entity->entity_type,
                    'confidence_score' => round($score, 2),
                    'reason' => implode(', ', array_values(array_unique($reasons))),
                    'bbox' => $entity->bbox_json,
                    'area' => $entity->area,
                    'is_closed' => (bool) $entity->is_closed,
                ];
            }

            usort($candidates, fn ($a, $b) => ($b['confidence_score'] <=> $a['confidence_score']));
            $result[$publicName] = [
                'semantic_layer_name' => $publicName,
                'internal_semantic' => $semantic,
                'required' => (bool) $cfg['required'],
                'optional' => (bool) $cfg['optional'],
                'top_suggestion' => $candidates[0] ?? null,
                'suggestions' => array_slice($candidates, 0, 5),
            ];
        }

        return $result;
    }

    private function matchesSourceLayers(string $normalizedEntityLayer, array $normalizedSourceLayers): bool
    {
        foreach ($normalizedSourceLayers as $sourceLayer) {
            if ($sourceLayer === '' || $normalizedEntityLayer === '') {
                continue;
            }
            if ($normalizedEntityLayer === $sourceLayer) {
                return true;
            }
            if (str_contains($normalizedEntityLayer, $sourceLayer) || str_contains($sourceLayer, $normalizedEntityLayer)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLayerName(string $layerName): string
    {
        $normalized = strtolower(trim($layerName));
        $normalized = preg_replace('/^[\d\.\-_ ]+/', '', $normalized) ?? $normalized;
        $normalized = str_replace(['_', '-', '.'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function isInsidePlot(?array $bbox, ?array $plotBBox): bool
    {
        if (! is_array($bbox) || ! is_array($plotBBox)) {
            return false;
        }
        $eps = 0.001;
        return
            (($bbox['min_x'] ?? INF) >= (($plotBBox['min_x'] ?? -INF) - $eps)) &&
            (($bbox['min_y'] ?? INF) >= (($plotBBox['min_y'] ?? -INF) - $eps)) &&
            (($bbox['max_x'] ?? -INF) <= (($plotBBox['max_x'] ?? INF) + $eps)) &&
            (($bbox['max_y'] ?? -INF) <= (($plotBBox['max_y'] ?? INF) + $eps));
    }
}
