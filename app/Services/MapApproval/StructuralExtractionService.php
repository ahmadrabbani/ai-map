<?php

namespace App\Services\MapApproval;

use App\Models\MapDrawing;
use App\Models\MapEntity;
use App\Models\MapGeometryResult;

class StructuralExtractionService
{
    public function extract(MapDrawing $drawing): array
    {
        $drawing->loadMissing('entities');

        $entities = [];
        foreach ($drawing->entities as $entity) {
            $candidate = $this->classifyEntity($entity);
            if (! $candidate) {
                continue;
            }
            $entities[] = $candidate;
        }

        $graph = $this->buildGraph($entities);
        $summary = $this->buildSummary($entities);

        return [
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'entities' => $entities,
            'graph' => $graph,
            'summary' => $summary,
            'confidence' => $this->overallConfidence($entities),
            'notes' => [
                'Phase 1 structural extraction uses geometry/layer heuristics and should be verified by expert when confidence is low.',
            ],
        ];
    }

    private function classifyEntity(MapEntity $entity): ?array
    {
        $layer = strtolower((string) ($entity->layer_name ?? ''));
        $type = strtoupper((string) ($entity->entity_type ?? ''));
        $geometry = is_array($entity->geometry_json) ? $entity->geometry_json : [];
        $bbox = $this->resolveBbox($entity, $geometry);
        if (! $bbox) {
            return null;
        }

        $width = abs(($bbox['max_x'] ?? 0) - ($bbox['min_x'] ?? 0));
        $height = abs(($bbox['max_y'] ?? 0) - ($bbox['min_y'] ?? 0));
        $maxDim = max($width, $height);
        $minDim = min($width, $height);
        $ratio = $minDim > 0 ? ($maxDim / $minDim) : 0;

        $semantic = null;
        $confidence = 0.45;
        $reason = [];

        if ($this->layerContainsAny($layer, ['column', 'col', 'pillar'])) {
            $semantic = 'column';
            $confidence = 0.85;
            $reason[] = 'Layer name indicates column.';
        } elseif ($this->layerContainsAny($layer, ['beam', 'bm'])) {
            $semantic = 'beam';
            $confidence = 0.82;
            $reason[] = 'Layer name indicates beam.';
        } elseif ($this->layerContainsAny($layer, ['slab'])) {
            $semantic = 'slab';
            $confidence = 0.82;
            $reason[] = 'Layer name indicates slab.';
        } elseif ($this->layerContainsAny($layer, ['shear', 'core wall'])) {
            $semantic = 'shear_wall';
            $confidence = 0.8;
            $reason[] = 'Layer name indicates shear/core wall.';
        } elseif ($this->layerContainsAny($layer, ['wall'])) {
            $semantic = 'wall';
            $confidence = 0.72;
            $reason[] = 'Layer name indicates wall.';
        } elseif ($this->layerContainsAny($layer, ['stair', 'staircase'])) {
            $semantic = 'stair';
            $confidence = 0.76;
            $reason[] = 'Layer name indicates stair.';
        }

        if (! $semantic) {
            if (in_array($type, ['LWPOLYLINE', 'POLYLINE'], true) && $entity->is_closed && $ratio <= 1.5 && $maxDim > 0 && $maxDim <= 4.0) {
                $semantic = 'column';
                $confidence = 0.62;
                $reason[] = 'Closed near-square small footprint resembles a column.';
            } elseif (in_array($type, ['LINE', 'LWPOLYLINE', 'POLYLINE'], true) && $ratio >= 4.0) {
                $semantic = 'beam';
                $confidence = 0.58;
                $reason[] = 'Long narrow geometry resembles a beam.';
            } elseif (in_array($type, ['LWPOLYLINE', 'POLYLINE', 'HATCH'], true) && $entity->is_closed && $maxDim >= 8.0) {
                $semantic = 'slab';
                $confidence = 0.56;
                $reason[] = 'Large closed region resembles a slab panel.';
            }
        }

        if (! $semantic) {
            return null;
        }

        $centerX = (($bbox['min_x'] ?? 0) + ($bbox['max_x'] ?? 0)) / 2;
        $centerY = (($bbox['min_y'] ?? 0) + ($bbox['max_y'] ?? 0)) / 2;

        return [
            'id' => (string) ($entity->handle ?: ('entity-' . $entity->id)),
            'map_drawing_id' => $entity->map_drawing_id,
            'source_entity_id' => $entity->id,
            'layer' => (string) ($entity->layer_name ?? ''),
            'entity_type' => $type,
            'semantic_type' => $semantic,
            'confidence' => round($confidence, 3),
            'reason' => implode(' ', $reason),
            'bbox' => $bbox,
            'center' => ['x' => round($centerX, 3), 'y' => round($centerY, 3)],
            'dimensions' => [
                'width' => round($width, 3),
                'height' => round($height, 3),
                'aspect_ratio' => round($ratio, 3),
            ],
            'area' => is_numeric($entity->area) ? round((float) $entity->area, 3) : null,
            'perimeter' => is_numeric($entity->perimeter) ? round((float) $entity->perimeter, 3) : null,
        ];
    }

    private function buildGraph(array $entities): array
    {
        $connectionThreshold = $this->adaptiveConnectionThreshold($entities);
        $unitScaleToFt = $this->unitScaleToFeet($entities);
        $nodes = array_map(fn ($e) => [
            'id' => $e['id'],
            'semantic_type' => $e['semantic_type'],
            'confidence' => $e['confidence'],
        ], $entities);

        $edges = [];
        $count = count($entities);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $entities[$i];
                $b = $entities[$j];
                $dist = $this->distance($a['center'], $b['center']);
                if ($dist === null || $dist > $connectionThreshold) {
                    continue;
                }

                $type = null;
                $pair = [$a['semantic_type'], $b['semantic_type']];
                sort($pair);
                if ($pair === ['beam', 'column']) {
                    $type = 'beam_column_connection';
                } elseif (in_array('slab', $pair, true) && (in_array('beam', $pair, true) || in_array('wall', $pair, true) || in_array('shear_wall', $pair, true))) {
                    $type = 'support_relation';
                } elseif ($pair === ['column', 'column']) {
                    $type = 'column_alignment';
                }

                if (! $type) {
                    continue;
                }

                $edges[] = [
                    'from' => $a['id'],
                    'to' => $b['id'],
                    'relation' => $type,
                    'distance' => round($dist * $unitScaleToFt, 3),
                    'distance_unit' => 'ft',
                    'raw_distance' => round($dist, 3),
                    'raw_distance_unit' => 'drawing_unit',
                ];
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    private function adaptiveConnectionThreshold(array $entities): float
    {
        if (empty($entities)) {
            return 14.0;
        }

        $maxDims = [];
        foreach ($entities as $entity) {
            $w = (float) data_get($entity, 'dimensions.width', 0);
            $h = (float) data_get($entity, 'dimensions.height', 0);
            $d = max($w, $h);
            if ($d > 0) {
                $maxDims[] = $d;
            }
        }

        if (empty($maxDims)) {
            return 14.0;
        }

        sort($maxDims);
        $mid = (int) floor(count($maxDims) / 2);
        $median = $maxDims[$mid] ?? 14.0;

        return max(14.0, min(1200.0, $median * 6.0));
    }

    private function buildSummary(array $entities): array
    {
        $byType = [];
        foreach ($entities as $entity) {
            $t = (string) ($entity['semantic_type'] ?? 'unknown');
            $byType[$t] = ($byType[$t] ?? 0) + 1;
        }

        ksort($byType);

        return [
            'total_detected' => count($entities),
            'by_type' => $byType,
        ];
    }

    private function overallConfidence(array $entities): float
    {
        if (empty($entities)) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($entities as $entity) {
            $sum += (float) ($entity['confidence'] ?? 0);
        }

        return round($sum / count($entities), 3);
    }

    private function resolveBbox(MapEntity $entity, array $geometry): ?array
    {
        $bbox = is_array($entity->bbox_json) ? $entity->bbox_json : [];
        if (isset($bbox['min_x'], $bbox['max_x'], $bbox['min_y'], $bbox['max_y'])) {
            return [
                'min_x' => (float) $bbox['min_x'],
                'min_y' => (float) $bbox['min_y'],
                'max_x' => (float) $bbox['max_x'],
                'max_y' => (float) $bbox['max_y'],
            ];
        }

        $points = is_array($geometry['points'] ?? null) ? $geometry['points'] : [];
        if (empty($points)) {
            return null;
        }

        $xs = [];
        $ys = [];
        foreach ($points as $point) {
            if (! is_array($point) || ! isset($point[0], $point[1]) || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                continue;
            }
            $xs[] = (float) $point[0];
            $ys[] = (float) $point[1];
        }

        if (empty($xs) || empty($ys)) {
            return null;
        }

        return [
            'min_x' => min($xs),
            'min_y' => min($ys),
            'max_x' => max($xs),
            'max_y' => max($ys),
        ];
    }

    private function layerContainsAny(string $layer, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($layer, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function distance(array $a, array $b): ?float
    {
        if (! isset($a['x'], $a['y'], $b['x'], $b['y'])) {
            return null;
        }

        $dx = (float) $a['x'] - (float) $b['x'];
        $dy = (float) $a['y'] - (float) $b['y'];

        return sqrt(($dx * $dx) + ($dy * $dy));
    }

    private function unitScaleToFeet(array $entities): float
    {
        if (empty($entities)) {
            return 1.0;
        }

        $drawingId = data_get($entities, '0.map_drawing_id');
        if (! is_numeric($drawingId)) {
            return 1.0;
        }

        $row = MapGeometryResult::query()
            ->where('map_drawing_id', (int) $drawingId)
            ->where('key', 'cad_unit_scale')
            ->first();

        $value = is_numeric($row?->value) ? (float) $row->value : null;
        if ($value === null || $value <= 0) {
            return 1.0;
        }

        return $value;
    }
}
