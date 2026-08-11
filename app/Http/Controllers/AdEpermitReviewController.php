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
use App\Services\MapApproval\DxfPatternTrainingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdEpermitReviewController extends Controller
{
    private const DASHBOARD_STATUS_MAP = [
        'assigned' => [
            'submitted_to_ad_epermit',
            'under_review',
            'observation_marked',
            'rejected_by_ad_epermit',
            'approved_by_ad_epermit',
            'dfps_push_failed',
            'pushed_to_dfps',
        ],
        'under_process' => ['under_review'],
        'observation' => ['observation_marked'],
        'marked_to_dfps' => ['pushed_to_dfps'],
        'approved' => ['approved_by_ad_epermit'],
        'rejected' => ['rejected_by_ad_epermit'],
    ];

    public function __construct(
        private readonly AiMapAnalysisService $analysisService,
        private readonly AiReportGenerationService $reportGenerationService,
        private readonly DfpsApplicationPushService $dfpsPushService,
        private readonly DxfPatternTrainingService $dxfPatternTrainingService,
    ) {
    }

    public function index(Request $request)
    {
        $statusFilter = (string) $request->query('status', '');
        $userId = $request->user()?->id;

        $applications = $this->dashboardApplicationsQuery($userId, $statusFilter)
            ->with(['siteReview', 'dfpsPushLogs'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.building-plan.ad-dashboard', [
            'applications' => $applications,
            'stats' => $this->dashboardStats($userId),
            'statusFilter' => $statusFilter,
        ]);
    }

    public function show(Request $request, PublicBuildingPlanApplication $application)
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
            'decisionComparison' => $this->buildDecisionComparison($application),
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

        if (in_array($action, ['approve', 'reject', 'observation'], true)) {
            $this->dxfPatternTrainingService->capture($application->fresh(['statusLogs', 'legacyBpApplication']));
        }

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

        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:4000'],
        ]);

        $push = $this->dfpsPushService->push(
            $application->fresh(['documents', 'statusLogs', 'siteReview']),
            $request->user()?->id,
            $data['remarks']
        );
        if ($push['ok'] ?? false) {
            $this->transition($application->fresh(), 'pushed_to_dfps', 'dfps_push_success', $data['remarks'], [
                'dfps_log_id' => $push['log_id'] ?? null,
                'remarks' => $data['remarks'],
            ]);

            return back()->with('status', 'Case pushed to DFPS/internal system successfully.');
        }

        $this->transition($application->fresh(), 'dfps_push_failed', 'dfps_push_failed', $data['remarks'], [
            'dfps_log_id' => $push['log_id'] ?? null,
            'remarks' => $data['remarks'],
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

    private function dashboardApplicationsQuery(?int $userId, string $statusFilter): Builder
    {
        $query = PublicBuildingPlanApplication::query()
            ->whereIn('current_status', [
                'submitted_to_ad_epermit',
                'under_review',
                'observation_marked',
                'rejected_by_ad_epermit',
                'approved_by_ad_epermit',
                'dfps_push_failed',
                'pushed_to_dfps',
            ]);

        if ($userId) {
            $query->whereHas('statusLogs', function (Builder $builder) use ($userId) {
                $builder->where('action_by_user_id', $userId);
            });
        }

        $filterStatuses = self::DASHBOARD_STATUS_MAP[$statusFilter] ?? null;
        if (is_array($filterStatuses) && $filterStatuses !== []) {
            $query->whereIn('current_status', $filterStatuses);
        }

        return $query;
    }

    private function dashboardStats(?int $userId): array
    {
        $base = PublicBuildingPlanApplication::query()
            ->whereIn('current_status', [
                'submitted_to_ad_epermit',
                'under_review',
                'observation_marked',
                'rejected_by_ad_epermit',
                'approved_by_ad_epermit',
                'dfps_push_failed',
                'pushed_to_dfps',
            ]);

        if ($userId) {
            $base->whereHas('statusLogs', function (Builder $builder) use ($userId) {
                $builder->where('action_by_user_id', $userId);
            });
        }

        $counts = [];
        foreach (self::DASHBOARD_STATUS_MAP as $key => $statuses) {
            $counts[$key] = (clone $base)->whereIn('current_status', $statuses)->count();
        }

        return $counts;
    }

    private function buildDecisionComparison(PublicBuildingPlanApplication $application): array
    {
        $report = is_array($application->ai_report_json) ? $application->ai_report_json : [];
        $analysis = is_array(data_get($report, 'analysis')) ? data_get($report, 'analysis') : [];

        $aiRecommendationRaw = (string) (
            data_get($analysis, 'recommendation')
            ?: data_get($report, 'ai_recommendation')
            ?: $application->ai_status
            ?: ''
        );
        $aiReasoning = (string) (
            data_get($analysis, 'summary')
            ?: data_get($analysis, 'reasoning')
            ?: data_get($analysis, 'remarks')
            ?: data_get($report, 'disclaimer')
            ?: ''
        );
        $cadConfidence = (array) data_get($analysis, 'cad_confidence_assessment', data_get($report, 'report_data.cad_confidence_assessment', []));
        $patternProfile = (array) data_get($analysis, 'dxf_pattern_profile', data_get($report, 'report_data.dxf_pattern_profile', []));

        $adLog = $application->statusLogs()
            ->whereNotNull('remarks')
            ->whereIn('new_status', ['under_review', 'observation_marked', 'rejected_by_ad_epermit', 'approved_by_ad_epermit', 'pushed_to_dfps', 'dfps_push_failed'])
            ->latest('id')
            ->first();

        $adDecisionRaw = (string) ($application->ad_epermit_decision ?: data_get($adLog, 'new_status', ''));
        $adComments = trim((string) ($application->ad_epermit_remarks ?: data_get($adLog, 'remarks', '')));

        $comparison = $this->compareDecisionStrings($aiRecommendationRaw, $adDecisionRaw);

        return [
            'ai' => [
                'available' => $aiRecommendationRaw !== '',
                'recommendation' => $aiRecommendationRaw !== '' ? $aiRecommendationRaw : 'AI response not available yet',
                'reasoning' => $aiReasoning !== '' ? $aiReasoning : 'AI response not available yet',
                'confidence_score' => (float) data_get($cadConfidence, 'confidence_score', data_get($report, 'report_data.ai_confidence_score', 0)),
                'confidence_level' => (string) data_get($cadConfidence, 'confidence_level', 'unknown'),
                'dimension_source' => (string) data_get($cadConfidence, 'dimension_source', 'unknown'),
                'fallback_method_used' => (string) data_get($cadConfidence, 'fallback_method_used', 'unknown'),
                'missing_layers' => (array) data_get($cadConfidence, 'missing_layers', []),
                'warnings' => (array) data_get($cadConfidence, 'warnings', []),
                'pattern_family' => (string) data_get($patternProfile, 'pattern_family', 'generic_dxf'),
                'pattern_strength' => (float) data_get($patternProfile, 'pattern_strength', 0),
            ],
            'ad' => [
                'available' => $adDecisionRaw !== '' || $adComments !== '',
                'decision' => $adDecisionRaw !== '' ? $this->normalizeDecisionLabel($adDecisionRaw) : 'AD decision not submitted yet',
                'comments' => $adComments !== '' ? $adComments : 'AD comments not submitted yet',
                'user_id' => $adLog?->action_by_user_id,
                'time' => $adLog?->created_at?->toDateTimeString(),
            ],
            'comparison' => $comparison,
        ];
    }

    private function compareDecisionStrings(string $aiRecommendation, string $adDecision): array
    {
        $ai = $this->normalizeDecision($aiRecommendation);
        $ad = $this->normalizeDecision($adDecision);

        if ($ai === '' || $ad === '') {
            return [
                'status' => 'missing',
                'label' => 'Decision comparison not available yet',
                'note' => 'AI or AD decision is missing.',
            ];
        }

        if ($ai === $ad) {
            return [
                'status' => 'match',
                'label' => 'AI and AD both agree',
                'note' => 'AI and AD decision are aligned.',
            ];
        }

        if ($ai === 'approve' && $ad === 'observation') {
            return [
                'status' => 'ad_stricter',
                'label' => 'AI recommends approval but AD marked observation',
                'note' => 'AD applied a stricter review outcome than AI.',
            ];
        }

        if ($ai === 'reject' && $ad === 'approve') {
            return [
                'status' => 'ad_laxer',
                'label' => 'AI found violation but AD recommended approval',
                'note' => 'AD decision is more permissive than AI.',
            ];
        }

        return [
            'status' => 'different',
            'label' => 'AI and AD decision differ',
            'note' => 'Please review the reasoning and remarks side by side.',
        ];
    }

    private function normalizeDecisionLabel(string $value): string
    {
        return match ($this->normalizeDecision($value)) {
            'approve' => 'Approve',
            'reject' => 'Reject',
            'observation' => 'Observation',
            default => ucfirst(str_replace('_', ' ', trim($value))) ?: 'Not available',
        };
    }

    private function normalizeDecision(string $value): string
    {
        $value = strtolower(trim($value));

        return match (true) {
            in_array($value, ['approve', 'approved', 'pass', 'passed', 'approval'], true) => 'approve',
            in_array($value, ['reject', 'rejected', 'fail', 'failed', 'objection'], true) => 'reject',
            in_array($value, ['observation', 'needs review', 'needs_expert_review', 'needs expert review', 'review'], true) => 'observation',
            default => $value,
        };
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
