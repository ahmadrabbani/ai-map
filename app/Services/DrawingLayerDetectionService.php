<?php

namespace App\Services;

use App\Models\CadApprovalPlan;

class DrawingLayerDetectionService
{
    public function __construct(
        private readonly LayerGuidelineService $guidelineService
    ) {
    }

    public function validatePlanLayers(CadApprovalPlan $plan): array
    {
        $analysis = $plan->analysis_result ?? [];
        $detectedLayers = $this->extractDetectedLayers($analysis, $plan);
        $guidelines = $this->guidelineService->loadGuidelines();
        $aliases = $this->guidelineService->aliasesMap();
        $expectedLayers = $this->expectedLayersForPlan($plan, $guidelines);

        $normalizedDetected = [];
        foreach ($detectedLayers as $layer) {
            $normalizedDetected[] = [
                'original' => $layer,
                'normalized' => $aliases[strtolower($layer)] ?? $layer,
            ];
        }

        $detectedCanonical = array_values(array_unique(array_map(
            fn (array $item) => $item['normalized'],
            $normalizedDetected
        )));

        $requiredNames = array_values(array_filter(array_map(fn ($item) => $item['name'] ?? null, $expectedLayers['required'])));
        $optionalNames = array_values(array_filter(array_map(fn ($item) => $item['name'] ?? null, $expectedLayers['optional'])));
        $knownNames = array_values(array_unique(array_merge(
            $requiredNames,
            $optionalNames,
            array_map(fn ($item) => $item['name'] ?? null, $guidelines['required_layers'] ?? []),
            array_map(fn ($item) => $item['name'] ?? null, $guidelines['optional_layers'] ?? []),
        )));

        $missingRequired = array_values(array_diff($requiredNames, $detectedCanonical));
        $unknown = array_values(array_filter(
            $detectedLayers,
            fn ($layer) => ! in_array(($aliases[strtolower($layer)] ?? $layer), $knownNames, true)
        ));

        $matchedAliases = array_values(array_filter($normalizedDetected, fn (array $item) => $item['original'] !== $item['normalized']));
        $confidence = $this->confidenceScore($missingRequired, $unknown, $matchedAliases, count($detectedCanonical));
        $fallbackUsed = empty($detectedLayers) || ! empty($unknown);

        return [
            'status' => empty($missingRequired) && $confidence >= 70 && ! $fallbackUsed ? 'ok' : 'needs_expert_review',
            'validation_mode' => $fallbackUsed ? 'layer_guideline_with_fallback' : 'layer_guideline_primary',
            'floor_type' => $plan->floor_type,
            'found_layers' => $detectedLayers,
            'detected_layers' => $normalizedDetected,
            'expected_required_layers' => $requiredNames,
            'expected_optional_layers' => $optionalNames,
            'missing_required_layers' => $missingRequired,
            'unknown_layers' => $unknown,
            'matched_aliases' => $matchedAliases,
            'suggested_corrections' => $this->suggestCorrections($missingRequired, $unknown, $aliases),
            'confidence_score' => $confidence,
            'guideline_source' => 'storage/app/rules/layer_guidelines.json',
        ];
    }

    private function extractDetectedLayers(array $analysis, CadApprovalPlan $plan): array
    {
        $layers = [];

        foreach (($analysis['entity_features'] ?? []) as $feature) {
            if (is_array($feature) && ! empty($feature['layer'])) {
                $layers[] = (string) $feature['layer'];
            }
        }

        foreach (($analysis['polygon_discovery']['sample_polygons'] ?? []) as $polygon) {
            if (is_array($polygon) && ! empty($polygon['raw_layer'])) {
                $layers[] = (string) $polygon['raw_layer'];
            }
        }

        if (empty($layers) && is_array($plan->detected_layers_json ?? null)) {
            return $plan->detected_layers_json;
        }

        $layers = array_values(array_unique(array_filter($layers)));
        sort($layers);

        return $layers;
    }

    private function suggestCorrections(array $missingRequired, array $unknown, array $aliases): array
    {
        $suggestions = [];

        foreach ($missingRequired as $layer) {
            $suggestions[] = 'Add or rename a layer to match required guideline layer ' . $layer . '.';
        }

        foreach ($unknown as $layer) {
            $normalized = $aliases[strtolower($layer)] ?? null;
            $suggestions[] = $normalized
                ? 'Rename "' . $layer . '" to canonical layer ' . $normalized . '.'
                : 'Review unknown layer "' . $layer . '" and map it to an official guideline layer.';
        }

        return array_values(array_unique($suggestions));
    }

    private function confidenceScore(array $missingRequired, array $unknown, array $matchedAliases, int $detectedCount): float
    {
        $score = 100.0;
        $score -= count($missingRequired) * 20;
        $score -= count($unknown) * 8;
        $score -= count($matchedAliases) * 3;

        if ($detectedCount === 0) {
            $score = 0;
        }

        return max(0.0, min(100.0, $score));
    }

    private function expectedLayersForPlan(CadApprovalPlan $plan, array $guidelines): array
    {
        $purposeMap = [
            'ground' => ['plot_boundary', 'ground_floor', 'setback', 'dimensions'],
            'basement' => ['basement', 'dimensions'],
            'first' => ['first_floor', 'dimensions'],
            'second' => ['second_floor', 'dimensions'],
            'roof' => ['dimensions'],
            'site' => ['plot_boundary', 'setback', 'dimensions'],
            'services' => ['services', 'dimensions'],
        ];

        $purposes = $purposeMap[$plan->floor_type] ?? ['dimensions'];

        $matchesPurpose = static function (array $layer) use ($purposes): bool {
            return in_array($layer['purpose'] ?? null, $purposes, true);
        };

        $required = array_values(array_filter(
            array_merge($guidelines['required_layers'] ?? [], $guidelines['optional_layers'] ?? []),
            function (array $layer) use ($matchesPurpose) {
                return $matchesPurpose($layer) && (! array_key_exists('required', $layer) || (bool) $layer['required']);
            }
        ));

        $optional = array_values(array_filter(
            array_merge($guidelines['required_layers'] ?? [], $guidelines['optional_layers'] ?? []),
            function (array $layer) use ($matchesPurpose) {
                return $matchesPurpose($layer) && ! ((bool) ($layer['required'] ?? false));
            }
        ));

        return [
            'required' => $required,
            'optional' => $optional,
        ];
    }
}
