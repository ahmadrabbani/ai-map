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

        $rules = $this->loadResidentialRules($drawing);
        $schema = $this->schemaService->load();
        $rulesToLayers = $schema['rules_to_layers'] ?? [];
        $results = [];

        foreach ($rules as $rule) {
            $ruleCode = (string) ($rule['id'] ?? '');
            $required = $this->requiredValue($rule);
            $unit = $this->requiredUnit($rule);
            [$actual, $actualStatus] = $this->actualForRule($ruleCode, $geometry);
            $status = $this->compare($rule['operator'] ?? null, $required, $actual, $actualStatus);

            $row = [
                'rule_code' => $ruleCode,
                'rule_title' => $rule['title'] ?? null,
                'required_value' => $required === null ? null : (string) $required,
                'actual_value' => $actual === null ? null : (string) $actual,
                'unit' => $unit,
                'status' => $status,
                'message' => $this->message($status, $rule, $required, $actual, $unit),
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
        $category = data_get($drawing->metadata_json, 'plot_size_category', '5_marla');
        $rules = $meta['plot_size_categories'][$category]['ground_floor_rules'] ?? [];

        return is_array($rules) ? $rules : [];
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

    private function actualForRule(string $ruleCode, array $geometry): array
    {
        return match ($ruleCode) {
            'SETBACK_FRONT' => [$geometry['front_setback_ft']['value'] ?? null, $geometry['front_setback_ft']['status'] ?? 'needs_review'],
            'SETBACK_REAR' => [$geometry['rear_setback_ft']['value'] ?? null, $geometry['rear_setback_ft']['status'] ?? 'needs_review'],
            'SETBACK_SIDE' => [$this->minimum([$geometry['left_setback_ft']['value'] ?? null, $geometry['right_setback_ft']['value'] ?? null]), $geometry['left_setback_ft']['status'] ?? 'needs_review'],
            'GROUND_COVERAGE' => [$geometry['ground_coverage_percent']['value'] ?? null, $geometry['ground_coverage_percent']['status'] ?? 'needs_review'],
            'FAR_LIMIT' => [$geometry['far']['value'] ?? null, $geometry['far']['status'] ?? 'needs_review'],
            'MAX_STOREYS' => [$geometry['storey_count']['value'] ?? null, $geometry['storey_count']['status'] ?? 'needs_review'],
            'PORCH_LENGTH' => [$geometry['porch_length_ft']['value'] ?? null, $geometry['porch_length_ft']['status'] ?? 'needs_review'],
            'REAR_TOILET_AREA' => [$geometry['rear_toilet_area_sqft']['value'] ?? null, $geometry['rear_toilet_area_sqft']['status'] ?? 'needs_review'],
            default => [null, 'needs_review'],
        };
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

    private function message(string $status, array $rule, mixed $required, mixed $actual, ?string $unit): string
    {
        if ($status === 'needs_review') {
            if ($actual !== null) {
                return 'Measurement verification required. The system calculated a draft value, but it was based on reconstructed or approximate CAD geometry and must be confirmed before pass/fail.';
            }

            return 'Manual review required because deterministic geometry inputs are incomplete or ambiguous.';
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
}
