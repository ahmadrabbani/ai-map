<?php

namespace App\Services;

use App\Models\CadApprovalApplication;

class ReportGenerationService
{
    public function __construct(
        private readonly CadApprovalReportService $reportService,
        private readonly RuleValidationService $ruleValidationService,
        private readonly ExpertMarkingService $expertMarkingService
    ) {
    }

    public function generate(CadApprovalApplication $application): array
    {
        $report = $this->reportService->buildReport($application);
        $report['rule_validation'] = $this->ruleValidationService->validateApplication($application);
        $report['expert_marking_summary'] = $this->expertMarkingService->summary($application);

        return $report;
    }
}
