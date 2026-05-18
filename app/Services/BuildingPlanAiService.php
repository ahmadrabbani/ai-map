<?php

namespace App\Services;

use App\Models\PublicBuildingPlanApplication;

class BuildingPlanAiService
{
    public function __construct(private readonly PublicBuildingPlanAiService $publicBuildingPlanAiService)
    {
    }

    public function generateReport(PublicBuildingPlanApplication $application): array
    {
        return $this->publicBuildingPlanAiService->generateReport($application);
    }
}
