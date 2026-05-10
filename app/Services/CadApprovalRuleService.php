<?php

namespace App\Services;

use App\Models\CadApprovalApplication;
use App\Models\CadApprovalPlan;

class CadApprovalRuleService
{
    public function loadRulesMeta(): array
    {
        $path = base_path('rules/approval_rules_meta.json');

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode(file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function getRequiredPlanTypes(CadApprovalApplication $application): array
    {
        $meta = $this->loadRulesMeta();
        $requiredPlans = $meta['required_plans'] ?? [];
        $required = [];

        foreach ($requiredPlans as $floorType => $config) {
            if (! is_array($config)) {
                continue;
            }

            if ($this->isPlanRequired($application, $floorType, $config)) {
                $required[] = $floorType;
            }
        }

        if (! in_array('ground', $required, true)) {
            $required[] = 'ground';
        }

        return array_values(array_unique($required));
    }

    public function getOptionalPlanTypes(CadApprovalApplication $application): array
    {
        $required = $this->getRequiredPlanTypes($application);
        $all = CadApprovalPlan::FLOOR_TYPES;
        $meta = $this->loadRulesMeta();

        foreach (array_keys($meta['required_plans'] ?? []) as $floorType) {
            if (! in_array($floorType, $all, true)) {
                $all[] = $floorType;
            }
        }

        return array_values(array_diff($all, $required));
    }

    public function isBasementRequired(CadApprovalApplication $application): bool
    {
        $meta = $this->loadRulesMeta();
        $categories = $meta['plot_size_categories'] ?? [];
        $category = $categories[$application->plot_size_category] ?? [];

        if (($category['basement_required'] ?? false) === true) {
            return true;
        }

        if ($application->plot_size_category !== 'custom') {
            return false;
        }

        $threshold = $category['basement_required_when_area_gt_sqft'] ?? null;

        return $threshold !== null
            && $application->plot_area_sqft !== null
            && (float) $application->plot_area_sqft > (float) $threshold;
    }

    public function syncRequiredPlans(CadApprovalApplication $application): void
    {
        $meta = $this->loadRulesMeta();
        $required = $this->getRequiredPlanTypes($application);
        $labels = $this->planLabels($meta);
        $all = array_values(array_unique(array_merge(
            CadApprovalPlan::FLOOR_TYPES,
            array_keys($labels)
        )));

        foreach ($all as $floorType) {
            $plan = $application->plans()->firstOrNew([
                'floor_type' => $floorType,
            ]);

            $plan->label = $labels[$floorType] ?? $this->defaultLabel($floorType);
            $plan->is_required = in_array($floorType, $required, true);

            if (! $plan->exists) {
                $plan->status = 'pending';
            }

            $plan->save();
        }
    }

    public function canGenerateFinalReport(CadApprovalApplication $application): bool
    {
        $application->loadMissing('plans');

        foreach ($this->getRequiredPlanTypes($application) as $floorType) {
            $plan = $application->plans->firstWhere('floor_type', $floorType);

            if (! $plan || ! $plan->is_uploaded) {
                return false;
            }
        }

        return true;
    }

    public function determineFinalStatus(CadApprovalApplication $application): string
    {
        $application->loadMissing('plans');
        $requiredTypes = $this->getRequiredPlanTypes($application);
        $requiredPlans = $application->plans->whereIn('floor_type', $requiredTypes);

        foreach ($requiredTypes as $floorType) {
            $plan = $requiredPlans->firstWhere('floor_type', $floorType);

            if (! $plan || ! $plan->is_uploaded) {
                return 'incomplete';
            }

            if (in_array($plan->status, ['pending', 'uploaded', 'processing'], true)) {
                return 'incomplete';
            }
        }

        if ($requiredPlans->contains(fn (CadApprovalPlan $plan) => $plan->status === 'needs_expert_review')) {
            return 'needs_expert_review';
        }

        if ($application->plans->contains(fn (CadApprovalPlan $plan) => $plan->is_uploaded && $this->hasBlockingFailure($plan))) {
            return 'needs_correction';
        }

        if ($application->plans->contains(fn (CadApprovalPlan $plan) => $this->hasManualNotes($plan))) {
            return 'ready_for_submission_with_manual_notes';
        }

        return 'ready_for_submission';
    }

    public function summarizeApplication(CadApprovalApplication $application): array
    {
        $application->loadMissing('plans');
        $meta = $this->loadRulesMeta();
        $required = $this->getRequiredPlanTypes($application);
        $optional = $this->getOptionalPlanTypes($application);
        $plotCategoryMeta = $meta['plot_size_categories'][$application->plot_size_category] ?? [];
        $categoryRules = $this->getCategoryRules($application, 'ground');

        return [
            'ruleset' => $application->ruleset,
            'building_type' => $application->building_type,
            'plot_size_category' => $application->plot_size_category,
            'plot_size_label' => $plotCategoryMeta['label'] ?? $application->plot_size_category,
            'required_plan_types' => $required,
            'optional_plan_types' => $optional,
            'required_uploads_complete' => $this->canGenerateFinalReport($application),
            'basement_required' => $this->isBasementRequired($application),
            'final_status' => $this->determineFinalStatus($application),
            'ruleset_overview' => $this->getRulesetOverview($application),
            'active_ground_rules' => $categoryRules,
        ];
    }

    public function planLabel(string $floorType): string
    {
        $meta = $this->loadRulesMeta();
        $labels = $this->planLabels($meta);

        return $labels[$floorType] ?? $this->defaultLabel($floorType);
    }

    public function getRulesetOverview(?CadApprovalApplication $application = null): array
    {
        $meta = $this->loadRulesMeta();

        return [
            'ruleset' => $meta['ruleset'] ?? null,
            'building_type' => $meta['building_type'] ?? null,
            'source_documents' => $meta['source_documents'] ?? [],
            'applicability_scope' => $meta['applicability_scope'] ?? [],
            'canonical_units' => $meta['canonical_units'] ?? [],
            'definitions' => $meta['definitions'] ?? [],
            'rule_types' => $meta['rule_types'] ?? [],
            'evaluation_flow' => $meta['evaluation_flow'] ?? [],
            'comparison_matrix' => $meta['comparison_matrix'] ?? [],
            'implementation_assumptions' => $meta['implementation_assumptions'] ?? [],
            'plot_size_category' => $application?->plot_size_category,
            'plot_size_label' => $application
                ? (($meta['plot_size_categories'][$application->plot_size_category]['label'] ?? $application->plot_size_category))
                : null,
        ];
    }

    public function getCategoryRules(CadApprovalApplication $application, ?string $floorType = null): array
    {
        $meta = $this->loadRulesMeta();
        $categoryMeta = $meta['plot_size_categories'][$application->plot_size_category] ?? [];
        $rules = [];

        if ($floorType === null || $floorType === 'ground') {
            foreach (($categoryMeta['ground_floor_rules'] ?? []) as $rule) {
                if (is_array($rule)) {
                    $rules[] = $this->normalizeLegacyRule($rule, 'ground');
                }
            }
        }

        foreach (($meta['normalized_rules'] ?? []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if (! $this->normalizedRuleApplies($application, $rule, $floorType)) {
                continue;
            }

            $rules[] = [
                'id' => $rule['id'] ?? null,
                'title' => $rule['title'] ?? null,
                'rule_type' => $rule['rule_type'] ?? null,
                'scope' => $rule['scope'] ?? null,
                'evaluation_mode' => $rule['evaluation']['mode'] ?? null,
                'result_fields' => $rule['evaluation']['result_fields'] ?? [],
                'bands' => $rule['evaluation']['bands'] ?? [],
                'requirements' => $rule['evaluation']['requirements'] ?? [],
                'source_refs' => $rule['source_refs'] ?? [],
                'external_code_refs' => $rule['external_code_refs'] ?? [],
                'notes' => $rule['notes'] ?? null,
            ];
        }

        return $rules;
    }

    public function getSubmissionDocumentRequirements(): array
    {
        return $this->loadRulesMeta()['required_documents'] ?? [];
    }

    public function buildPlanTextualRecord(CadApprovalPlan $plan): array
    {
        $analysis = $plan->analysis_result ?? [];
        $areas = $analysis['areas'] ?? [];
        $setbacks = $analysis['setbacks_ft'] ?? [];
        $dimensions = $analysis['dimensions'] ?? [];

        $textual = [
            'floor_type' => $plan->floor_type,
            'label' => $plan->label,
            'status' => $plan->status,
            'analysis_status' => $analysis['status'] ?? null,
            'message' => $analysis['message'] ?? null,
            'plot_handle_used' => data_get($analysis, 'polygon_discovery.plot_handle_used'),
            'floor_handles_used' => data_get($analysis, 'polygon_discovery.floor_handles_used', []),
            'plot_layer_used' => data_get($analysis, 'polygon_discovery.plot_layer_used'),
            'building_layer_used' => data_get($analysis, 'polygon_discovery.building_layer_used'),
            'warnings' => $analysis['warnings'] ?? [],
            'resolver' => $analysis['resolver'] ?? [],
            'debug' => $analysis['debug'] ?? [],
        ];

        $measurable = [
            'areas' => $areas,
            'setbacks_ft' => $setbacks,
            'dimensions' => $dimensions,
        ];

        $training = [
            'training_events' => $analysis['training_events'] ?? [],
            'entity_features_count' => is_array($analysis['entity_features'] ?? null) ? count($analysis['entity_features']) : 0,
            'polygon_discovery' => $analysis['polygon_discovery'] ?? [],
        ];

        return [
            'textual' => $textual,
            'measurable' => $measurable,
            'training' => $training,
        ];
    }

    private function isPlanRequired(CadApprovalApplication $application, string $floorType, array $config): bool
    {
        if ($floorType === 'ground') {
            return true;
        }

        if (($config['required'] ?? false) === true) {
            return true;
        }

        if ($floorType === 'basement') {
            return $this->isBasementRequired($application);
        }

        $requiredWhen = $config['required_when'] ?? [];

        if (($requiredWhen['plot_size_category'] ?? null) !== null) {
            return $requiredWhen['plot_size_category'] === $application->plot_size_category;
        }

        return false;
    }

    private function planLabels(array $meta): array
    {
        $labels = [
            'site' => 'Site Plan',
        ];

        foreach (($meta['required_plans'] ?? []) as $floorType => $config) {
            if (is_array($config) && ! empty($config['label'])) {
                $labels[$floorType] = (string) $config['label'];
            }
        }

        return $labels;
    }

    private function defaultLabel(string $floorType): string
    {
        return match ($floorType) {
            'ground' => 'Ground Floor Plan',
            'basement' => 'Basement Plan',
            'first' => 'First Floor Plan',
            'second' => 'Second Floor Plan',
            'roof' => 'Roof Plan',
            'site' => 'Site Plan',
            'services' => 'Services Plan',
            default => ucwords(str_replace('_', ' ', $floorType)) . ' Plan',
        };
    }

    private function normalizeLegacyRule(array $rule, string $floorType): array
    {
        return [
            'id' => $rule['id'] ?? null,
            'title' => $rule['title'] ?? null,
            'rule_type' => $rule['type'] ?? null,
            'scope' => 'floor',
            'evaluation_mode' => 'boolean_check',
            'requirements' => [[
                'subject' => strtolower((string) ($rule['type'] ?? 'measurement')),
                'operator' => $rule['operator'] ?? null,
                'value' => $rule['value'] ?? $rule['value_ft'] ?? $rule['value_percent'] ?? $rule['value_sqft'] ?? null,
                'unit' => $this->detectRuleUnit($rule),
            ]],
            'source_refs' => $rule['source_refs'] ?? [],
            'notes' => $rule['description'] ?? null,
            'floor_selector' => $floorType,
        ];
    }

    private function detectRuleUnit(array $rule): ?string
    {
        return match (true) {
            array_key_exists('value_ft', $rule) => 'ft',
            array_key_exists('value_percent', $rule) => '%',
            array_key_exists('value_sqft', $rule) => 'sqft',
            default => $rule['unit'] ?? null,
        };
    }

    private function normalizedRuleApplies(CadApprovalApplication $application, array $rule, ?string $floorType): bool
    {
        $applicability = $rule['applicability'] ?? [];
        $selectorType = data_get($applicability, 'floor_selector.type', 'all');

        if ($floorType !== null && $selectorType !== 'all') {
            $matchesFloor = match ($selectorType) {
                'ground' => $floorType === 'ground',
                'basement' => $floorType === 'basement',
                'upper' => in_array($floorType, ['first', 'second', 'roof'], true),
                'service' => $floorType === 'services',
                default => true,
            };

            if (! $matchesFloor) {
                return false;
            }
        }

        $bands = data_get($rule, 'evaluation.bands', []);
        if (is_array($bands) && ! empty($bands)) {
            foreach ($bands as $band) {
                $matchesBand = true;

                foreach ((data_get($band, 'when.all_of', [])) as $operand) {
                    if (($operand['subject'] ?? null) !== 'plot_area_sqft') {
                        continue;
                    }

                    if (! $this->operandMatches((float) ($application->plot_area_sqft ?? 0), $operand)) {
                        $matchesBand = false;
                        break;
                    }
                }

                if ($matchesBand) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function operandMatches(float $actual, array $operand): bool
    {
        $value = (float) ($operand['value'] ?? 0);

        return match ($operand['operator'] ?? null) {
            '<' => $actual < $value,
            '<=' => $actual <= $value,
            '>' => $actual > $value,
            '>=' => $actual >= $value,
            '=', '==' => $actual == $value,
            default => true,
        };
    }

    private function hasBlockingFailure(CadApprovalPlan $plan): bool
    {
        if ($plan->status === 'failed') {
            return true;
        }

        $rules = $plan->analysis_result['rules'] ?? [];

        if (! is_array($rules)) {
            return false;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $pass = $rule['pass'] ?? null;
            $severity = strtolower((string) ($rule['severity'] ?? 'blocking'));
            $type = strtolower((string) ($rule['type'] ?? ''));

            if ($pass === false && ! in_array($severity, ['advisory', 'manual'], true) && ! str_contains($type, 'advisory')) {
                return true;
            }
        }

        return false;
    }

    private function hasManualNotes(CadApprovalPlan $plan): bool
    {
        if ($plan->status === 'needs_expert_review' && ! $plan->is_required) {
            return true;
        }

        $rules = $plan->analysis_result['rules'] ?? [];

        if (! is_array($rules)) {
            return false;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $pass = $rule['pass'] ?? null;
            $severity = strtolower((string) ($rule['severity'] ?? ''));
            $type = strtolower((string) ($rule['type'] ?? ''));

            if ($pass === false && (in_array($severity, ['advisory', 'manual'], true) || str_contains($type, 'advisory'))) {
                return true;
            }

            if ($pass === null && in_array($severity, ['manual', 'review'], true)) {
                return true;
            }
        }

        return false;
    }
}
