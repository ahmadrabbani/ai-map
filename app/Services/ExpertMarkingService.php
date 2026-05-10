<?php

namespace App\Services;

use App\Models\CadApprovalApplication;
use App\Models\CadApprovalPlan;
use App\Models\CadExpertMarking;

class ExpertMarkingService
{
    public function saveMarking(CadApprovalApplication $application, array $data, ?CadApprovalPlan $plan = null): CadExpertMarking
    {
        return CadExpertMarking::updateOrCreate(
            [
                'cad_approval_application_id' => $application->id,
                'cad_approval_plan_id' => $plan?->id,
                'floor_type' => $data['floor_type'] ?? $plan?->floor_type,
                'marking_type' => $data['marking_type'],
            ],
            [
                'geometry_json' => $data['geometry_json'] ?? null,
                'measured_area' => $data['measured_area'] ?? null,
                'measured_length' => $data['measured_length'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]
        );
    }

    public function summary(CadApprovalApplication $application): array
    {
        return $application->expertMarkings->map(function (CadExpertMarking $marking) {
            return [
                'floor_type' => $marking->floor_type,
                'marking_type' => $marking->marking_type,
                'measured_area' => $marking->measured_area,
                'measured_length' => $marking->measured_length,
                'remarks' => $marking->remarks,
                'created_by' => $marking->created_by,
                'updated_at' => optional($marking->updated_at)->toIso8601String(),
            ];
        })->all();
    }
}
