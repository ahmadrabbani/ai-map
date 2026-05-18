<?php

namespace App\Http\Controllers;

use App\Models\BpAiReport;
use App\Models\BpApplication;
use App\Models\MapDrawing;
use App\Services\AiMapAnalysisService;
use App\Services\AiReportGenerationService;
use App\Services\BuildingPlanNumberService;
use App\Services\ListDocumentExtractionService;
use App\Services\QrCodeService;
use App\Services\ReviewRoutingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BuildingPlanApplicationController extends Controller
{
    public function __construct(
        private readonly BuildingPlanNumberService $numberService,
        private readonly QrCodeService $qrCodeService,
        private readonly AiMapAnalysisService $analysisService,
        private readonly AiReportGenerationService $reportGenerationService,
        private readonly ReviewRoutingService $reviewRoutingService,
        private readonly ListDocumentExtractionService $listExtractionService,
    ) {
    }

    public function index()
    {
        return view('admin.building-plan.upload', [
            'applications' => BpApplication::query()->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'applicant_name' => ['nullable', 'string', 'max:255'],
            'applicant_email' => ['nullable', 'email', 'max:255'],
            'applicant_phone' => ['nullable', 'string', 'max:40'],
            'map_file' => ['required', 'file', 'extensions:dwg,dxf,cad,pdf', 'max:51200'],
            'list_document' => ['nullable', 'file', 'extensions:docx', 'max:10240'],
            'map_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'map_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'map_place_id' => ['nullable', 'string', 'max:255'],
            'map_formatted_address' => ['nullable', 'string', 'max:500'],
            'map_plot_number' => ['nullable', 'string', 'max:120'],
            'map_site_signal' => ['nullable', 'string', 'max:120'],
            'map_geocode_json' => ['nullable', 'string', 'max:65000'],
            'map_scheme' => ['nullable', 'string', 'max:120'],
            'map_phase' => ['nullable', 'string', 'max:120'],
            'map_block' => ['nullable', 'string', 'max:120'],
            'map_plot_ref' => ['nullable', 'string', 'max:120'],
        ]);

        $file = $request->file('map_file');
        $storedPath = $file->store('uploads/bp-applications', 'local');
        $listDocFile = $request->file('list_document');
        $listDocPath = $listDocFile?->store('uploads/bp-applications/metadata', 'local');

        $extracted = null;
        if ($listDocPath) {
            $absolute = Storage::disk('local')->path($listDocPath);
            $extracted = $this->listExtractionService->extractFromDocx($absolute);
        }

        $resolvedName = ($data['applicant_name'] ?? null) ?: ($extracted['applicant']['name'] ?? null);
        $resolvedEmail = ($data['applicant_email'] ?? null) ?: ($extracted['applicant']['email'] ?? null);
        $resolvedPhone = ($data['applicant_phone'] ?? null) ?: ($extracted['applicant']['phone'] ?? null);

        $application = DB::transaction(function () use ($file, $storedPath, $resolvedName, $resolvedEmail, $resolvedPhone, $listDocFile, $listDocPath, $extracted, $data) {
            $applicationNumber = $this->numberService->generate();
            $token = $this->qrCodeService->generateToken();
            $verificationUrl = $this->qrCodeService->verificationUrl($token);
            $qrCodeUrl = $this->qrCodeService->qrImageUrl($verificationUrl);
            $plotData = $extracted['plot'] ?? [];
            if (! is_array($plotData)) {
                $plotData = [];
            }
            $mapSelection = array_filter([
                'lat' => isset($data['map_lat']) ? (float) $data['map_lat'] : null,
                'lng' => isset($data['map_lng']) ? (float) $data['map_lng'] : null,
                'place_id' => $data['map_place_id'] ?? null,
                'formatted_address' => $data['map_formatted_address'] ?? null,
                'plot_number' => $data['map_plot_number'] ?? null,
                'site_signal' => $data['map_site_signal'] ?? null,
                'scheme' => $data['map_scheme'] ?? null,
                'phase' => $data['map_phase'] ?? null,
                'block' => $data['map_block'] ?? null,
                'plot_ref' => $data['map_plot_ref'] ?? null,
                'source' => 'google_maps_search',
            ], fn ($v) => $v !== null && $v !== '');
            $rawGeocode = trim((string) ($data['map_geocode_json'] ?? ''));
            if ($rawGeocode !== '') {
                $decoded = json_decode($rawGeocode, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $geoPath = 'uploads/bp-applications/geocode/' . date('Y/m') . '/' . $applicationNumber . '-geocode.json';
                    Storage::disk('local')->put($geoPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $mapSelection['geocode_json'] = $decoded;
                    $mapSelection['geocode_json_path'] = $geoPath;
                }
            }
            if (! empty($mapSelection)) {
                $plotData['map_selection'] = $mapSelection;
            }

            $application = BpApplication::create([
                'application_number' => $applicationNumber,
                'status' => 'Uploaded',
                'applicant_name' => $resolvedName ?: null,
                'applicant_email' => $resolvedEmail ?: null,
                'applicant_phone' => $resolvedPhone ?: null,
                'uploaded_file_name' => $file->getClientOriginalName(),
                'uploaded_file_path' => $storedPath,
                'uploaded_file_type' => strtolower((string) $file->getClientOriginalExtension()),
                'uploaded_file_size' => $file->getSize(),
                'metadata_doc_name' => $listDocFile?->getClientOriginalName(),
                'metadata_doc_path' => $listDocPath,
                'applicant_data_json' => $extracted['applicant'] ?? null,
                'plot_data_json' => ! empty($plotData) ? $plotData : null,
                'layer_table_json' => $extracted ? [
                    'layers' => $extracted['layers'] ?? [],
                    'sections' => $extracted['sections'] ?? [],
                    'measurement_metrics' => $extracted['measurement_metrics'] ?? [],
                    'rows_count' => $extracted['rows_count'] ?? 0,
                ] : null,
                'qr_token' => $token,
                'verification_url' => $verificationUrl,
                'qr_code_url' => $qrCodeUrl,
            ]);

            $cadSubmission = $this->analysisService->prepareCadSubmission($application, $file, $storedPath);
            $application->cad_submission_id = $cadSubmission->id;
            $application->status = 'AI Analysis In Progress';
            $application->save();

            BpAiReport::create([
                'bp_application_id' => $application->id,
                'analysis_status' => 'pending',
                'ai_recommendation' => 'Needs Expert Review',
                'analysis_json' => ['state' => 'queued'],
            ]);

            return $application;
        });

        $analysis = $this->analysisService->run($application->fresh('cadSubmission'));
        $application = $application->fresh();
        if (! empty($analysis['map_drawing_id'])) {
            $application->map_drawing_id = (int) $analysis['map_drawing_id'];
        }

        $reportPack = $this->reportGenerationService->generate($application, $analysis);

        $application->status = ($analysis['recommendation'] ?? '') === 'Needs Expert Review'
            ? 'Needs Expert Review'
            : 'AI Report Generated';
        $application->save();

        $report = $application->aiReport;
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

        return redirect()->route('admin.plan.bp.portal', $application)->with('status', 'Building plan application created and AI report generated.');
    }

    public function show(BpApplication $application)
    {
        $application->load(['aiReport', 'chatMessages', 'reviewLogs']);

        return view('admin.building-plan.show', [
            'application' => $application,
        ]);
    }

    public function portal(BpApplication $application)
    {
        $application->load(['aiReport', 'chatMessages']);
        $report = $application->aiReport;
        $ruleRows = collect($report->rule_results_json ?? [])->values();
        $layerTable = is_array($application->layer_table_json) ? $application->layer_table_json : [];
        $sectionCards = collect(data_get($layerTable, 'sections', []))->values()->all();
        $textMetrics = is_array(data_get($layerTable, 'measurement_metrics')) ? data_get($layerTable, 'measurement_metrics') : [];
        $hasUsableTextMetrics = collect($textMetrics)->contains(fn ($v) => ! $this->isBlankMetricValue($v));
        $cadApplicant = [];
        $cadPlot = [];
        if ((empty($sectionCards) || ! $hasUsableTextMetrics) && $application->map_drawing_id) {
            $drawing = MapDrawing::find($application->map_drawing_id);
            if ($drawing) {
                $this->analysisService->hydrateCadTextReferencesFromLayers($drawing);
                $drawing->refresh();
            }
            $meta = is_array($drawing?->metadata_json) ? $drawing->metadata_json : [];
            if (empty($sectionCards)) {
                $sectionCards = collect(data_get($meta, 'cad_text_sections', []))->values()->all();
            }
            $cadMetrics = is_array(data_get($meta, 'cad_text_measurement_metrics')) ? data_get($meta, 'cad_text_measurement_metrics') : [];
            if (! empty($cadMetrics)) {
                $textMetrics = array_replace($textMetrics, $cadMetrics);
            }
            $cadApplicant = is_array(data_get($meta, 'cad_text_applicant')) ? data_get($meta, 'cad_text_applicant') : [];
            $cadPlot = is_array(data_get($meta, 'cad_text_plot')) ? data_get($meta, 'cad_text_plot') : [];
        }
        $plotAreaText = is_numeric($textMetrics['plot_area'] ?? null) ? (float) $textMetrics['plot_area'] : null;
        $groundCoveredText = is_numeric($textMetrics['ground_floor_covered'] ?? null) ? (float) $textMetrics['ground_floor_covered'] : null;
        $allCoveredText = is_numeric($textMetrics['total_floor_covered'] ?? null) ? (float) $textMetrics['total_floor_covered'] : null;
        $coverageFormula = ($plotAreaText && $groundCoveredText !== null && $plotAreaText > 0)
            ? round(($groundCoveredText / $plotAreaText) * 100, 2)
            : null;
        $farFormula = ($plotAreaText && $allCoveredText !== null && $plotAreaText > 0)
            ? round(($allCoveredText / $plotAreaText), 4)
            : null;

        $normalizedStatus = fn ($row) => strtolower((string) ($row['status'] ?? ''));
        $counts = [
            'pass' => $ruleRows->filter(fn ($row) => in_array($normalizedStatus($row), ['pass', 'passed'], true))->count(),
            'fail' => $ruleRows->filter(fn ($row) => in_array($normalizedStatus($row), ['fail', 'failed'], true))->count(),
            'needs_review' => $ruleRows->filter(fn ($row) => in_array($normalizedStatus($row), ['needs_review', 'review', 'warn'], true))->count(),
        ];

        $statusLabel = function (string $status): string {
            $normalized = strtolower(trim($status));
            if (in_array($normalized, ['pass', 'passed'], true)) {
                return 'Clear';
            }
            if (in_array($normalized, ['fail', 'failed'], true)) {
                return 'Issue Found';
            }

            return 'Needs Review';
        };

        $laymanChecks = $ruleRows
            ->take(12)
            ->map(function ($row) use ($statusLabel) {
                $rule = trim((string) ($row['rule_code'] ?? 'Rule check'));
                $message = trim((string) ($row['message'] ?? 'Manual review required for this item.'));
                $rawStatus = (string) ($row['status'] ?? '');

                return [
                    'title' => $rule !== '' ? $rule : 'Rule check',
                    'status' => $statusLabel($rawStatus),
                    'message' => $message,
                ];
            })
            ->values()
            ->all();

        $laymanNextStep = 'Your application looks good so far. Submit to AD ePermit for final authority review.';
        if ($counts['fail'] > 0) {
            $laymanNextStep = 'Please fix the items marked as "Issue Found" before final authority submission.';
        } elseif ($counts['needs_review'] > 0) {
            $laymanNextStep = 'Some items need officer review. You can still submit, but final decision is by AD ePermit/DDTP.';
        }

        $laymanClauseReferences = $ruleRows
            ->map(function ($row) {
                $code = (string) ($row['id'] ?? $row['rule_code'] ?? '');
                if ($code === '') {
                    return null;
                }

                return [
                    'rule_code' => $code,
                    'clause_reference' => $this->clauseReferenceForRuleCode($code),
                ];
            })
            ->filter()
            ->unique('rule_code')
            ->values()
            ->all();
        // Key findings for portal should come from text/layer extracted report context,
        // not AI rule-result findings.
        $keyFindings = collect($sectionCards)
            ->map(function ($section) {
                $title = trim((string) ($section['title'] ?? 'Text Layer Section'));
                $description = trim((string) ($section['description'] ?? ''));
                $key = trim((string) ($section['key'] ?? ''));
                $plainTitle = $key !== '' ? ($key . ' - ' . $title) : $title;
                $plainMessage = $description !== ''
                    ? $description
                    : 'This information was read from your uploaded drawing text and is shown for your review.';

                return [
                    'rule' => $plainTitle,
                    'status' => 'INFO',
                    'message' => $plainMessage,
                    'required' => null,
                    'actual' => null,
                ];
            })
            ->filter(fn ($row) => trim((string) ($row['message'] ?? '')) !== '')
            ->take(8)
            ->values();

        if ($keyFindings->isEmpty()) {
            $metricLabels = [
                'plot_area' => 'Plot Area',
                'ground_floor_covered' => 'Ground Floor Covered',
                'total_floor_covered' => 'Total Floor Covered',
                'coverage_percent' => 'Coverage %',
                'far' => 'FAR',
            ];

            $keyFindings = collect($metricLabels)
                ->map(function ($label, $metricKey) use ($textMetrics) {
                    $value = $textMetrics[$metricKey] ?? null;
                    if ($value === null || $value === '') {
                        return null;
                    }

                    return [
                        'rule' => $label,
                        'status' => 'INFO',
                        'message' => 'From your uploaded plan, the ' . strtolower($label) . ' is recorded as shown below.',
                        'required' => null,
                        'actual' => $value,
                    ];
                })
                ->filter()
                ->take(8)
                ->values();
        }

        $keyFindings = $keyFindings->all();

        $reportCards = [
            [
                'key' => '37',
                'title' => 'Applicant Information',
                'description' => 'Applicant/owner information',
                'items' => [
                    'Name' => $application->applicant_name ?: (data_get($application->applicant_data_json, 'name') ?: data_get($cadApplicant, 'name')),
                    'Email' => $application->applicant_email ?: (data_get($application->applicant_data_json, 'email') ?: data_get($cadApplicant, 'email')),
                    'Phone' => $application->applicant_phone ?: (data_get($application->applicant_data_json, 'phone') ?: data_get($cadApplicant, 'phone')),
                    'CNIC' => data_get($cadApplicant, 'cnic'),
                ],
            ],
            [
                'key' => '38',
                'title' => 'Plot Information',
                'description' => 'Plot/site information',
                'items' => [
                    'Plot No' => data_get($application->plot_data_json, 'plot_no') ?: data_get($cadPlot, 'plot_no'),
                    'Plot Size' => data_get($application->plot_data_json, 'plot_size') ?: data_get($cadPlot, 'plot_size'),
                    'Scheme' => data_get($application->plot_data_json, 'scheme') ?: data_get($cadPlot, 'scheme'),
                    'Phase' => data_get($application->plot_data_json, 'phase') ?: data_get($cadPlot, 'phase'),
                    'Block' => data_get($application->plot_data_json, 'block') ?: data_get($cadPlot, 'block'),
                    'Sector' => data_get($application->plot_data_json, 'sector') ?: data_get($cadPlot, 'sector'),
                    'Plot Category' => data_get($application->plot_data_json, 'plot_category') ?: data_get($cadPlot, 'plot_category'),
                    'Building Purpose' => data_get($application->plot_data_json, 'building_purpose') ?: data_get($cadPlot, 'building_purpose'),
                    'Street' => data_get($application->plot_data_json, 'street') ?: data_get($cadPlot, 'street'),
                ],
            ],
            [
                'key' => '39',
                'title' => 'Measurements',
                'description' => 'Plot/building measurements',
                'items' => [
                    'Plot Area' => $textMetrics['plot_area'] ?? null,
                    'Ground Floor Covered' => $textMetrics['ground_floor_covered'] ?? null,
                    'Total Floor Covered' => $textMetrics['total_floor_covered'] ?? null,
                    'Coverage % (Text)' => $textMetrics['coverage_percent'] ?? null,
                    'Coverage % (Formula: GF/Plot)' => $coverageFormula,
                    'FAR (Text)' => $textMetrics['far'] ?? null,
                    'FAR (Formula: All Floors/Plot)' => $farFormula,
                ],
            ],
            [
                'key' => '40',
                'title' => 'Submission Details',
                'description' => 'Submission/application details',
                'items' => [
                    'Application ID' => $application->application_number,
                    'File' => $application->uploaded_file_name,
                    'AI Recommendation' => $application->aiReport->ai_recommendation ?? null,
                    'AI Confidence %' => $application->aiReport->ai_confidence_score ?? null,
                ],
            ],
        ];

        return view('admin.building-plan.portal', [
            'application' => $application,
            'counts' => $counts,
            'keyFindings' => $keyFindings,
            'laymanChecks' => $laymanChecks,
            'laymanNextStep' => $laymanNextStep,
            'laymanClauseReferences' => $laymanClauseReferences,
            'reportCards' => $reportCards,
            'sectionCards' => $sectionCards,
        ]);
    }

    private function clauseReferenceForRuleCode(string $ruleCode): string
    {
        $map = [
            'SETBACK_FRONT' => 'Clause 2.1.3 / 2.2.3 (front open space)',
            'SETBACK_REAR' => 'Clause 2.1.3 / 2.2.3 (rear open space)',
            'SETBACK_SIDE' => 'Clause 2.1.3 / 2.2.3 (side open space)',
            'GROUND_COVERAGE' => 'Clause 2.2.1 / 2.1.1 (maximum ground coverage)',
            'FAR_LIMIT' => 'Clause 2.2.1 / 2.1.1 / 2.3.1 (FAR limit)',
            'MAX_STOREYS' => 'Clause 2.2.1 / 2.1.1 (storey limit)',
            'MAX_HEIGHT' => 'Clause 2.2.1 / 2.1.1 (height limit)',
            'PORCH_LENGTH' => 'Clause 5.3.3 (porch and side space)',
            'REAR_TOILET_AREA' => 'Clause 2.7(iii) (rear service/toilet constraint)',
            'REAR_TOILET_HEIGHT' => 'Clause 2.7(iii) (rear service/toilet constraint)',
        ];

        return $map[$ruleCode] ?? 'Clause reference pending mapping';
    }

    private function isBlankMetricValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            $v = trim($value);
            return $v === '' || $v === '-' || strtolower($v) === 'n/a';
        }

        return false;
    }

    public function submitToAdEpermit(BpApplication $application)
    {
        $this->reviewRoutingService->transition(
            $application,
            'Submitted to AD ePermit',
            'submit_to_ad_epermit',
            'Application submitted for AD ePermit review. External system push is pending AD approval.'
        );

        return back()->with('status', 'Application submitted to AD ePermit for review.');
    }
}
