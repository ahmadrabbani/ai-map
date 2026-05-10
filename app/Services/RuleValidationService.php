<?php

namespace App\Services;

use App\Models\CadApprovalApplication;
use App\Models\CadApprovalPlan;

class RuleValidationService
{
    public function __construct(
        private readonly CadApprovalRuleService $ruleService,
        private readonly GeometryMeasurementService $measurementService
    ) {
    }

    public function validateApplication(CadApprovalApplication $application): array
    {
        $application->loadMissing('plans');

        $floorSummaries = [];
        foreach ($application->plans as $plan) {
            $floorSummaries[] = $this->validatePlan($application, $plan);
        }

        return [
            'final_status' => $this->ruleService->determineFinalStatus($application),
            'floors' => $floorSummaries,
        ];
    }

    public function validatePlan(CadApprovalApplication $application, CadApprovalPlan $plan): array
    {
        $analysis = $plan->analysis_result ?? [];
        $rules = is_array($analysis['rules'] ?? null) ? $analysis['rules'] : [];
        $failed = [];
        $manual = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if (($rule['pass'] ?? null) === false) {
                $failed[] = $rule;
            }

            if (($rule['pass'] ?? null) === null || (($rule['note'] ?? null) === 'manual_review')) {
                $manual[] = $rule;
            }
        }

        return [
            'floor_type' => $plan->floor_type,
            'label' => $plan->label,
            'status' => $plan->status,
            'is_required' => $plan->is_required,
            'measurements' => $this->measurementService->summarizePlan($plan),
            'failed_rules' => $failed,
            'manual_review_rules' => $manual,
        ];
    }
}
