<?php

namespace App\Services\MapApproval;

use App\Models\MapDrawing;
use App\Models\MapRuleResult;

class RuleValidationService
{
    public function __construct(
        private readonly RuleToLayerSchemaService $schemaService
    ) {
    }

    public function validate(MapDrawing $drawing, array $geometry): array
    {
        MapRuleResult::where('map_drawing_id', $drawing->id)->delete();

        $meta = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
        $textMetrics = is_array(data_get($meta, 'cad_text_measurement_metrics'))
            ? (array) data_get($meta, 'cad_text_measurement_metrics')
            : [];
        $textRefs = is_array(data_get($meta, 'cad_text_references'))
            ? (array) data_get($meta, 'cad_text_references')
            : [];
        $roomAreas = is_array(data_get($meta, 'cad_text_room_areas'))
            ? (array) data_get($meta, 'cad_text_room_areas')
            : [];
        $patternProfile = is_array(data_get($meta, 'dxf_pattern_profile'))
            ? (array) data_get($meta, 'dxf_pattern_profile')
            : [];

        $rules = $this->loadResidentialRules($drawing);
        $schema = $this->schemaService->load();
        $rulesToLayers = $schema['rules_to_layers'] ?? [];
        $results = [];

        foreach ($rules as $rule) {
            $ruleCode = (string) ($rule['id'] ?? '');
            $required = $this->requiredValue($rule);
            $unit = $this->requiredUnit($rule);
            [$actual, $actualStatus, $sourceHint] = $this->actualForRule($ruleCode, $geometry, $textMetrics, $textRefs, $roomAreas, $patternProfile);
            $status = $this->compare($rule['operator'] ?? null, $required, $actual, $actualStatus);

            $row = [
                'rule_code' => $ruleCode,
                'rule_title' => $rule['title'] ?? null,
                'required_value' => $required === null ? null : (string) $required,
                'actual_value' => $actual === null ? null : (string) $actual,
                'unit' => $unit,
                'status' => $status,
                'message' => $this->message($status, $rule, $required, $actual, $unit, $sourceHint),
                'source_layers_json' => $rulesToLayers[$ruleCode] ?? [],
                'source_entities_json' => $this->sourceEntities($drawing, $ruleCode),
            ];

            MapRuleResult::create(array_merge($row, ['map_drawing_id' => $drawing->id]));
            $results[] = $row;
        }

        $this->validateDocumentRules($drawing, $results, $rulesToLayers);

        return $results;
    }

    private function loadResidentialRules(MapDrawing $drawing): array
    {
        $metaPath = base_path('rules/approval_rules_meta.json');
        if (! is_file($metaPath)) {
            return [];
        }

        $meta = json_decode(file_get_contents($metaPath), true);
        $category = (string) data_get($drawing->metadata_json, 'plot_size_category', '');
        if ($category === '') {
            $category = $this->inferPlotSizeCategory($drawing);
            $metadata = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
            $metadata['plot_size_category'] = $category;
            $drawing->metadata_json = $metadata;
            $drawing->save();
        }
        $rules = $meta['plot_size_categories'][$category]['ground_floor_rules'] ?? [];
        if (! is_array($rules) || empty($rules)) {
            $rules = $this->rulesFromResidentialHouseBands($meta, $this->resolvePlotAreaSqft($drawing));
        }

        return is_array($rules) ? $rules : [];
    }

    private function inferPlotSizeCategory(MapDrawing $drawing): string
    {
        $plotAreaSqft = $this->resolvePlotAreaSqft($drawing);

        if ($plotAreaSqft === null || $plotAreaSqft <= 0) {
            return '5_marla';
        }
        if ($plotAreaSqft <= 1125.0) {
            return '5_marla';
        }
        if ($plotAreaSqft <= 2250.0) {
            return '10_marla';
        }

        return 'above_10_marla';
    }

    private function resolvePlotAreaSqft(MapDrawing $drawing): ?float
    {
        $meta = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
        $textArea = data_get($meta, 'cad_text_measurement_metrics.plot_area');
        if (is_numeric($textArea)) {
            return (float) $textArea;
        }
        $declaredArea = data_get($meta, 'plot_area_sqft');
        if (is_numeric($declaredArea)) {
            return (float) $declaredArea;
        }
        $geometryArea = $drawing->geometryResults()
            ->where('key', 'plot_area_sqft')
            ->orderByDesc('id')
            ->value('value');
        if (is_numeric($geometryArea)) {
            return (float) $geometryArea;
        }

        return null;
    }

    private function rulesFromResidentialHouseBands(array $meta, ?float $plotAreaSqft): array
    {
        if (! is_array($meta) || $plotAreaSqft === null || $plotAreaSqft <= 0) {
            return [];
        }

        $marla = $plotAreaSqft / 225.0;
        $coverageRows = data_get($meta, 'source_rulebook_snapshot.residential_house_rules.coverage_far_height_storeys_approved_scheme', []);
        $spaceRows = data_get($meta, 'source_rulebook_snapshot.residential_house_rules.mandatory_open_spaces_approved_scheme', []);
        if (! is_array($coverageRows) || ! is_array($spaceRows)) {
            return [];
        }

        $coverage = $this->pickCoverageBand($coverageRows, $marla);
        $spaces = $this->pickOpenSpaceBand($spaceRows, $marla);
        if (! is_array($coverage) && ! is_array($spaces)) {
            return [];
        }

        $rules = [];
        if (is_array($spaces)) {
            $front = $this->toFloat($spaces['front_ft'] ?? null);
            $rear = $this->toFloat($spaces['rear_ft'] ?? null);
            $side = $this->sideSetbackFromLabel($spaces['side'] ?? null);
            if ($front !== null) {
                $rules[] = ['id' => 'SETBACK_FRONT', 'title' => 'Front setback requirement', 'operator' => '>=', 'value_ft' => $front];
            }
            if ($rear !== null) {
                $rules[] = ['id' => 'SETBACK_REAR', 'title' => 'Rear setback requirement', 'operator' => '>=', 'value_ft' => $rear];
            }
            if ($side !== null) {
                $rules[] = [
                    'id' => 'SETBACK_SIDE',
                    'title' => 'Side setback requirement',
                    'operator' => $side > 0 ? '>=' : '==',
                    'value_ft' => $side,
                ];
            }
        }
        if (is_array($coverage)) {
            $gc = $this->toFloat($coverage['max_ground_coverage_percent'] ?? null);
            $far = $this->toFloat($coverage['max_far'] ?? null);
            $height = $this->toFloat($coverage['max_height_ft'] ?? null);
            $storeys = $this->toFloat($coverage['max_storeys_excluding_basement'] ?? null);
            if ($gc !== null) {
                $rules[] = ['id' => 'GROUND_COVERAGE', 'title' => 'Maximum ground coverage', 'operator' => '<=', 'value_percent' => $gc];
            }
            if ($far !== null) {
                $rules[] = ['id' => 'FAR_LIMIT', 'title' => 'Maximum Floor Area Ratio', 'operator' => '<=', 'value' => $far];
            }
            if ($storeys !== null) {
                $rules[] = ['id' => 'MAX_STOREYS', 'title' => 'Maximum number of storeys', 'operator' => '<=', 'value' => $storeys];
            }
            if ($height !== null) {
                $rules[] = ['id' => 'MAX_HEIGHT', 'title' => 'Maximum building height', 'operator' => '<=', 'value_ft' => $height];
            }
        }

        return $rules;
    }

    private function pickCoverageBand(array $rows, float $marla): ?array
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $size = (string) ($row['plot_size'] ?? '');
            if ($size === '10_marla_to_less_than_1_kanal' && $marla >= 10 && $marla < 20) {
                return $row;
            }
            if ($size === '1_kanal_to_30_marla' && $marla >= 20 && $marla <= 30) {
                return $row;
            }
            if ($size === 'above_30_marla_to_less_than_2_kanal' && $marla > 30 && $marla < 40) {
                return $row;
            }
            if ($size === '2_kanal_and_above' && $marla >= 40) {
                return $row;
            }
            if ($size === '5_to_less_than_10_marla' && $marla >= 5 && $marla < 10) {
                return $row;
            }
            if ($size === 'less_than_5_marla' && $marla < 5) {
                return $row;
            }
        }

        return null;
    }

    private function pickOpenSpaceBand(array $rows, float $marla): ?array
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $size = (string) ($row['plot_size'] ?? '');
            if ($size === '10_to_30_marla' && $marla >= 10 && $marla <= 30) {
                return $row;
            }
            if ($size === 'above_30_marla_to_less_than_2_kanal' && $marla > 30 && $marla < 40) {
                return $row;
            }
            if ($size === '2_kanal_to_less_than_4_kanal' && $marla >= 40 && $marla < 80) {
                return $row;
            }
            if ($size === '4_kanal_and_above' && $marla >= 80) {
                return $row;
            }
            if ($size === '5_to_less_than_10_marla' && $marla >= 5 && $marla < 10) {
                return $row;
            }
            if ($size === 'less_than_5_marla' && $marla < 5) {
                return $row;
            }
        }

        return null;
    }

    private function sideSetbackFromLabel(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $label = strtolower(trim($value));
        if ($label === 'not_required') {
            return 0.0;
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*ft/', $label, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function requiredValue(array $rule): mixed
    {
        foreach (['value_ft', 'value_percent', 'value_sqft', 'value'] as $key) {
            if (array_key_exists($key, $rule)) {
                return $rule[$key];
            }
        }

        return null;
    }

    private function requiredUnit(array $rule): ?string
    {
        if (array_key_exists('value_ft', $rule)) {
            return 'ft';
        }
        if (array_key_exists('value_percent', $rule)) {
            return '%';
        }
        if (array_key_exists('value_sqft', $rule)) {
            return 'sqft';
        }

        return $rule['unit'] ?? null;
    }

    private function actualForRule(string $ruleCode, array $geometry, array $textMetrics, array $textRefs, array $roomAreas, array $patternProfile): array
    {
        $strongTextPattern = (bool) data_get($patternProfile, 'pattern_family')
            && in_array((string) data_get($patternProfile, 'pattern_family'), ['text_table_near_polygon', 'text_table_measurement_plan', 'mixed_polygon_text_plan', 'text_enriched_plan'], true);
        $textualStatus = ($strongTextPattern || count($textRefs) >= 4) ? 'calculated' : 'needs_review';

        $geometryValue = function (string $key) use ($geometry): array {
            return [
                'value' => data_get($geometry, $key . '.value'),
                'status' => (string) data_get($geometry, $key . '.status', 'needs_review'),
                'source' => 'geometry',
            ];
        };
        $textValue = function (string $key) use ($textMetrics, $textualStatus): array {
            return [
                'value' => is_numeric($textMetrics[$key] ?? null) ? (float) $textMetrics[$key] : null,
                'status' => $textualStatus,
                'source' => 'textual_layer',
            ];
        };

        $pick = function (array $candidates): array {
            foreach ($candidates as $candidate) {
                if (is_numeric($candidate['value'] ?? null)) {
                    return $candidate;
                }
            }

            return ['value' => null, 'status' => 'needs_review', 'source' => 'unknown'];
        };

        $result = match ($ruleCode) {
            'SETBACK_FRONT' => $pick([$geometryValue('front_setback_ft'), $textValue('front_setback_ft')]),
            'SETBACK_REAR' => $pick([$geometryValue('rear_setback_ft'), $textValue('rear_setback_ft')]),
            'SETBACK_SIDE' => $pick([
                ['value' => $this->maximum([$geometry['left_setback_ft']['value'] ?? null, $geometry['right_setback_ft']['value'] ?? null]), 'status' => (string) data_get($geometry, 'left_setback_ft.status', 'needs_review'), 'source' => 'geometry'],
                ['value' => $this->maximum([$textMetrics['left_setback_ft'] ?? null, $textMetrics['right_setback_ft'] ?? null]), 'status' => $textualStatus, 'source' => 'textual_layer'],
            ]),
            'GROUND_COVERAGE' => $pick([$geometryValue('ground_coverage_percent'), $textValue('coverage_percent')]),
            'FAR_LIMIT' => $pick([$geometryValue('far'), $textValue('far')]),
            'MAX_STOREYS' => $pick([$geometryValue('storey_count'), $textValue('number_of_floors')]),
            'PORCH_LENGTH' => $geometryValue('porch_length_ft'),
            'REAR_TOILET_AREA' => [
                'value' => $geometry['rear_toilet_area_sqft']['value'] ?? $this->rearToiletAreaFromRoomAreas($roomAreas),
                'status' => (($geometry['rear_toilet_area_sqft']['status'] ?? 'needs_review') === 'needs_review' && $this->rearToiletAreaFromRoomAreas($roomAreas) !== null && $strongTextPattern) ? 'calculated' : ($geometry['rear_toilet_area_sqft']['status'] ?? 'needs_review'),
                'source' => $this->rearToiletAreaFromRoomAreas($roomAreas) !== null ? 'textual_room_areas' : 'geometry',
            ],
            default => [null, 'needs_review', 'unknown'],
        };

        if (($result['status'] ?? 'needs_review') === 'needs_review' && ($strongTextPattern || count($textRefs) >= 4) && is_numeric($result['value'] ?? null)) {
            $result['status'] = 'calculated';
        }

        return [
            $result['value'] ?? null,
            (string) ($result['status'] ?? 'needs_review'),
            (string) ($result['source'] ?? 'unknown'),
        ];
    }

    private function compare(?string $operator, mixed $required, mixed $actual, string $actualStatus): string
    {
        if ($actualStatus === 'needs_review' || $actual === null || $required === null) {
            return 'needs_review';
        }
        if (! is_numeric($actual) || ! is_numeric($required)) {
            return 'needs_review';
        }

        $a = (float) $actual;
        $r = (float) $required;

        $pass = match ($operator) {
            '<=' => $a <= $r,
            '>=' => $a >= $r,
            '==' => abs($a - $r) < 0.0001,
            '<' => $a < $r,
            '>' => $a > $r,
            default => null,
        };

        if ($pass === null) {
            return 'needs_review';
        }

        return $pass ? 'pass' : 'fail';
    }

    private function message(string $status, array $rule, mixed $required, mixed $actual, ?string $unit, ?string $sourceHint = null): string
    {
        if ($status === 'needs_review') {
            if ($actual !== null) {
                return 'Measurement verification required. The system calculated a draft value from ' . ($sourceHint ?: 'reconstructed CAD geometry') . ' and must be confirmed before pass/fail.';
            }

            return 'Manual review required because deterministic geometry inputs are incomplete or ambiguous.';
        }

        if ($sourceHint === 'textual_layer') {
            return sprintf(
                '%s: required %s %s %s, actual %s %s. Derived from text-based near-polygon mapping.',
                $status === 'pass' ? 'Passed' : 'Failed',
                (string) ($rule['operator'] ?? '?'),
                (string) $required,
                (string) ($unit ?? ''),
                (string) $actual,
                (string) ($unit ?? '')
            );
        }

        return sprintf(
            '%s: required %s %s %s, actual %s %s.',
            $status === 'pass' ? 'Passed' : 'Failed',
            (string) ($rule['operator'] ?? '?'),
            (string) $required,
            (string) ($unit ?? ''),
            (string) $actual,
            (string) ($unit ?? '')
        );
    }

    private function sourceEntities(MapDrawing $drawing, string $ruleCode): array
    {
        $schema = $this->schemaService->load();
        $layerNames = $schema['rules_to_layers'][$ruleCode] ?? [];
        $normalizedLayerNames = array_map(fn ($layer) => $this->normalizeLayerName((string) $layer), $layerNames);
        $semanticEntities = $this->semanticEntitiesForRule($ruleCode);

        return $drawing->entities()
            ->get()
            ->filter(function ($entity) use ($normalizedLayerNames, $semanticEntities) {
                if ($entity->semantic_entity && in_array($entity->semantic_entity, $semanticEntities, true)) {
                    return true;
                }

                return in_array($this->normalizeLayerName((string) $entity->layer_name), $normalizedLayerNames, true);
            })
            ->map(fn ($entity) => (string) $entity->layer_name . ':' . (string) $entity->handle)
            ->values()
            ->all();
    }

    private function semanticEntitiesForRule(string $ruleCode): array
    {
        return match ($ruleCode) {
            'SETBACK_FRONT',
            'SETBACK_REAR',
            'SETBACK_SIDE' => ['plot_boundary', 'ground_floor_covered_polygon', 'front_building_line', 'setback_lines'],
            'GROUND_COVERAGE' => ['plot_boundary', 'ground_floor_covered_polygon'],
            'FAR_LIMIT' => ['plot_boundary', 'ground_floor_covered_polygon', 'first_floor_covered_polygon', 'second_floor_covered_polygon'],
            'MAX_STOREYS',
            'MAX_HEIGHT',
            'STOREY_CLEAR_HEIGHT' => ['ground_floor_covered_polygon', 'first_floor_covered_polygon', 'second_floor_covered_polygon', 'annotations_and_dimensions'],
            'PORCH_LENGTH',
            'PORCH_ROOM_NOT_ALLOWED' => ['ground_porch_polygon'],
            'REAR_TOILET_AREA',
            'REAR_TOILET_HEIGHT' => ['ground_services_polygon', 'first_floor_services_polygon', 'second_floor_services_polygon'],
            'BASEMENT_VALIDATION' => ['basement_covered_polygon'],
            'STRUCTURAL_DRAWINGS' => ['structural'],
            'WATER_SUPPLY_SEWERAGE_DRAINAGE_PLAN',
            'ELECTRICITY_SAFETY_PLAN',
            'FIRE_SAFETY_PLAN' => ['utilities'],
            default => [],
        };
    }

    private function normalizeLayerName(string $layerName): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $layerName);
        $value = trim((string) $value);
        $value = preg_replace('/^\d+\s*[\.\-_\):\s]+\s*/', '', $value);
        $value = preg_replace('/[-_]+/', ' ', (string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return strtolower(trim((string) $value));
    }

    private function validateDocumentRules(MapDrawing $drawing, array &$results, array $rulesToLayers): void
    {
        foreach ([
            'BASEMENT_VALIDATION',
            'STRUCTURAL_DRAWINGS',
            'WATER_SUPPLY_SEWERAGE_DRAINAGE_PLAN',
            'ELECTRICITY_SAFETY_PLAN',
            'FIRE_SAFETY_PLAN',
            'MAX_HEIGHT',
            'STOREY_CLEAR_HEIGHT',
            'PORCH_ROOM_NOT_ALLOWED',
        ] as $code) {
            $exists = $this->hasRuleEvidence($drawing, $code, $rulesToLayers);
            $status = $exists ? 'warn' : 'needs_review';
            $row = [
                'rule_code' => $code,
                'rule_title' => str_replace('_', ' ', $code),
                'required_value' => 'configured',
                'actual_value' => $exists ? 'layer evidence found' : 'not found',
                'unit' => null,
                'status' => $status,
                'message' => $exists
                    ? 'Evidence layer found but manual confirmation is still required.'
                    : 'Required layer evidence not found. Manual review required.',
                'source_layers_json' => $rulesToLayers[$code] ?? [],
                'source_entities_json' => $this->sourceEntities($drawing, $code),
            ];
            MapRuleResult::create(array_merge($row, ['map_drawing_id' => $drawing->id]));
            $results[] = $row;
        }
    }

    private function hasRuleEvidence(MapDrawing $drawing, string $code, array $rulesToLayers): bool
    {
        $layers = $rulesToLayers[$code] ?? [];
        $normalizedExpected = array_values(array_filter(array_map(
            fn ($layer) => $this->normalizeLayerName((string) $layer),
            is_array($layers) ? $layers : []
        )));
        $semanticHints = $this->semanticEntitiesForRule($code);

        return $drawing->entities()
            ->get()
            ->contains(function ($entity) use ($normalizedExpected, $semanticHints): bool {
                if ($entity->mapping_status === 'ignored') {
                    return false;
                }
                if ($entity->semantic_entity && in_array((string) $entity->semantic_entity, $semanticHints, true)) {
                    return true;
                }
                $normalizedEntityLayer = $this->normalizeLayerName((string) $entity->layer_name);
                return in_array($normalizedEntityLayer, $normalizedExpected, true);
            });
    }

    private function minimum(array $values): ?float
    {
        $nums = array_values(array_filter($values, fn ($v) => is_numeric($v)));
        if (empty($nums)) {
            return null;
        }

        return (float) min($nums);
    }

    private function maximum(array $values): ?float
    {
        $nums = array_values(array_filter($values, fn ($v) => is_numeric($v)));
        if (empty($nums)) {
            return null;
        }

        return (float) max($nums);
    }

    private function sourceStatus(string $status, bool $strongTextPattern, array $textRefs): string
    {
        if ($status === 'needs_review' && ($strongTextPattern || count($textRefs) >= 4)) {
            return 'calculated';
        }

        return $status;
    }

    private function rearToiletAreaFromRoomAreas(array $roomAreas): ?float
    {
        $total = 0.0;
        $count = 0;
        foreach ($roomAreas as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = strtolower(trim((string) ($row['label'] ?? $row['category'] ?? '')));
            if ($label === '') {
                continue;
            }
            if (str_contains($label, 'toilet') || str_contains($label, 'bath')) {
                $area = is_numeric($row['area_sqft'] ?? null) ? (float) $row['area_sqft'] : 0.0;
                if ($area > 0) {
                    $total += $area;
                    $count++;
                }
            }
        }

        return $count > 0 ? round($total, 4) : null;
    }
}
