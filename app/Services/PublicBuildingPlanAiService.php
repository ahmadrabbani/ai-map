<?php

namespace App\Services;

use App\Models\BpApplication;
use App\Models\BpAiReport;
use App\Models\ApplicationStatusLog;
use App\Models\PublicBuildingPlanApplication;
use Illuminate\Support\Facades\Storage;

class PublicBuildingPlanAiService
{
    public function __construct(
        private readonly BuildingPlanNumberService $numberService,
        private readonly QrCodeService $qrCodeService,
        private readonly AiMapAnalysisService $aiMapAnalysisService,
        private readonly AiReportGenerationService $reportGenerationService,
    ) {
    }

    public function generateReport(PublicBuildingPlanApplication $application): array
    {
        $applicationNo = $application->application_no ?: $this->numberService->generate();
        $application->application_no = $applicationNo;
        $application->submitted_at = now();
        $application->status = 'submitted_to_ad_epermit';
        $application->current_status = 'submitted_to_ad_epermit';
        $application->ai_status = 'AI Analysis In Progress';
        $application->cad_file_path = (string) $application->plan_file_path;
        $application->save();

        $verificationToken = (string) str()->uuid();

        $legacy = BpApplication::create([
            'application_number' => $applicationNo,
            'status' => 'AI Analysis In Progress',
            'applicant_name' => $application->applicant_name,
            'applicant_email' => $application->applicant_email,
            'applicant_phone' => $application->applicant_phone,
            'uploaded_file_name' => basename((string) $application->plan_file_path),
            'uploaded_file_path' => (string) $application->plan_file_path,
            'uploaded_file_type' => strtolower(pathinfo((string) $application->plan_file_path, PATHINFO_EXTENSION)),
            'uploaded_file_size' => is_file(storage_path('app/private/' . $application->plan_file_path)) ? (filesize(storage_path('app/private/' . $application->plan_file_path)) ?: 0) : 0,
            'metadata_doc_name' => $application->list_document_path ? basename((string) $application->list_document_path) : null,
            'metadata_doc_path' => $application->list_document_path,
            'plot_data_json' => [
                'scheme' => $application->scheme,
                'phase' => $application->phase,
                'block' => $application->block,
                'plot_ref' => $application->plot_ref,
                'selected_address' => $application->selected_address,
            ],
            'qr_token' => $verificationToken,
            'verification_url' => route('admin.plan.bp.verify', ['token' => $verificationToken]),
            'qr_code_url' => $this->qrCodeService->qrImageUrl(route('admin.plan.bp.verify', ['token' => $verificationToken])),
        ]);

        $path = storage_path('app/private/' . $application->plan_file_path);
        $uploadedFile = new \Illuminate\Http\UploadedFile(
            $path,
            basename($path),
            null,
            null,
            true
        );

        $cadSubmission = $this->aiMapAnalysisService->prepareCadSubmission($legacy, $uploadedFile, $application->plan_file_path);
        $legacy->cad_submission_id = $cadSubmission->id;
        $legacy->status = 'Submitted to AD ePermit';
        $legacy->save();

        BpAiReport::create([
            'bp_application_id' => $legacy->id,
            'analysis_status' => 'pending',
            'ai_recommendation' => 'Needs Expert Review',
            'analysis_json' => ['state' => 'queued'],
        ]);

        $analysis = $this->aiMapAnalysisService->run($legacy->fresh('cadSubmission'));
        if (! empty($analysis['map_drawing_id'])) {
            $legacy->map_drawing_id = (int) $analysis['map_drawing_id'];
            $legacy->save();
        }

        $reportPack = $this->reportGenerationService->generate($legacy->fresh(), $analysis);

        $legacyReport = $legacy->aiReport;
        if ($legacyReport) {
            $legacyReport->analysis_status = (string) ($analysis['status'] ?? 'needs_expert_review');
            $legacyReport->ai_recommendation = (string) ($analysis['recommendation'] ?? 'Needs Expert Review');
            $legacyReport->ai_confidence_score = (float) ($analysis['confidence_score'] ?? 0);
            $legacyReport->analysis_json = $analysis['analysis_json'] ?? [];
            $legacyReport->rule_results_json = $reportPack['report_data']['rule_wise_compliance_results'] ?? [];
            $legacyReport->warnings_json = $reportPack['report_data']['warnings'] ?? [];
            $legacyReport->report_markdown = $reportPack['report_markdown'] ?? null;
            $legacyReport->report_html = $reportPack['report_html'] ?? null;
            $legacyReport->generated_at = now();
            $legacyReport->save();
        }

        $recommendation = (string) ($analysis['recommendation'] ?? 'Needs Expert Review');
        $finalStatus = $recommendation === 'Needs Expert Review' ? 'Needs Expert Review' : 'AI Scrutiny Completed';

        $publicReport = [
            'legacy_bp_application_id' => $legacy->id,
            'analysis' => $analysis,
            'report_data' => $reportPack['report_data'] ?? [],
            'report_html' => $reportPack['report_html'] ?? null,
            'report_markdown' => $reportPack['report_markdown'] ?? null,
            'disclaimer' => 'This AI-based scrutiny report is generated to assist preliminary validation of building plan submissions. Final approval, rejection, or objection shall remain subject to review and decision by the concerned authority/directorate.',
        ];

        $publicVerifyUrl = route('public.bp.applications.report', $application->id);
        $reportDir = 'uploads/public-building-plan/' . now()->format('Y/m/d') . '/' . $application->id . '/reports';
        Storage::disk('local')->makeDirectory($reportDir);
        $reportJsonPath = $reportDir . '/ai-report.json';
        Storage::disk('local')->put($reportJsonPath, json_encode($publicReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $application->ai_report_json = $publicReport;
        $application->ai_status = $finalStatus;
        $application->status = 'submitted_to_ad_epermit';
        $application->current_status = 'submitted_to_ad_epermit';
        $application->routed_to = $finalStatus === 'Needs Expert Review' ? 'AD ePermit' : null;
        $application->qr_code_path = $this->qrCodeService->qrImageUrl($publicVerifyUrl);
        $application->ai_report_path = $reportJsonPath;
        $application->legacy_bp_application_id = $legacy->id;
        $application->save();

        ApplicationStatusLog::create([
            'application_id' => $application->id,
            'action_by_user_id' => null,
            'action_by_role' => 'system',
            'old_status' => 'draft',
            'new_status' => 'submitted_to_ad_epermit',
            'remarks' => 'Application submitted to AD ePermit queue after AI packaging.',
            'payload_json' => [
                'legacy_bp_application_id' => $legacy->id,
                'ai_recommendation' => (string) ($analysis['recommendation'] ?? 'Needs Expert Review'),
                'ai_confidence_score' => (float) ($analysis['confidence_score'] ?? 0),
            ],
        ]);

        return [
            'application' => $application->fresh(),
            'analysis' => $analysis,
            'report' => $publicReport,
        ];
    }
}
