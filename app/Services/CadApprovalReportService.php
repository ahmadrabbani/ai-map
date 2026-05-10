<?php

namespace App\Services;

use App\Models\CadApprovalApplication;
use App\Models\CadApprovalPlan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CadApprovalReportService
{
    public function __construct(
        private readonly CadApprovalRuleService $ruleService
    ) {
    }

    public function buildReport(CadApprovalApplication $application): array
    {
        $application->loadMissing('plans');

        $summary = $this->ruleService->summarizeApplication($application);
        $requiredTypes = $summary['required_plan_types'];
        $finalStatus = $summary['final_status'];
        $rulesetOverview = $this->ruleService->getRulesetOverview($application);
        $submissionRequirements = $this->ruleService->getSubmissionDocumentRequirements();
        $uploadedPlans = $application->plans->filter(fn (CadApprovalPlan $plan) => $plan->is_uploaded)->values();
        $expertItems = $application->plans
            ->filter(fn (CadApprovalPlan $plan) => $plan->status === 'needs_expert_review')
            ->map(fn (CadApprovalPlan $plan) => [
                'floor_type' => $plan->floor_type,
                'label' => $plan->label,
                'message' => $plan->analysis_result['message'] ?? 'Expert review is required.',
                'error_code' => $plan->analysis_result['error_code'] ?? null,
                'recommended_next_step' => $plan->analysis_result['recommended_next_step'] ?? null,
            ])
            ->values()
            ->all();

        $ruleFailures = [];
        $floorAnalysis = [];
        $processingStatus = [];
        $textualRecords = [];
        $measurableRecords = [];
        $trainingRecords = [];

        foreach ($application->plans as $plan) {
            $analysis = $plan->analysis_result ?? [];
            $rules = is_array($analysis['rules'] ?? null) ? $analysis['rules'] : [];
            $failedRules = [];
            $planRecord = $this->ruleService->buildPlanTextualRecord($plan);

            foreach ($rules as $rule) {
                if (is_array($rule) && (($rule['pass'] ?? null) === false)) {
                    $failedRules[] = [
                        'id' => $rule['id'] ?? null,
                        'title' => $rule['title'] ?? null,
                        'details' => $rule['details'] ?? null,
                        'severity' => $rule['severity'] ?? 'blocking',
                    ];
                }
            }

            if (! empty($failedRules)) {
                $ruleFailures[$plan->floor_type] = $failedRules;
            }

            $floorAnalysis[] = [
                'floor_type' => $plan->floor_type,
                'label' => $plan->label,
                'required' => $plan->is_required,
                'uploaded' => $plan->is_uploaded,
                'status' => $plan->status,
                'analysis_status' => $analysis['status'] ?? null,
                'message' => $analysis['message'] ?? null,
                'rule_count' => count($rules),
                'failed_rule_count' => count($failedRules),
            ];

            $processingStatus[] = [
                'floor_type' => $plan->floor_type,
                'label' => $plan->label,
                'processing_status' => $plan->status,
                'cad_status' => $analysis['status'] ?? null,
                'overlay_pdf_path' => $plan->overlay_pdf_path,
                'drawing_pdf_path' => $plan->drawing_pdf_path,
            ];

            $textualRecords[] = $planRecord['textual'];
            $measurableRecords[] = $planRecord['measurable'];
            $trainingRecords[] = $planRecord['training'];
        }

        $nextSteps = match ($finalStatus) {
            'incomplete' => [
                'Upload and process all mandatory plans before final submission.',
            ],
            'needs_expert_review' => [
                'Open expert marking for the flagged plans and rerun analysis with labels.',
                'Review polygon discovery data and manually confirm plot/floor handles.',
            ],
            'needs_correction' => [
                'Revise the failed rule items in the CAD drawings and re-upload or rerun the affected plans.',
            ],
            'ready_for_submission_with_manual_notes' => [
                'Proceed with internal review while keeping the manual notes attached to the case file.',
            ],
            default => [
                'Proceed to internal processing and formal approval submission.',
            ],
        };

        return [
            'generated_at' => now()->toIso8601String(),
            'application_id' => $application->id,
            'final_status' => $finalStatus,
            'ruleset_overview' => $rulesetOverview,
            'application_details' => [
                'applicant_name' => $application->applicant_name,
                'contact_number' => $application->contact_number,
                'plot_number' => $application->plot_number,
                'scheme' => $application->scheme,
                'phase' => $application->phase,
                'block' => $application->block,
                'plot_size_category' => $summary['plot_size_label'],
                'plot_area_sqft' => $application->plot_area_sqft,
                'building_type' => $application->building_type,
                'ruleset' => $application->ruleset,
                'application_status' => $application->status,
            ],
            'required_optional_plan_checklist' => $application->plans->map(fn (CadApprovalPlan $plan) => [
                'floor_type' => $plan->floor_type,
                'label' => $plan->label,
                'required' => $plan->is_required,
                'uploaded' => $plan->is_uploaded,
                'status' => $plan->status,
            ])->values()->all(),
            'basement_requirement_decision' => [
                'required' => $summary['basement_required'],
                'plot_size_category' => $summary['plot_size_label'],
                'reason' => $summary['basement_required']
                    ? 'Basement is mandatory for this plot category under the configured rules.'
                    : 'Basement is optional for this plot category under the configured rules.',
            ],
            'uploaded_plans' => $uploadedPlans->map(fn (CadApprovalPlan $plan) => [
                'floor_type' => $plan->floor_type,
                'label' => $plan->label,
                'status' => $plan->status,
                'overlay_pdf_path' => $plan->overlay_pdf_path,
                'drawing_pdf_path' => $plan->drawing_pdf_path,
            ])->all(),
            'cad_processing_status' => $processingStatus,
            'floor_wise_analysis' => $floorAnalysis,
            'rule_compliance_summary' => [
                'required_plan_types' => $requiredTypes,
                'failed_rules_by_floor' => $ruleFailures,
                'active_ground_rules' => $summary['active_ground_rules'] ?? [],
            ],
            'textual_records' => $textualRecords,
            'measurable_records' => $measurableRecords,
            'training_records' => $trainingRecords,
            'expert_review_required_items' => $expertItems,
            'submission_document_requirements' => $submissionRequirements,
            'finalized_system' => [
                'evaluation_flow' => $rulesetOverview['evaluation_flow'] ?? [],
                'comparison_matrix' => $rulesetOverview['comparison_matrix'] ?? [],
                'implementation_assumptions' => $rulesetOverview['implementation_assumptions'] ?? [],
            ],
            'final_recommendation' => $this->finalRecommendation($finalStatus),
            'next_steps' => $nextSteps,
        ];
    }

    public function generatePdf(CadApprovalApplication $application): ?string
    {
        if (! app()->bound('dompdf.wrapper')) {
            return null;
        }

        $report = $application->final_report_json ?? $this->buildReport($application);
        $pdf = app('dompdf.wrapper');
        $pdf->loadView('admin.cad-approval.pdf.report', [
            'application' => $application,
            'report' => $report,
        ]);

        $relativePath = 'cad_approval_reports/' . $application->id . '/final-report-' . Str::uuid() . '.pdf';
        Storage::disk('public')->put($relativePath, $pdf->output());

        return $relativePath;
    }

    public function saveReport(CadApprovalApplication $application): array
    {
        $report = $this->buildReport($application);
        $application->final_report_json = $report;
        $application->status = $report['final_status'];

        $pdfPath = $this->generatePdf($application);

        if ($pdfPath !== null) {
            $application->final_report_pdf_path = $pdfPath;
        }

        $application->save();

        return $report;
    }

    private function finalRecommendation(string $finalStatus): string
    {
        return match ($finalStatus) {
            'incomplete' => 'Application is incomplete. Mandatory uploads or processing steps are still pending.',
            'needs_expert_review' => 'Application requires expert review before internal processing can continue.',
            'needs_correction' => 'Application has blocking CAD rule failures and should be corrected before resubmission.',
            'ready_for_submission_with_manual_notes' => 'Application is substantially complete and may proceed with manual notes attached for staff review.',
            default => 'Application is ready for internal processing and final submission.',
        };
    }
}
