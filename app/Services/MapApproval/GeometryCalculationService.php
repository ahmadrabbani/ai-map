<?php

namespace App\Services\MapApproval;

use App\Models\MapDrawing;
use App\Models\MapGeometryResult;

class GeometryCalculationService
{
    public function calculate(MapDrawing $drawing): array
    {
        $drawing->load('entities');
        MapGeometryResult::where('map_drawing_id', $drawing->id)->delete();

        $mapped = $drawing->entities
            ->whereIn('mapping_status', ['auto_mapped', 'manual_mapped', 'expert_verified'])
            ->groupBy('semantic_entity');

        $plot = $this->pickPrimaryPolygon($mapped->get('plot_boundary', collect())->all());
        $ground = $this->pickPrimaryPolygon($mapped->get('ground_floor_covered_polygon', collect())->all());
        $basement = $this->pickPrimaryPolygon($mapped->get('basement_covered_polygon', collect())->all());
        $first = $this->pickPrimaryPolygon($mapped->get('first_floor_covered_polygon', collect())->all());
        $second = $this->pickPrimaryPolygon($mapped->get('second_floor_covered_polygon', collect())->all());
        $porch = $this->pickPrimaryPolygon($mapped->get('ground_porch_polygon', collect())->all());

        $results = [];
        $status = 'calculated';
        $measurementsConfirmed = (bool) data_get($drawing->metadata_json, 'expert_measurements_confirmed', false);

        if (! $plot || ! $ground) {
            $status = 'needs_review';
        }
        $measurementStatus = (! $measurementsConfirmed && $this->sourceNeedsMeasurementVerification($plot, $ground)) ? 'needs_review' : $status;

        $plotArea = $plot?->area ?? null;
        $groundArea = $ground?->area ?? null;
        $basementArea = $basement?->area ?? null;
        $firstArea = $first?->area ?? null;
        $secondArea = $second?->area ?? null;
        $unitScale = $this->unitScaleForDrawing($drawing, $plotArea);
        $areaScale = $unitScale * $unitScale;

        $plotArea = $this->scaleArea($plotArea, $areaScale);
        $groundArea = $this->scaleArea($groundArea, $areaScale);
        $basementArea = $this->scaleArea($basementArea, $areaScale);
        $firstArea = $this->scaleArea($firstArea, $areaScale);
        $secondArea = $this->scaleArea($secondArea, $areaScale);

        $overrides = is_array(data_get($drawing->metadata_json, 'measurement_overrides'))
            ? data_get($drawing->metadata_json, 'measurement_overrides')
            : [];
        $textMetrics = is_array(data_get($drawing->metadata_json, 'cad_text_measurement_metrics'))
            ? (array) data_get($drawing->metadata_json, 'cad_text_measurement_metrics')
            : [];
        $textPlotArea = is_numeric($textMetrics['plot_area'] ?? null) ? (float) $textMetrics['plot_area'] : null;
        $textGroundCovered = is_numeric($textMetrics['ground_floor_covered'] ?? null) ? (float) $textMetrics['ground_floor_covered'] : null;
        $textTotalCovered = is_numeric($textMetrics['total_floor_covered'] ?? null) ? (float) $textMetrics['total_floor_covered'] : null;

        // For plans with complete textual measurements, trust the declared values first.
        if ($textPlotArea !== null && $textPlotArea > 0) {
            $plotArea = round($textPlotArea, 3);
        }
        if ($textGroundCovered !== null && $textGroundCovered >= 0) {
            $groundArea = round($textGroundCovered, 3);
        }

        $plotArea = $this->manualOverride($overrides, 'plot_area_sqft', $plotArea);
        $groundArea = $this->manualOverride($overrides, 'ground_floor_area_sqft', $groundArea);
        $basementArea = $this->manualOverride($overrides, 'basement_area_sqft', $basementArea);
        $firstArea = $this->manualOverride($overrides, 'first_floor_area_sqft', $firstArea);
        $secondArea = $this->manualOverride($overrides, 'second_floor_area_sqft', $secondArea);

        $totalCovered = collect([$groundArea, $firstArea, $secondArea])->filter(fn ($v) => is_numeric($v))->sum();
        if ($textTotalCovered !== null && $textTotalCovered >= 0) {
            $totalCovered = round($textTotalCovered, 3);
        }
        $coverage = (is_numeric($plotArea) && $plotArea > 0 && is_numeric($groundArea))
            ? round(($groundArea / $plotArea) * 100, 2)
            : null;
        $coverageFromTextMetric = is_numeric($textMetrics['coverage_percent'] ?? null)
            ? round((float) $textMetrics['coverage_percent'], 2)
            : null;
        if ($coverageFromTextMetric === null && $textPlotArea !== null && $textPlotArea > 0 && $textGroundCovered !== null) {
            $coverageFromTextMetric = round(($textGroundCovered / $textPlotArea) * 100, 2);
        }
        $farFromTextMetric = is_numeric($textMetrics['far'] ?? null)
            ? round((float) $textMetrics['far'], 4)
            : null;
        if ($farFromTextMetric === null && $textPlotArea !== null && $textPlotArea > 0 && $textTotalCovered !== null) {
            $farFromTextMetric = round($textTotalCovered / $textPlotArea, 4);
        }
        $coverageFromText = $this->groundCoveragePercentFromTextReferences(
            is_array(data_get($drawing->metadata_json, 'cad_text_references'))
                ? (array) data_get($drawing->metadata_json, 'cad_text_references')
                : []
        );
        if ($coverageFromTextMetric !== null) {
            // Canonical metric from structured measurement rows is stronger than free-text scan.
            $coverage = $coverageFromTextMetric;
        } elseif ($coverageFromText !== null) {
            // Prefer explicit plan text like "Ground coverage 75%" over weak reconstructed geometry.
            $coverage = $coverageFromText;
        }
        $coverage = $this->manualOverride($overrides, 'ground_coverage_percent', $coverage);
        $far = (is_numeric($plotArea) && $plotArea > 0 && $totalCovered > 0)
            ? round($totalCovered / $plotArea, 4)
            : null;
        if ($farFromTextMetric !== null) {
            $far = $farFromTextMetric;
        }
        $far = $this->manualOverride($overrides, 'far', $far);

        $setbacks = $this->setbacksFromBbox($plot?->bbox_json, $ground?->bbox_json, $unitScale, $measurementsConfirmed);
        if (is_numeric($textMetrics['front_setback_ft'] ?? null)) {
            $setbacks['front'] = round((float) $textMetrics['front_setback_ft'], 3);
        }
        if (is_numeric($textMetrics['rear_setback_ft'] ?? null)) {
            $setbacks['rear'] = round((float) $textMetrics['rear_setback_ft'], 3);
        }
        if (is_numeric($textMetrics['left_setback_ft'] ?? null)) {
            $setbacks['left'] = round((float) $textMetrics['left_setback_ft'], 3);
        }
        if (is_numeric($textMetrics['right_setback_ft'] ?? null)) {
            $setbacks['right'] = round((float) $textMetrics['right_setback_ft'], 3);
        }
        foreach (['front', 'rear', 'left', 'right'] as $side) {
            $setbacks[$side] = $this->manualOverride($overrides, $side . '_setback_ft', $setbacks[$side]);
        }
        $setbackStatus = $setbacks['status'];

        $porchLength = $porch ? $this->maxEdgeLength($porch->geometry_json['points'] ?? [], $unitScale) : null;
        $porchLength = $this->manualOverride($overrides, 'porch_length_ft', $porchLength);
        $porchArea = $this->scaleArea($porch?->area, $areaScale);
        $porchArea = $this->manualOverride($overrides, 'porch_area_sqft', $porchArea);

        $results['plot_area_sqft'] = ['value' => $plotArea, 'unit' => 'sqft', 'status' => $measurementStatus, 'sources' => ['plot_boundary']];
        $results['ground_floor_area_sqft'] = ['value' => $groundArea, 'unit' => 'sqft', 'status' => $measurementStatus, 'sources' => ['ground_floor_covered_polygon']];
        $results['basement_area_sqft'] = ['value' => $basementArea, 'unit' => 'sqft', 'status' => 'calculated', 'sources' => ['basement_covered_polygon']];
        $results['first_floor_area_sqft'] = ['value' => $firstArea, 'unit' => 'sqft', 'status' => 'calculated', 'sources' => ['first_floor_covered_polygon']];
        $results['second_floor_area_sqft'] = ['value' => $secondArea, 'unit' => 'sqft', 'status' => 'calculated', 'sources' => ['second_floor_covered_polygon']];
        $results['total_covered_area_sqft'] = ['value' => $totalCovered ?: null, 'unit' => 'sqft', 'status' => $measurementStatus, 'sources' => ['ground_floor_covered_polygon', 'first_floor_covered_polygon', 'second_floor_covered_polygon']];
        $coverageStatus = $coverage === null
            ? 'needs_review'
            : (($coverageFromTextMetric !== null || $coverageFromText !== null) ? 'calculated' : $measurementStatus);
        $coverageSources = ($coverageFromTextMetric !== null || $coverageFromText !== null)
            ? ['cad_text_references', 'plot_boundary', 'ground_floor_covered_polygon']
            : ['plot_boundary', 'ground_floor_covered_polygon'];
        $results['ground_coverage_percent'] = ['value' => $coverage, 'unit' => '%', 'status' => $coverageStatus, 'sources' => $coverageSources];
        $results['far'] = ['value' => $far, 'unit' => 'far', 'status' => $far === null ? 'needs_review' : $measurementStatus, 'sources' => ['plot_boundary', 'ground_floor_covered_polygon', 'first_floor_covered_polygon', 'second_floor_covered_polygon']];
        $results['front_setback_ft'] = ['value' => $setbacks['front'], 'unit' => 'ft', 'status' => $setbackStatus, 'sources' => ['plot_boundary', 'ground_floor_covered_polygon']];
        $results['rear_setback_ft'] = ['value' => $setbacks['rear'], 'unit' => 'ft', 'status' => $setbackStatus, 'sources' => ['plot_boundary', 'ground_floor_covered_polygon']];
        $results['left_setback_ft'] = ['value' => $setbacks['left'], 'unit' => 'ft', 'status' => $setbackStatus, 'sources' => ['plot_boundary', 'ground_floor_covered_polygon']];
        $results['right_setback_ft'] = ['value' => $setbacks['right'], 'unit' => 'ft', 'status' => $setbackStatus, 'sources' => ['plot_boundary', 'ground_floor_covered_polygon']];
        $results['porch_length_ft'] = ['value' => $porchLength, 'unit' => 'ft', 'status' => $porchLength === null ? 'needs_review' : 'calculated', 'sources' => ['ground_porch_polygon']];
        $results['porch_area_sqft'] = ['value' => $porchArea, 'unit' => 'sqft', 'status' => $porch ? 'calculated' : 'not_applicable', 'sources' => ['ground_porch_polygon']];
        $results['rear_toilet_area_sqft'] = ['value' => $this->totalArea($areaScale, $mapped->get('ground_services_polygon', collect())->all(), $mapped->get('first_floor_services_polygon', collect())->all(), $mapped->get('second_floor_services_polygon', collect())->all()), 'unit' => 'sqft', 'status' => 'needs_review', 'sources' => ['ground_services_polygon', 'first_floor_services_polygon', 'second_floor_services_polygon']];
        $results['storey_count'] = ['value' => $this->manualOverride($overrides, 'storey_count', $this->storeyCount($ground, $first, $second)), 'unit' => 'count', 'status' => 'calculated', 'sources' => ['ground_floor_covered_polygon', 'first_floor_covered_polygon', 'second_floor_covered_polygon']];
        $results['cad_unit_scale'] = ['value' => round($unitScale, 6), 'unit' => 'ft_per_drawing_unit', 'status' => 'calculated', 'sources' => ['plot_boundary']];
        $results['measurement_verification'] = [
            'value' => $measurementStatus === 'needs_review' ? 'required' : ($measurementsConfirmed ? 'expert_confirmed' : 'verified'),
            'unit' => null,
            'status' => $measurementStatus,
            'sources' => ['plot_boundary', 'ground_floor_covered_polygon'],
        ];

        foreach ($results as $key => $row) {
            MapGeometryResult::create([
                'map_drawing_id' => $drawing->id,
                'key' => $key,
                'value' => $row['value'] === null ? null : (string) $row['value'],
                'unit' => $row['unit'],
                'source_semantic_entities_json' => $row['sources'],
                'calculation_method' => 'deterministic_semantic_geometry',
                'status' => $row['status'],
            ]);
        }

        return $results;
    }

    private function pickPrimaryPolygon(array $entities): ?object
    {
        if (empty($entities)) {
            return null;
        }

        usort($entities, fn ($a, $b) => ($b->area ?? 0) <=> ($a->area ?? 0));
        if (count($entities) > 1) {
            $top = (float) ($entities[0]->area ?? 0);
            $next = (float) ($entities[1]->area ?? 0);
            if ($top > 0 && ($next / $top) >= 0.9) {
                return null;
            }
        }

        return $entities[0];
    }

    private function setbacksFromBbox(?array $plot, ?array $ground, float $unitScale, bool $measurementsConfirmed = false): array
    {
        if (! is_array($plot) || ! is_array($ground)) {
            return ['front' => null, 'rear' => null, 'left' => null, 'right' => null, 'status' => 'needs_review'];
        }

        return [
            'front' => round(max(0, ($ground['min_y'] ?? 0) - ($plot['min_y'] ?? 0)) * $unitScale, 3),
            'rear' => round(max(0, ($plot['max_y'] ?? 0) - ($ground['max_y'] ?? 0)) * $unitScale, 3),
            'left' => round(max(0, ($ground['min_x'] ?? 0) - ($plot['min_x'] ?? 0)) * $unitScale, 3),
            'right' => round(max(0, ($plot['max_x'] ?? 0) - ($ground['max_x'] ?? 0)) * $unitScale, 3),
            'status' => $measurementsConfirmed ? 'calculated' : 'needs_review',
        ];
    }

    private function maxEdgeLength(array $points, float $unitScale): ?float
    {
        if (count($points) < 2) {
            return null;
        }
        $max = 0.0;
        for ($i = 0; $i < count($points) - 1; $i++) {
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $d = hypot(($p2[0] ?? 0) - ($p1[0] ?? 0), ($p2[1] ?? 0) - ($p1[1] ?? 0));
            $max = max($max, $d);
        }

        return round($max * $unitScale, 3);
    }

    private function totalArea(float $areaScale, array ...$groups): ?float
    {
        $sum = 0.0;
        $seen = false;
        foreach ($groups as $group) {
            foreach ($group as $entity) {
                if (is_numeric($entity->area)) {
                    $sum += ((float) $entity->area) * $areaScale;
                    $seen = true;
                }
            }
        }

        return $seen ? round($sum, 3) : null;
    }

    private function storeyCount(?object $ground, ?object $first, ?object $second): int
    {
        $count = 0;
        foreach ([$ground, $first, $second] as $level) {
            if ($level) {
                $count++;
            }
        }

        return $count;
    }

    private function sourceNeedsMeasurementVerification(?object ...$entities): bool
    {
        foreach ($entities as $entity) {
            if (! $entity) {
                return true;
            }

            $geometry = is_array($entity->geometry_json ?? null) ? $entity->geometry_json : [];
            $source = (string) ($geometry['source'] ?? '');
            $mappingSource = (string) ($entity->mapping_source ?? '');
            if (($geometry['synthetic'] ?? false) || in_array($mappingSource, ['boundary_layer_bbox', 'spatial_plan_block', 'floor_layer_name'], true)) {
                return true;
            }
            if (in_array($source, ['recognized_boundary_layer_bbox', 'external_wall_spatial_plan_block'], true)) {
                return true;
            }
        }

        return false;
    }

    private function scaleArea(mixed $value, float $areaScale): ?float
    {
        return is_numeric($value) ? round(((float) $value) * $areaScale, 3) : null;
    }

    private function manualOverride(array $overrides, string $key, mixed $value): mixed
    {
        if (! array_key_exists($key, $overrides) || ! is_numeric($overrides[$key])) {
            return $value;
        }

        return round((float) $overrides[$key], str_ends_with($key, '_percent') || $key === 'far' ? 4 : 3);
    }

    private function unitScaleForDrawing(MapDrawing $drawing, mixed $plotArea): float
    {
        if (! is_numeric($plotArea) || (float) $plotArea <= 0) {
            return 1.0;
        }

        // Many DWG files are drafted in inches. A 25x45 ft plot appears as 300x540 units.
        if ((float) $plotArea > 10000) {
            $areaInFeetIfInches = ((float) $plotArea) / 144;
            if ($areaInFeetIfInches >= 500 && $areaInFeetIfInches <= 10000) {
                return round(1 / 12, 8);
            }
        }

        $declaredArea = data_get($drawing->metadata_json, 'plot_area_sqft');
        if (is_numeric($declaredArea) && (float) $declaredArea > 0) {
            return round(sqrt(((float) $declaredArea) / ((float) $plotArea)), 8);
        }

        $category = (string) data_get($drawing->metadata_json, 'plot_size_category', '5_marla');
        $expected = match ($category) {
            '10_marla' => 2250.0,
            '5_marla' => 1125.0,
            default => null,
        };

        if ($expected && (float) $plotArea > ($expected * 10)) {
            return round(sqrt($expected / ((float) $plotArea)), 8);
        }

        // Most local residential CAD submissions are drafted in inches; normalize to feet.
        if ((float) $plotArea > 10000) {
            return round(1 / 12, 8);
        }

        return 1.0;
    }

    private function groundCoveragePercentFromTextReferences(array $textReferences): ?float
    {
        $candidates = [];

        foreach ($textReferences as $row) {
            $text = strtolower(trim((string) data_get($row, 'text', '')));
            if ($text === '') {
                continue;
            }

            $isCoverageLine = str_contains($text, 'coverage')
                || str_contains($text, 'covered area')
                || str_contains($text, 'ground coverage')
                || str_contains($text, 'max coverage');
            if (! $isCoverageLine) {
                continue;
            }

            if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $text, $m)) {
                $val = (float) $m[1];
                if ($val >= 20 && $val <= 95) {
                    $candidates[] = $val;
                    continue;
                }
            }

            if (preg_match_all('/\b(\d+(?:\.\d+)?)\b/', $text, $mAll)) {
                foreach ((array) ($mAll[1] ?? []) as $raw) {
                    $val = (float) $raw;
                    if ($val >= 20 && $val <= 95) {
                        $candidates[] = $val;
                    }
                }
            }
        }

        if (empty($candidates)) {
            return null;
        }

        sort($candidates);
        $mid = intdiv(count($candidates), 2);
        $median = count($candidates) % 2 === 0
            ? (($candidates[$mid - 1] + $candidates[$mid]) / 2)
            : $candidates[$mid];

        return round((float) $median, 2);
    }
}
