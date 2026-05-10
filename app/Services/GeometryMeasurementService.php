<?php

namespace App\Services;

use App\Models\CadApprovalPlan;

class GeometryMeasurementService
{
    public function summarizePlan(CadApprovalPlan $plan): array
    {
        $analysis = $plan->analysis_result ?? [];

        return [
            'plot_area_sqft' => data_get($analysis, 'areas.plot_sqft'),
            'building_footprint_area_sqft' => data_get($analysis, 'areas.ground_sqft'),
            'ground_floor_area_sqft' => data_get($analysis, 'areas.ground_floor_sqft'),
            'first_floor_area_sqft' => data_get($analysis, 'areas.first_sqft'),
            'second_floor_area_sqft' => data_get($analysis, 'areas.second_sqft'),
            'total_floor_area_sqft' => data_get($analysis, 'areas.total_floor_sqft'),
            'coverage_percent' => data_get($analysis, 'areas.coverage_percent'),
            'far' => data_get($analysis, 'areas.far'),
            'storeys_detected' => data_get($analysis, 'areas.storeys_detected'),
            'front_setback_ft' => data_get($analysis, 'setbacks_ft.front'),
            'rear_setback_ft' => data_get($analysis, 'setbacks_ft.rear'),
            'left_setback_ft' => data_get($analysis, 'setbacks_ft.left'),
            'right_setback_ft' => data_get($analysis, 'setbacks_ft.right'),
            'dimensions' => $analysis['dimensions'] ?? [],
        ];
    }
}
