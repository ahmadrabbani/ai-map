<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\ApplicationDocument;
use App\Models\BpApplication;
use App\Models\MapDrawing;
use App\Models\PublicBuildingPlanApplication;
use App\Services\AiMapAnalysisService;
use App\Services\DocumentValidationService;
use App\Services\BuildingPlanAiService;
use App\Services\MapApproval\GeometryCalculationService;
use App\Services\MapApproval\RuleValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class PublicBuildingPlanPortalController extends Controller
{
    public function __construct(
        private readonly DocumentValidationService $documentValidationService,
        private readonly BuildingPlanAiService $publicBuildingPlanAiService,
        private readonly AiMapAnalysisService $aiMapAnalysisService,
        private readonly GeometryCalculationService $geometryCalculationService,
        private readonly RuleValidationService $mapRuleValidationService,
    ) {
    }

    public function dashboard(Request $request): View
    {
        $applicant = $this->currentApplicant($request);
        $applications = PublicBuildingPlanApplication::where('user_id', $applicant->id)
            ->withCount([
                'chatMessages as unread_ad_epermit_messages_count' => function ($query) {
                    $query->where('role', 'ad_epermit')
                        ->where('context_json->channel', 'ad_epermit')
                        ->whereNull('read_by_applicant_at');
                },
            ])
            ->latest('id')
            ->paginate(10);

        return view('public.building-plan.dashboard', compact('applicant', 'applications'));
    }

    public function create(Request $request, ?PublicBuildingPlanApplication $application = null): View
    {
        $applicant = $this->currentApplicant($request);

        if ($application && $application->user_id !== $applicant->id) {
            abort(403);
        }

        return view('public.building-plan.applications.create', [
            'applicant' => $applicant,
            'application' => $application,
            'geoStatusOptions' => ['Not evaluated', 'Matched', 'Needs manual verification'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $applicant = $this->currentApplicant($request);

        $data = $request->validate([
            'applicant_name' => ['required', 'string', 'max:255'],
            'applicant_cnic' => ['required', 'regex:/^\d{5}-\d{7}-\d$/'],
            'applicant_phone' => ['required', 'regex:/^(?:\+92|0)3\d{2}-?\d{7}$/'],
            'applicant_email' => ['required', 'email', 'max:255'],
            'scheme' => ['required', 'string', 'max:120'],
            'phase' => ['nullable', 'string', 'max:120'],
            'block' => ['nullable', 'string', 'max:120'],
            'plot_ref' => ['required', 'string', 'max:120'],
            'selected_address' => ['required', 'string', 'max:500'],
            'property_signal' => ['nullable', 'in:Not evaluated,Matched,Needs manual verification'],
            'cnic_front' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'cnic_back' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'ownership_document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'list_document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'affidavit' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'supporting_documents.*' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'plan_file' => ['required', 'file', 'extensions:dwg,dxf,cad,pdf', 'max:51200'],
        ]);

        $application = DB::transaction(function () use ($applicant, $data, $request) {
            $baseDir = 'uploads/public-building-plan/' . now()->format('Y/m/d');

            $application = PublicBuildingPlanApplication::create([
                'user_id' => $applicant->id,
                'applicant_name' => $data['applicant_name'],
                'applicant_cnic' => $data['applicant_cnic'],
                'applicant_email' => $data['applicant_email'],
                'applicant_phone' => $data['applicant_phone'],
                'scheme' => $data['scheme'],
                'scheme_name' => $data['scheme'],
                'phase' => $data['phase'] ?? null,
                'block' => $data['block'] ?? null,
                'block_name' => $data['block'] ?? null,
                'plot_ref' => $data['plot_ref'],
                'plot_no' => $data['plot_ref'],
                'selected_address' => $data['selected_address'],
                'plot_address' => $data['selected_address'],
                'status' => 'Draft',
                'current_status' => 'draft',
                'ai_status' => $data['property_signal'] ?? 'Not evaluated',
            ]);

            $map = [
                'cnic_front' => ['field' => 'cnic_front', 'maxKb' => 5120],
                'cnic_back' => ['field' => 'cnic_back', 'maxKb' => 5120],
                'ownership_document' => ['field' => 'ownership_document', 'maxKb' => 10240],
                'list_document' => ['field' => 'list_document', 'maxKb' => 10240],
                'affidavit' => ['field' => 'affidavit', 'maxKb' => 10240],
            ];

            foreach ($map as $docType => $conf) {
                $file = $request->file($conf['field']);
                if (! $file) {
                    continue;
                }

                $path = $file->store($baseDir . '/' . $application->id . '/documents', 'local');
                $abs = Storage::disk('local')->path($path);
                $validation = $this->documentValidationService->validate($abs, $file->getMimeType(), $conf['maxKb']);

                ApplicationDocument::create([
                    'application_id' => $application->id,
                    'document_type' => $docType,
                    'attachment_type' => $docType,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'validation_status' => $validation['validation_status'],
                    'validation_message' => $validation['validation_message'],
                ]);

                $col = match ($docType) {
                    'cnic_front' => 'cnic_front_path',
                    'cnic_back' => 'cnic_back_path',
                    'ownership_document' => 'ownership_document_path',
                    'list_document' => 'list_document_path',
                    'affidavit' => 'affidavit_path',
                };
                $application->{$col} = $path;
            }

            $supportingDocs = $request->file('supporting_documents', []);
            foreach ($supportingDocs as $file) {
                if (! $file) {
                    continue;
                }
                $path = $file->store($baseDir . '/' . $application->id . '/supporting', 'local');
                $abs = Storage::disk('local')->path($path);
                $validation = $this->documentValidationService->validate($abs, $file->getMimeType(), 10240);

                ApplicationDocument::create([
                    'application_id' => $application->id,
                    'document_type' => 'supporting_document',
                    'attachment_type' => 'supporting_document',
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'validation_status' => $validation['validation_status'],
                    'validation_message' => $validation['validation_message'],
                ]);
            }

            $plan = $request->file('plan_file');
            $application->plan_file_path = $plan->store($baseDir . '/' . $application->id . '/plan', 'local');
            $application->cad_file_path = $application->plan_file_path;
            $application->save();

            return $application;
        });

        try {
            $this->publicBuildingPlanAiService->generateReport($application);
        } catch (\Throwable $e) {
            $application->status = 'Needs Expert Review';
            $application->ai_status = 'Needs Expert Review';
            $application->ai_report_json = [
                'error' => $e->getMessage(),
                'disclaimer' => 'This AI-based scrutiny report is generated to assist preliminary validation of building plan submissions. Final approval, rejection, or objection shall remain subject to review and decision by the concerned authority/directorate.',
            ];
            $application->save();
        }

        return redirect()->route('public.bp.applications.show', $application->id)
            ->with('status', 'Application submitted and scrutiny workflow started.');
    }

    public function precheck(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_file' => ['required', 'file', 'extensions:dwg,dxf,cad,pdf', 'max:51200'],
        ]);

        $file = $request->file('plan_file');
        $tempDir = 'uploads/public-building-plan/precheck/' . now()->format('Y/m/d') . '/' . Str::uuid();
        $storedPath = $file->store($tempDir, 'local');

        $tempApplication = null;
        $tempSubmission = null;
        $tempDrawingId = null;
        try {
            $token = (string) Str::uuid();
            $tempApplication = BpApplication::create([
                'application_number' => 'PRE-' . strtoupper(Str::random(10)),
                'status' => 'AI Analysis In Progress',
                'applicant_name' => 'Precheck',
                'uploaded_file_name' => $file->getClientOriginalName(),
                'uploaded_file_path' => $storedPath,
                'uploaded_file_type' => strtolower((string) $file->getClientOriginalExtension()),
                'uploaded_file_size' => $file->getSize(),
                'qr_token' => $token,
                'verification_url' => url('/building-plan-ai/applications/precheck/' . $token),
                'qr_code_url' => null,
            ]);

            $uploadedFile = new UploadedFile(
                Storage::disk('local')->path($storedPath),
                $file->getClientOriginalName(),
                $file->getMimeType(),
                null,
                true
            );

            $tempSubmission = $this->aiMapAnalysisService->prepareCadSubmission($tempApplication, $uploadedFile, $storedPath);
            $tempApplication->cad_submission_id = $tempSubmission->id;
            $tempApplication->save();

            $analysis = $this->aiMapAnalysisService->run($tempApplication->fresh('cadSubmission'));
            $tempDrawingId = (int) data_get($analysis, 'map_drawing_id', 0);
            $previewConfidence = $this->previewConfidenceScore($analysis, $file);
            $confidenceSource = $previewConfidence['source'];
            $confidenceScore = $previewConfidence['score'];
            $confidenceNotes = $previewConfidence['notes'];
            $analysisConfidence = $this->analysisConfidenceScore($analysis);

            return response()->json([
                'ok' => true,
                'status' => (string) ($analysis['status'] ?? 'needs_expert_review'),
                'recommendation' => (string) ($analysis['recommendation'] ?? 'Needs Expert Review'),
                'confidence_score' => $confidenceScore,
                'analysis_confidence_score' => $analysisConfidence,
                'confidence_source' => $confidenceSource,
                'cad_confidence_assessment' => data_get($analysis, 'analysis_json.cad_confidence_assessment', data_get($analysis, 'cad_confidence_assessment', [])),
                'dxf_pattern_profile' => data_get($analysis, 'analysis_json.dxf_pattern_profile', data_get($analysis, 'dxf_pattern_profile', [])),
                'warnings' => array_values((array) ($analysis['warnings'] ?? [])),
                'notes' => $confidenceNotes,
                'preview_note' => 'This is a server-side preview based on the uploaded plan file. It is not the final application record.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'status' => 'needs_review',
                'recommendation' => 'Needs Review',
                'confidence_score' => 0,
                'warnings' => ['Precheck analysis failed: ' . $e->getMessage()],
            ], 422);
        } finally {
            if ($tempDrawingId > 0) {
                MapDrawing::where('id', $tempDrawingId)->delete();
            }
            if ($tempSubmission) {
                $tempSubmission->delete();
            }
            if ($tempApplication) {
                $tempApplication->delete();
            }
            if ($storedPath && Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
        }
    }

    private function previewConfidenceScore(array $analysis, \Illuminate\Http\UploadedFile $file): array
    {
        $candidates = [
            'cad_confidence_assessment.final_confidence_score' => data_get($analysis, 'cad_confidence_assessment.final_confidence_score'),
            'cad_confidence_assessment.confidence_score' => data_get($analysis, 'cad_confidence_assessment.confidence_score'),
            'analysis_json.cad_confidence_assessment.final_confidence_score' => data_get($analysis, 'analysis_json.cad_confidence_assessment.final_confidence_score'),
            'analysis_json.cad_confidence_assessment.confidence_score' => data_get($analysis, 'analysis_json.cad_confidence_assessment.confidence_score'),
            'analysis.confidence_score' => data_get($analysis, 'confidence_score'),
            'analysis_json.confidence_score' => data_get($analysis, 'analysis_json.confidence_score'),
        ];

        foreach ($candidates as $source => $value) {
            if (! is_numeric($value)) {
                continue;
            }
            return [
                'score' => round((float) $value, 2),
                'source' => $source,
                'notes' => ['Server analysis score used.'],
            ];
        }

        $local = $this->localPreviewConfidence($file);

        return [
            'score' => $local['confidence'],
            'source' => 'local_heuristic',
            'notes' => ['Server analysis returned no usable score, so the local heuristic was used as fallback.'],
        ];
    }

    private function analysisConfidenceScore(array $analysis): ?float
    {
        $candidates = [
            data_get($analysis, 'analysis_json.confidence_score'),
            data_get($analysis, 'confidence_score'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                return round((float) $value, 2);
            }
        }

        return null;
    }

    private function localPreviewConfidence(\Illuminate\Http\UploadedFile $file): array
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $sizeMb = $file->getSize() / (1024 * 1024);
        $validExt = in_array($ext, ['dwg', 'dxf', 'cad', 'pdf'], true);
        $confidence = $validExt ? 84 : 40;

        if ($sizeMb <= 20) {
            $confidence += 8;
        }
        if ($sizeMb > 45) {
            $confidence -= 10;
        }
        if (in_array($ext, ['dwg', 'dxf'], true)) {
            $confidence += 4;
        }

        return [
            'confidence' => max(0, min(99, round($confidence))),
        ];
    }

    public function show(Request $request, int $id): View
    {
        $applicant = $this->currentApplicant($request);
        $application = PublicBuildingPlanApplication::with('documents')->findOrFail($id);
        $this->authorizeOwnership($application, $applicant);
        $unreadAdEpermitMessages = (int) $application->chatMessages()
            ->where('role', 'ad_epermit')
            ->where('context_json->channel', 'ad_epermit')
            ->whereNull('read_by_applicant_at')
            ->count();

        return view('public.building-plan.applications.show', compact('application', 'applicant', 'unreadAdEpermitMessages'));
    }

    public function report(Request $request, int $id): View
    {
        $applicant = $this->currentApplicant($request);
        $application = PublicBuildingPlanApplication::findOrFail($id);
        $this->authorizeOwnership($application, $applicant);
        $this->refreshPublicReportMetricsFromLegacyDrawing($application);
        $comparison = $this->buildLegacyTextualComparison($application);

        return view('public.building-plan.applications.report', array_merge([
            'application' => $application,
        ], $comparison));
    }

    public function downloadReport(Request $request, int $id)
    {
        $applicant = $this->currentApplicant($request);
        $application = PublicBuildingPlanApplication::findOrFail($id);
        $this->authorizeOwnership($application, $applicant);

        $payload = [
            'application_no' => $application->application_no,
            'status' => $application->status,
            'ai_status' => $application->ai_status,
            'ad_epermit_decision' => $application->ad_epermit_decision,
            'ad_epermit_remarks' => $application->ad_epermit_remarks,
            'reviewed_at' => optional($application->reviewed_at)->toISOString(),
            'report' => $application->ai_report_json,
            'disclaimer' => 'This AI-based scrutiny report is generated to assist preliminary validation of building plan submissions. Final approval, rejection, or objection shall remain subject to review and decision by the concerned authority/directorate.',
        ];

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, ($application->application_no ?: 'building-plan-report') . '.json', [
            'Content-Type' => 'application/json',
        ]);
    }

    public function document(Request $request, int $id, int $documentId): StreamedResponse
    {
        $applicant = $this->currentApplicant($request);
        $application = PublicBuildingPlanApplication::findOrFail($id);
        $this->authorizeOwnership($application, $applicant);

        $doc = ApplicationDocument::where('application_id', $application->id)->findOrFail($documentId);
        abort_unless(Storage::disk('local')->exists($doc->file_path), 404);

        return Storage::disk('local')->download($doc->file_path, basename($doc->file_path));
    }


    public function planPdf(Request $request, int $id)
    {
        $applicant = $this->currentApplicant($request);
        $application = PublicBuildingPlanApplication::findOrFail($id);
        $this->authorizeOwnership($application, $applicant);

        $path = (string) ($application->plan_file_path ?? '');
        abort_if($path === '' || !Storage::disk('local')->exists($path), 404, 'Plan file not found.');

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_unless($ext === 'pdf', 422, 'Uploaded plan is not a PDF file.');

        $content = Storage::disk('local')->get($path);
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }

    public function edit(Request $request, int $id): View
    {
        $applicant = $this->currentApplicant($request);
        $application = PublicBuildingPlanApplication::findOrFail($id);
        $this->authorizeOwnership($application, $applicant);

        if ($application->status !== 'Draft') {
            return redirect()->route('public.bp.applications.show', $application->id)
                ->with('status', 'Only draft applications can be edited.');
        }

        return $this->create($request, $application);
    }

    private function currentApplicant(Request $request): Applicant
    {
        $applicant = $request->attributes->get('bpApplicant');
        if (! $applicant instanceof Applicant) {
            abort(401);
        }

        return $applicant;
    }

    private function authorizeOwnership(PublicBuildingPlanApplication $application, Applicant $applicant): void
    {
        if ($application->user_id !== $applicant->id) {
            abort(403, 'Unauthorized access to application record.');
        }
    }

    private function refreshPublicReportMetricsFromLegacyDrawing(PublicBuildingPlanApplication $application): void
    {
        $report = is_array($application->ai_report_json) ? $application->ai_report_json : [];
        $legacyId = (int) data_get($report, 'legacy_bp_application_id', 0);
        if ($legacyId <= 0) {
            return;
        }

        $legacy = BpApplication::find($legacyId);
        if (! $legacy || ! $legacy->map_drawing_id) {
            return;
        }

        $drawing = MapDrawing::find((int) $legacy->map_drawing_id);
        if (! $drawing) {
            return;
        }

        $this->aiMapAnalysisService->hydrateCadTextReferencesFromLayers($drawing);
        $refreshedRuleRows = $this->rerunRulesFromHydratedDrawing($legacy, $drawing);
        $drawing->refresh();
        $meta = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
        $metrics = is_array(data_get($meta, 'cad_text_measurement_metrics'))
            ? (array) data_get($meta, 'cad_text_measurement_metrics')
            : [];
        $roomAreas = is_array(data_get($meta, 'cad_text_room_areas'))
            ? (array) data_get($meta, 'cad_text_room_areas')
            : [];

        if (empty($metrics) && empty($roomAreas)) {
            return;
        }

        $metrics = $this->mergeAdjacentRoomFallbackMetrics($metrics, $roomAreas);
        data_set($report, 'analysis.analysis_json.map_report.cad_text_measurement_metrics', $metrics);
        data_set($report, 'analysis.analysis_json.cad_text_measurement_metrics', $metrics);
        data_set($report, 'analysis.analysis_result.cad_text_measurement_metrics', $metrics);
        data_set($report, 'analysis.map_report.cad_text_measurement_metrics', $metrics);
        data_set($report, 'report_data.cad_text_measurement_metrics', $metrics);
        data_set($report, 'analysis.analysis_json.map_report.cad_text_room_areas', $roomAreas);
        data_set($report, 'analysis.analysis_json.cad_text_room_areas', $roomAreas);
        if (! empty($refreshedRuleRows)) {
            data_set($report, 'report_data.rule_wise_compliance_results', $refreshedRuleRows);
        }

        $application->ai_report_json = $report;
        $application->save();
    }

    private function rerunRulesFromHydratedDrawing(BpApplication $legacy, MapDrawing $drawing): array
    {
        $geometry = $this->geometryCalculationService->calculate($drawing->fresh('entities'));
        $this->mapRuleValidationService->validate($drawing->fresh('entities'), $geometry);
        $drawing->refresh();

        $ruleRows = $drawing->ruleResults()
            ->orderBy('id')
            ->get()
            ->map(function ($result) {
                return [
                    'rule_code' => $result->rule_code,
                    'id' => $result->rule_code,
                    'status' => $result->status,
                    'required' => $result->required_value,
                    'required_value' => $result->required_value,
                    'actual' => $result->actual_value,
                    'actual_value' => $result->actual_value,
                    'measured' => $result->actual_value,
                    'message' => $result->message,
                    'source_entities' => collect($result->source_entities_json ?? [])
                        ->map(fn ($h) => (string) $h)
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        if ($legacy->aiReport) {
            $legacy->aiReport->rule_results_json = $ruleRows;
            $legacy->aiReport->save();
        }

        return $ruleRows;
    }

    private function mergeAdjacentRoomFallbackMetrics(array $metrics, array $roomAreas): array
    {
        if (empty($roomAreas)) {
            return $metrics;
        }

        $totalsByFloor = [];
        foreach ($roomAreas as $row) {
            $area = (float) ($row['area_sqft'] ?? 0);
            if ($area <= 0) {
                continue;
            }
            $floor = strtoupper(trim((string) ($row['floor'] ?? 'GF')));
            if ($floor === '') {
                $floor = 'GF';
            }
            $totalsByFloor[$floor] = ($totalsByFloor[$floor] ?? 0.0) + $area;
        }

        if (empty($totalsByFloor)) {
            return $metrics;
        }

        $sum = array_sum($totalsByFloor);
        if (($metrics['ground_floor_covered'] ?? null) === null && isset($totalsByFloor['GF'])) {
            $metrics['ground_floor_covered'] = round((float) $totalsByFloor['GF'], 2);
        }
        if (($metrics['total_floor_covered'] ?? null) === null && $sum > 0) {
            $metrics['total_floor_covered'] = round((float) $sum, 2);
        }
        if (($metrics['number_of_floors'] ?? null) === null) {
            $metrics['number_of_floors'] = count($totalsByFloor);
        }

        return $metrics;
    }

    private function buildLegacyTextualComparison(PublicBuildingPlanApplication $application): array
    {
        $report = is_array($application->ai_report_json) ? $application->ai_report_json : [];
        $legacyId = (int) data_get($report, 'legacy_bp_application_id', 0);
        if ($legacyId <= 0) {
            return [
                'comparisonRows' => [],
                'textualRecommendation' => 'Needs Expert Review',
                'textualFindings' => [],
                'roomAreas' => [],
                'roomAreaTotals' => [],
            ];
        }

        $legacy = BpApplication::with('aiReport')->find($legacyId);
        if (! $legacy || ! $legacy->aiReport) {
            return [
                'comparisonRows' => [],
                'textualRecommendation' => 'Needs Expert Review',
                'textualFindings' => [],
                'roomAreas' => [],
                'roomAreaTotals' => [],
            ];
        }

        $metrics = [];
        $roomAreas = [];
        if ($legacy->map_drawing_id) {
            $drawing = MapDrawing::find((int) $legacy->map_drawing_id);
            if ($drawing) {
                $this->aiMapAnalysisService->hydrateCadTextReferencesFromLayers($drawing);
                $drawing->refresh();
                $meta = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
                $metrics = is_array(data_get($meta, 'cad_text_measurement_metrics'))
                    ? (array) data_get($meta, 'cad_text_measurement_metrics')
                    : [];
                $roomAreas = is_array(data_get($meta, 'cad_text_room_areas'))
                    ? (array) data_get($meta, 'cad_text_room_areas')
                    : [];
            }
        }

        $metrics = $this->mergeAdjacentRoomFallbackMetrics($metrics, $roomAreas);
        $plotArea = $this->number($metrics['plot_area'] ?? null);
        $groundCovered = $this->number($metrics['ground_floor_covered'] ?? null);
        $totalCovered = $this->number($metrics['total_floor_covered'] ?? null);
        $coverageFormula = ($plotArea && $groundCovered !== null && $plotArea > 0)
            ? round(($groundCovered / $plotArea) * 100, 2)
            : null;
        $farFormula = ($plotArea && $totalCovered !== null && $plotArea > 0)
            ? round($totalCovered / $plotArea, 4)
            : null;
        $thresholds = $this->resolveThresholdsFromRules($drawing ?? null, $metrics);
        $maxGroundCoveredArea = ($plotArea && $thresholds['ground_coverage_percent'] !== null)
            ? round($plotArea * ((float) $thresholds['ground_coverage_percent'] / 100), 2)
            : null;
        $maxFarCoveredArea = ($plotArea && $thresholds['far_limit'] !== null)
            ? round($plotArea * (float) $thresholds['far_limit'], 2)
            : null;

        $ruleRows = collect($legacy->aiReport->rule_results_json ?? []);
        $aiActual = fn (string $ruleCode) => $this->ruleActual($ruleRows->first(fn ($row) => ($row['rule_code'] ?? $row['id'] ?? null) === $ruleCode));

        $sideSetbacks = array_values(array_filter([
            $this->number($metrics['left_setback_ft'] ?? null),
            $this->number($metrics['right_setback_ft'] ?? null),
        ], fn ($v) => $v !== null));

        $rows = [
            $this->comparisonRow('Plot Area', $metrics['plot_area'] ?? null, null, 'Base plot area read from layer 39 Measurements', null, $maxGroundCoveredArea === null ? null : (($thresholds['ground_coverage_percent'] ?? 75) . '% coverage area = ' . $maxGroundCoveredArea . ' sqft')),
            $this->comparisonRow('Ground Floor Covered', $metrics['ground_floor_covered'] ?? null, null, 'Ground coverage area check from textual table', '<=', $maxGroundCoveredArea),
            $this->comparisonRow('Total Floor Covered', $metrics['total_floor_covered'] ?? null, null, 'FAR area check from textual table', '<=', $maxFarCoveredArea),
            $this->comparisonRow('Ground Coverage %', $coverageFormula, $aiActual('GROUND_COVERAGE'), 'Text formula: ground floor covered / plot area x 100', '<=', $thresholds['ground_coverage_percent']),
            $this->comparisonRow('FAR', $farFormula, $aiActual('FAR_LIMIT'), 'Text formula: all floor covered / plot area', '<=', $thresholds['far_limit']),
            $this->comparisonRow('Number of Floors', $metrics['number_of_floors'] ?? null, $aiActual('MAX_STOREYS'), 'From layer 39 Measurements', '<=', $thresholds['max_storeys']),
            $this->comparisonRow('Provided Height (ft)', $metrics['provided_height_ft'] ?? null, $aiActual('MAX_HEIGHT'), 'From layer 39 Measurements', '<=', $thresholds['max_height_ft']),
            $this->comparisonRow('Front Setback (ft)', $metrics['front_setback_ft'] ?? null, $aiActual('SETBACK_FRONT'), 'From layer 39 Measurements', '>=', $thresholds['front_setback_ft']),
            $this->comparisonRow('Rear Setback (ft)', $metrics['rear_setback_ft'] ?? null, $aiActual('SETBACK_REAR'), 'From layer 39 Measurements', '>=', $thresholds['rear_setback_ft']),
            $this->comparisonRow('Side Setback (ft)', empty($sideSetbacks) ? null : max($sideSetbacks), $aiActual('SETBACK_SIDE'), 'Either left or right side mandatory space from text', (string) ($thresholds['side_setback_operator'] ?? '=='), $thresholds['side_setback_ft']),
        ];

        $scored = array_values(array_filter($rows, fn ($row) => in_array($row['status'], ['pass', 'fail'], true)));
        $missing = array_values(array_filter($rows, fn ($row) => $row['status'] === 'needs_review'));
        $failed = array_values(array_filter($scored, fn ($row) => $row['status'] === 'fail'));

        $recommendation = 'Needs Expert Review';
        if (! empty($failed)) {
            $recommendation = 'Failed';
        } elseif (empty($missing) && ! empty($scored)) {
            $recommendation = 'Passed';
        } elseif (empty($failed) && count($scored) >= 4) {
            $recommendation = 'Passed on Textual Data';
        }

        $findings = [];
        if ($coverageFormula !== null) {
            $findings[] = 'Ground coverage from text is ' . $coverageFormula . '% against allowed ' . (($thresholds['ground_coverage_percent'] ?? '-') . '%') . '.';
        }
        if ($farFormula !== null) {
            $findings[] = 'FAR from text is ' . $farFormula . ' against allowed ' . ($thresholds['far_limit'] ?? '-') . '.';
        }
        if (! empty($failed)) {
            $findings[] = 'One or more textual checks failed and should be corrected or reviewed.';
        } elseif ($recommendation !== 'Needs Expert Review') {
            $findings[] = 'Textual table checks available in the uploaded drawing are within configured limits.';
        }

        return [
            'comparisonRows' => $rows,
            'textualRecommendation' => $recommendation,
            'textualFindings' => $findings,
            'roomAreas' => $roomAreas,
            'roomAreaTotals' => $this->roomAreaTotals($roomAreas),
        ];
    }

    private function roomAreaTotals(array $roomAreas): array
    {
        $totals = [];
        foreach ($roomAreas as $row) {
            $floor = (string) ($row['floor'] ?? 'GF');
            $totals[$floor] = $totals[$floor] ?? ['floor' => $floor, 'count' => 0, 'area_sqft' => 0.0];
            $totals[$floor]['count']++;
            $totals[$floor]['area_sqft'] += (float) ($row['area_sqft'] ?? 0);
        }

        foreach ($totals as &$row) {
            $row['area_sqft'] = round((float) $row['area_sqft'], 2);
        }
        unset($row);

        return array_values($totals);
    }

    private function comparisonRow(string $label, mixed $textual, mixed $ai, string $basis, ?string $operator, mixed $required): array
    {
        $textualNumber = $this->number($textual);
        $status = 'needs_review';
        $requiredNumber = $this->number($required);
        if ($operator !== null && $requiredNumber !== null && $textualNumber !== null) {
            $pass = match ($operator) {
                '<=' => $textualNumber <= $requiredNumber,
                '>=' => $textualNumber >= $requiredNumber,
                '==' => abs($textualNumber - $requiredNumber) < 0.0001,
                default => null,
            };
            $status = $pass === null ? 'needs_review' : ($pass ? 'pass' : 'fail');
        } elseif ($textualNumber !== null || ($textual !== null && $textual !== '')) {
            $status = 'reference';
        }

        return [
            'label' => $label,
            'textual_value' => $textual,
            'ai_value' => $ai,
            'required' => $required,
            'operator' => $operator,
            'status' => $status,
            'basis' => $basis,
        ];
    }

    private function ruleActual(?array $row): mixed
    {
        if (! $row) {
            return null;
        }

        return $row['actual'] ?? $row['actual_value'] ?? $row['measured'] ?? null;
    }

    private function number(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value) && preg_match('/-?\d+(?:\.\d+)?/', $value, $m)) {
            return (float) $m[0];
        }

        return null;
    }

    private function resolveThresholdsFromRules(?MapDrawing $drawing, array $metrics): array
    {
        $defaults = [
            'ground_coverage_percent' => 75.0,
            'far_limit' => 2.3,
            'max_storeys' => 3.0,
            'max_height_ft' => 38.0,
            'front_setback_ft' => 5.0,
            'rear_setback_ft' => 5.5,
            'side_setback_ft' => 0.0,
            'side_setback_operator' => '==',
        ];
        $path = base_path('rules/approval_rules_meta.json');
        if (! is_file($path)) {
            return $defaults;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            return $defaults;
        }

        $plotArea = $this->number($metrics['plot_area'] ?? null);
        $category = (string) data_get($drawing?->metadata_json, 'plot_size_category', '');
        if ($category === '') {
            $category = $this->inferPlotSizeCategory($plotArea);
        }
        $rules = data_get($raw, 'plot_size_categories.' . $category . '.ground_floor_rules', []);
        if (! is_array($rules) || empty($rules)) {
            $rules = $this->rulesFromResidentialHouseBands($raw, $plotArea);
        }
        if (! is_array($rules)) {
            return $defaults;
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $id = (string) ($rule['id'] ?? '');
            if ($id === 'GROUND_COVERAGE' && isset($rule['value_percent'])) {
                $defaults['ground_coverage_percent'] = (float) $rule['value_percent'];
            } elseif ($id === 'FAR_LIMIT' && isset($rule['value'])) {
                $defaults['far_limit'] = (float) $rule['value'];
            } elseif ($id === 'MAX_STOREYS' && isset($rule['value'])) {
                $defaults['max_storeys'] = (float) $rule['value'];
            } elseif ($id === 'MAX_HEIGHT' && isset($rule['value_ft'])) {
                $defaults['max_height_ft'] = (float) $rule['value_ft'];
            } elseif ($id === 'SETBACK_FRONT' && isset($rule['value_ft'])) {
                $defaults['front_setback_ft'] = (float) $rule['value_ft'];
            } elseif ($id === 'SETBACK_REAR' && isset($rule['value_ft'])) {
                $defaults['rear_setback_ft'] = (float) $rule['value_ft'];
            } elseif ($id === 'SETBACK_SIDE' && isset($rule['value_ft'])) {
                $defaults['side_setback_ft'] = (float) $rule['value_ft'];
                $defaults['side_setback_operator'] = (string) (($rule['operator'] ?? null) ?: (((float) $rule['value_ft']) > 0 ? '>=' : '=='));
            }
        }

        return $defaults;
    }

    private function inferPlotSizeCategory(?float $plotAreaSqft): string
    {
        if ($plotAreaSqft === null || $plotAreaSqft <= 0) {
            return '5_marla';
        }
        if ($plotAreaSqft <= 1125.0) {
            return '5_marla';
        }
        if ($plotAreaSqft <= 2250.0) {
            return '10_marla';
        }

        return 'above_10_marla';
    }

    private function rulesFromResidentialHouseBands(array $raw, ?float $plotAreaSqft): array
    {
        if ($plotAreaSqft === null || $plotAreaSqft <= 0) {
            return [];
        }
        $marla = $plotAreaSqft / 225.0;
        $coverageRows = data_get($raw, 'source_rulebook_snapshot.residential_house_rules.coverage_far_height_storeys_approved_scheme', []);
        $spaceRows = data_get($raw, 'source_rulebook_snapshot.residential_house_rules.mandatory_open_spaces_approved_scheme', []);
        if (! is_array($coverageRows) || ! is_array($spaceRows)) {
            return [];
        }
        $coverage = $this->pickCoverageBand($coverageRows, $marla);
        $spaces = $this->pickOpenSpaceBand($spaceRows, $marla);
        if (! is_array($coverage) && ! is_array($spaces)) {
            return [];
        }

        $rules = [];
        if (is_array($spaces)) {
            if (is_numeric($spaces['front_ft'] ?? null)) {
                $rules[] = ['id' => 'SETBACK_FRONT', 'value_ft' => (float) $spaces['front_ft']];
            }
            if (is_numeric($spaces['rear_ft'] ?? null)) {
                $rules[] = ['id' => 'SETBACK_REAR', 'value_ft' => (float) $spaces['rear_ft']];
            }
            $side = $this->sideSetbackFromLabel($spaces['side'] ?? null);
            if ($side !== null) {
                $rules[] = ['id' => 'SETBACK_SIDE', 'value_ft' => $side, 'operator' => $side > 0 ? '>=' : '=='];
            }
        }
        if (is_array($coverage)) {
            if (is_numeric($coverage['max_ground_coverage_percent'] ?? null)) {
                $rules[] = ['id' => 'GROUND_COVERAGE', 'value_percent' => (float) $coverage['max_ground_coverage_percent']];
            }
            if (is_numeric($coverage['max_far'] ?? null)) {
                $rules[] = ['id' => 'FAR_LIMIT', 'value' => (float) $coverage['max_far']];
            }
            if (is_numeric($coverage['max_storeys_excluding_basement'] ?? null)) {
                $rules[] = ['id' => 'MAX_STOREYS', 'value' => (float) $coverage['max_storeys_excluding_basement']];
            }
            if (is_numeric($coverage['max_height_ft'] ?? null)) {
                $rules[] = ['id' => 'MAX_HEIGHT', 'value_ft' => (float) $coverage['max_height_ft']];
            }
        }

        return $rules;
    }

    private function pickCoverageBand(array $rows, float $marla): ?array
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $size = (string) ($row['plot_size'] ?? '');
            if ($size === '10_marla_to_less_than_1_kanal' && $marla >= 10 && $marla < 20) return $row;
            if ($size === '1_kanal_to_30_marla' && $marla >= 20 && $marla <= 30) return $row;
            if ($size === 'above_30_marla_to_less_than_2_kanal' && $marla > 30 && $marla < 40) return $row;
            if ($size === '2_kanal_and_above' && $marla >= 40) return $row;
            if ($size === '5_to_less_than_10_marla' && $marla >= 5 && $marla < 10) return $row;
            if ($size === 'less_than_5_marla' && $marla < 5) return $row;
        }
        return null;
    }

    private function pickOpenSpaceBand(array $rows, float $marla): ?array
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $size = (string) ($row['plot_size'] ?? '');
            if ($size === '10_to_30_marla' && $marla >= 10 && $marla <= 30) return $row;
            if ($size === 'above_30_marla_to_less_than_2_kanal' && $marla > 30 && $marla < 40) return $row;
            if ($size === '2_kanal_to_less_than_4_kanal' && $marla >= 40 && $marla < 80) return $row;
            if ($size === '4_kanal_and_above' && $marla >= 80) return $row;
            if ($size === '5_to_less_than_10_marla' && $marla >= 5 && $marla < 10) return $row;
            if ($size === 'less_than_5_marla' && $marla < 5) return $row;
        }
        return null;
    }

    private function sideSetbackFromLabel(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $label = strtolower(trim($value));
        if ($label === 'not_required') {
            return 0.0;
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*ft/', $label, $m)) {
            return (float) $m[1];
        }
        return null;
    }
}
