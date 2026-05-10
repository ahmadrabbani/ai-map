<?php

namespace App\Services\MapApproval;

use App\Models\MapDrawing;

class MapApprovalReportService
{
    public function generate(MapDrawing $drawing): array
    {
        $drawing->load(['entities', 'geometryResults', 'ruleResults']);

        $mapping = [];
        foreach ($drawing->entities as $entity) {
            if (! $entity->semantic_entity || $entity->mapping_status === 'ignored') {
                continue;
            }
            if (! isset($mapping[$entity->semantic_entity])) {
                $mapping[$entity->semantic_entity] = [
                    'handle' => $entity->handle,
                    'layer' => $entity->layer_name,
                    'status' => $entity->mapping_status,
                    'confidence' => $entity->confidence_score,
                ];
            }
        }

        $geometry = [];
        foreach ($drawing->geometryResults as $row) {
            $geometry[$row->key] = $this->castNumber($row->value);
        }

        $rules = $drawing->ruleResults->map(function ($result) {
            return [
                'rule_code' => $result->rule_code,
                'status' => $result->status,
                'required' => $result->required_value,
                'actual' => $result->actual_value,
                'source_entities' => collect($result->source_entities_json ?? [])
                    ->map(fn ($h) => (string) $h)
                    ->values()
                    ->all(),
                'message' => $result->message,
            ];
        })->values()->all();

        $missingRequired = [];
        foreach (['plot_boundary', 'ground_floor_covered_polygon'] as $required) {
            if (! isset($mapping[$required])) {
                $missingRequired[] = $required;
            }
        }

        $needsReviewRules = collect($rules)->where('status', 'needs_review')->count();
        $failedRules = collect($rules)->where('status', 'fail')->count();

        $status = 'ready_for_submission';
        if ($failedRules > 0) {
            $status = 'needs_correction';
        } elseif (! empty($missingRequired) || $needsReviewRules > 0) {
            $status = 'needs_expert_review';
        }

        return [
            'drawing_id' => $drawing->id,
            'status' => $status,
            'mapping_status' => $drawing->mapping_status,
            'validation_status' => $drawing->validation_status,
            'mapping' => $mapping,
            'geometry' => $geometry,
            'rules' => $rules,
            'missing_required_entities' => $missingRequired,
            'expert_review_reasons' => $this->reviewReasons($missingRequired, $needsReviewRules),
            'ready_for_submission' => $status === 'ready_for_submission',
        ];
    }

    private function reviewReasons(array $missingRequired, int $needsReviewRules): array
    {
        $reasons = [];
        foreach ($missingRequired as $missing) {
            $reasons[] = $missing . '_missing';
        }
        if ($needsReviewRules > 0) {
            $reasons[] = 'one_or_more_rules_need_manual_review';
        }

        return $reasons;
    }

    private function castNumber(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }
}
