<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdEpermitDecisionRequest;
use App\Http\Requests\ApplicationSiteReviewRequest;
use App\Models\ApplicationSiteReview;
use App\Models\BpAiReport;
use App\Models\BpApplication;
use App\Models\BpImageryLabel;
use App\Models\CadSubmission;
use App\Models\PublicBuildingPlanApplication;
use App\Services\AiMapAnalysisService;
use App\Services\AiReportGenerationService;
use App\Services\DfpsApplicationPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdEpermitReviewController extends Controller
{
    public function __construct(
        private readonly AiMapAnalysisService $analysisService,
        private readonly AiReportGenerationService $reportGenerationService,
        private readonly DfpsApplicationPushService $dfpsPushService,
    ) {
    }

    public function index()
    {
        $applications = PublicBuildingPlanApplication::query()
            ->with(['siteReview', 'dfpsPushLogs'])
            ->whereIn('current_status', [
                'submitted_to_ad_epermit',
                'under_review',
                'observation_marked',
                'rejected_by_ad_epermit',
                'approved_by_ad_epermit',
                'dfps_push_failed',
            ])
            ->latest('id')
            ->paginate(20);

        return view('admin.building-plan.ad-dashboard', compact('applications'));
    }

    public function show(PublicBuildingPlanApplication $application)
    {
        $application->load(['documents', 'statusLogs', 'siteReview', 'dfpsPushLogs']);
        if (($application->current_status ?: '') === 'submitted_to_ad_epermit') {
            $this->transition($application, 'under_review', 'ad_opened_review', null, [
                'opened_at' => now()->toISOString(),
            ]);
            $application->refresh()->load(['documents', 'statusLogs', 'siteReview', 'dfpsPushLogs']);
        }

        $legacy = $application->legacy_bp_application_id
            ? BpApplication::with(['aiReport', 'chatMessages', 'reviewLogs', 'cadSubmission', 'mapDrawing', 'imageryLabel'])->find($application->legacy_bp_application_id)
            : null;

        return view('admin.building-plan.ad-review', [
            'application' => $application,
            'legacyApplication' => $legacy,
        ]);
    }

    public function update(AdEpermitDecisionRequest $request, PublicBuildingPlanApplication $application)
    {
        $data = $request->validated();
        $action = (string) $data['action'];
        $remarks = trim((string) ($data['remarks'] ?? ''));

        $toStatus = match ($action) {
            'under_review' => 'under_review',
            'observation' => 'observation_marked',
            'reject' => 'rejected_by_ad_epermit',
            'approve' => 'approved_by_ad_epermit',
        };

        $this->transition($application, $toStatus, $action, $remarks, [
            'decision' => $action,
            'ip' => $request->ip(),
        ]);

        if ($request->boolean('push_to_dfps')) {
            $push = $this->dfpsPushService->push($application->fresh(['documents', 'statusLogs', 'siteReview']), $request->user()?->id);
            if ($push['ok'] ?? false) {
                $this->transition($application->fresh(), 'pushed_to_dfps', 'dfps_push_success', 'DFPS push successful.', [
                    'dfps_log_id' => $push['log_id'] ?? null,
                ]);
            } else {
                $this->transition($application->fresh(), 'dfps_push_failed', 'dfps_push_failed', (string) ($push['message'] ?? 'DFPS push failed.'), [
                    'dfps_log_id' => $push['log_id'] ?? null,
                ]);

                return back()->withErrors([
                    'dfps_push' => (string) ($push['message'] ?? 'DFPS push failed.'),
                ]);
            }
        }

        return back()->with('status', 'AD ePermit decision saved successfully.');
    }

    public function saveSiteReview(ApplicationSiteReviewRequest $request, PublicBuildingPlanApplication $application)
    {
        $data = $request->validated();
        $payload = (array) $data['site_review_json'];
        $payload['map_provider'] = 'google_maps';
        $payload['view_type'] = 'satellite';
        $payload['marked_by'] = (string) ($request->user()->email ?? $request->user()->name ?? 'ad_epermit');
        $payload['marked_at'] = now()->toISOString();

        ApplicationSiteReview::updateOrCreate(
            ['application_id' => $application->id],
            [
                'reviewer_id' => $request->user()?->id,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'site_condition' => $data['site_condition'],
                'front_road_detected' => (bool) $data['front_road_detected'],
                'side_road_detected' => (bool) $data['side_road_detected'],
                'corner_plot' => (bool) $data['corner_plot'],
                'remarks' => $data['remarks'] ?? null,
                'site_review_json' => $payload,
            ]
        );

        $this->transition($application, (string) ($application->current_status ?: 'under_review'), 'save_site_review', $data['remarks'] ?? null, [
            'site_condition' => $data['site_condition'],
        ]);

        return back()->with('status', 'Satellite site review saved.');
    }

    public function pushToDfps(Request $request, PublicBuildingPlanApplication $application)
    {
        if (! in_array((string) $application->current_status, ['approved_by_ad_epermit', 'rejected_by_ad_epermit', 'observation_marked', 'dfps_push_failed'], true)) {
            return back()->withErrors(['dfps_push' => 'DFPS push is allowed only after AD ePermit decision.']);
        }

        $push = $this->dfpsPushService->push($application->fresh(['documents', 'statusLogs', 'siteReview']), $request->user()?->id);
        if ($push['ok'] ?? false) {
            $this->transition($application->fresh(), 'pushed_to_dfps', 'dfps_push_success', 'DFPS push successful.', [
                'dfps_log_id' => $push['log_id'] ?? null,
            ]);

            return back()->with('status', 'Case pushed to DFPS/internal system successfully.');
        }

        $this->transition($application->fresh(), 'dfps_push_failed', 'dfps_push_failed', (string) ($push['message'] ?? 'DFPS push failed.'), [
            'dfps_log_id' => $push['log_id'] ?? null,
        ]);

        return back()->withErrors(['dfps_push' => (string) ($push['message'] ?? 'DFPS push failed.')]);
    }

    public function generateCadAnalysis(PublicBuildingPlanApplication $application)
    {
        $legacy = $application->legacy_bp_application_id ? BpApplication::find($application->legacy_bp_application_id) : null;
        if (! $legacy) {
            return back()->withErrors(['cad_analysis' => 'Legacy CAD application link is missing for this record.']);
        }

        $legacy->load('cadSubmission');
        $storedPath = $this->ensurePlanFileAvailable($legacy, $application);
        if ($storedPath === null && ! $this->cadSubmissionHasStoredFile($legacy)) {
            return back()->with('status', 'Cannot generate CAD analysis because no stored DWG/DXF file is available for this application.');
        }

        $ext = strtolower((string) ($legacy->uploaded_file_type ?: pathinfo((string) ($storedPath ?: $legacy->uploaded_file_name), PATHINFO_EXTENSION)));
        if (! in_array($ext, ['dwg', 'dxf'], true)) {
            return back()->with('status', 'CAD analysis can be generated only for DWG/DXF files.');
        }

        if (! $legacy->cad_submission_id) {
            $submission = CadSubmission::create([
                'original_filename' => $legacy->uploaded_file_name,
                'stored_dwg_path' => $ext === 'dwg' ? $storedPath : null,
                'stored_dxf_path' => $ext === 'dxf' ? $storedPath : null,
                'ruleset_key' => 'residential_building_approval',
                'analysis_result' => [
                    'source' => 'ad_epermit_generate_cad_analysis',
                    'bp_application_id' => $legacy->id,
                ],
            ]);
            $legacy->cad_submission_id = $submission->id;
            $legacy->save();
        }

        $analysis = $this->analysisService->run($legacy->fresh('cadSubmission'));

        if (! empty($analysis['map_drawing_id'])) {
            $legacy->map_drawing_id = (int) $analysis['map_drawing_id'];
            $legacy->save();
        }

        $reportPack = $this->reportGenerationService->generate($legacy->fresh(), $analysis);
        $report = $legacy->aiReport ?: new BpAiReport(['bp_application_id' => $legacy->id]);
        $report->analysis_status = (string) ($analysis['status'] ?? 'needs_expert_review');
        $report->ai_recommendation = (string) ($analysis['recommendation'] ?? 'Needs Expert Review');
        $report->ai_confidence_score = (float) ($analysis['confidence_score'] ?? 0);
        $report->analysis_json = $analysis['analysis_json'] ?? [];
        $report->detected_layers_json = $reportPack['report_data']['detected_cad_layers_entities']['layers'] ?? [];
        $report->detected_entities_json = $reportPack['report_data']['detected_cad_layers_entities']['entities'] ?? [];
        $report->rule_results_json = $reportPack['report_data']['rule_wise_compliance_results'] ?? [];
        $report->warnings_json = $reportPack['report_data']['warnings'] ?? [];
        $report->expert_review_items_json = $reportPack['report_data']['items_requiring_expert_review'] ?? [];
        $report->report_markdown = $reportPack['report_markdown'];
        $report->report_html = $reportPack['report_html'];
        $report->generated_at = now();
        $report->save();

        return back()->with('status', 'CAD analysis generated.');
    }

    public function saveImageryLabel(Request $request, PublicBuildingPlanApplication $application)
    {
        $legacy = $application->legacy_bp_application_id ? BpApplication::find($application->legacy_bp_application_id) : null;
        if (! $legacy) {
            return back()->withErrors(['imagery_label' => 'Legacy application not linked.']);
        }

        $data = $request->validate([
            'label' => ['required', 'in:open,built,mixed'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        BpImageryLabel::query()->updateOrCreate(
            ['bp_application_id' => $legacy->id],
            [
                'labeled_by_user_id' => $request->user()?->id,
                'label' => (string) $data['label'],
                'label_source' => 'ad_epermit_manual',
                'notes' => $data['notes'] ?? null,
                'labeled_at' => now(),
            ]
        );

        return back()->with('status', 'Imagery label saved for model training.');
    }

    private function transition(PublicBuildingPlanApplication $application, string $toStatus, string $action, ?string $remarks = null, array $payload = []): void
    {
        $old = (string) ($application->current_status ?: 'draft');
        $application->current_status = $toStatus;
        $application->status = $toStatus;
        if (in_array($toStatus, ['approved_by_ad_epermit', 'rejected_by_ad_epermit', 'observation_marked'], true)) {
            $application->reviewed_at = now();
        }
        if (in_array($toStatus, ['approved_by_ad_epermit', 'rejected_by_ad_epermit', 'observation_marked', 'under_review'], true)) {
            $application->ad_epermit_decision = $action;
            $application->ad_epermit_remarks = $remarks;
        }
        $application->save();

        $application->statusLogs()->create([
            'action_by_user_id' => auth()->id(),
            'action_by_role' => strtolower((string) data_get(auth()->user(), 'role', 'ad_epermit')),
            'old_status' => $old,
            'new_status' => $toStatus,
            'remarks' => $remarks,
            'payload_json' => $payload,
        ]);
    }

    private function cadSubmissionHasStoredFile(BpApplication $application): bool
    {
        $submission = $application->cadSubmission;
        if (! $submission) {
            return false;
        }

        foreach ([$submission->stored_dwg_path, $submission->stored_dxf_path] as $path) {
            if ($path && Storage::disk('local')->exists((string) $path)) {
                return true;
            }
        }

        return false;
    }

    private function ensurePlanFileAvailable(BpApplication $legacy, PublicBuildingPlanApplication $application): ?string
    {
        $current = (string) $legacy->uploaded_file_path;
        if ($current !== '' && Storage::disk('local')->exists($current)) {
            return $current;
        }

        $sourceRel = (string) ($application->cad_file_path ?: $application->plan_file_path);
        if ($sourceRel !== '' && Storage::disk('local')->exists($sourceRel)) {
            $extension = strtolower(pathinfo($sourceRel, PATHINFO_EXTENSION));
            $safeName = Str::slug(pathinfo($legacy->uploaded_file_name ?: basename($sourceRel), PATHINFO_FILENAME));
            $storedPath = 'uploads/bp-applications/ad-epermit-recovered/' . $legacy->id . '/' . ($safeName ?: 'plan') . '.' . $extension;
            Storage::disk('local')->put($storedPath, Storage::disk('local')->get($sourceRel));

            $legacy->uploaded_file_path = $storedPath;
            $legacy->uploaded_file_type = $extension;
            $legacy->uploaded_file_size = Storage::disk('local')->size($sourceRel);
            $legacy->save();

            return $storedPath;
        }

        return null;
    }
}
