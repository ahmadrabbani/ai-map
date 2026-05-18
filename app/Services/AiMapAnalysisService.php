<?php

namespace App\Services;

use App\Models\BpApplication;
use App\Models\CadSubmission;
use App\Models\MapDrawing;
use App\Models\MapEntity;
use App\Services\MapApproval\GeometryCalculationService;
use App\Services\MapApproval\MapApprovalPipelineService;
use App\Services\MapApproval\MapApprovalReportService;
use App\Services\MapApproval\RuleValidationService;
use App\Services\MapApproval\StructuralExtractionService;
use App\Services\Ml\ImageryBuildSignalService;
use App\Services\Ml\RuleRiskScoringService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AiMapAnalysisService
{
    public function __construct(
        private readonly CadComplianceService $cadComplianceService,
        private readonly MapApprovalPipelineService $mapPipelineService,
        private readonly GeometryCalculationService $geometryCalculationService,
        private readonly RuleValidationService $mapRuleValidationService,
        private readonly MapApprovalReportService $mapReportService,
        private readonly StructuralExtractionService $structuralExtractionService,
        private readonly RuleRiskScoringService $ruleRiskScoringService,
        private readonly ImageryBuildSignalService $imageryBuildSignalService,
    ) {
    }

    public function prepareCadSubmission(BpApplication $application, UploadedFile $file, string $storedPath): CadSubmission
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());

        return CadSubmission::create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_dwg_path' => $ext === 'dwg' ? $storedPath : null,
            'stored_dxf_path' => $ext === 'dxf' ? $storedPath : null,
            'ruleset_key' => 'residential_building_approval',
            'analysis_result' => [
                'source' => 'bp_application',
                'bp_application_id' => $application->id,
            ],
        ]);
    }

    public function run(BpApplication $application): array
    {
        $cadSubmission = $application->cadSubmission;
        if (! $cadSubmission) {
            return [
                'status' => 'needs_expert_review',
                'recommendation' => 'Needs Expert Review',
                'confidence_score' => 0,
                'analysis_json' => ['error' => 'cad_submission_missing'],
                'warnings' => ['CAD submission record is missing.'],
                'expert_review_items' => ['CAD submission missing.'],
                'map_drawing_id' => null,
            ];
        }

        $ext = strtolower((string) $application->uploaded_file_type);

        try {
            if ($ext === 'dwg') {
                $dwgAbs = Storage::disk('local')->path((string) $cadSubmission->stored_dwg_path);
                $run = $this->cadComplianceService->processSubmission($cadSubmission, $dwgAbs, [
                    'allow_heuristic_fallback' => true,
                ]);

                $analysis = (array) ($run['analysis_result'] ?? []);
                $rules = (array) ($run['rules'] ?? []);
                $status = (string) ($analysis['status'] ?? 'needs_expert_review');
                $recommendation = $this->recommendationFromStatusAndRules($status, $rules);
                $mapDrawingId = null;

                if ($cadSubmission->fresh()->stored_dxf_path) {
                    try {
                        $mapped = $this->mapPipelineService->mapExistingCadSubmission($cadSubmission->fresh());
                        $drawing = $mapped['drawing']->fresh('entities');
                        $this->hydrateCadTextReferencesFromLayers($drawing);
                        $structural = $this->extractAndPersistStructural($drawing);
                        $geometry = $this->geometryCalculationService->calculate($drawing);
                        $semanticRules = $this->mapRuleValidationService->validate($drawing->fresh('entities'), $geometry);
                        $report = $this->mapReportService->generate($drawing->fresh(['entities', 'geometryResults', 'ruleResults']));
                        $mapDrawingId = $drawing->id;
                        $rules = ! empty($semanticRules) ? $semanticRules : $rules;
                        $status = (string) ($report['status'] ?? $status);
                        $recommendation = $this->recommendationFromReportStatus($status);
                        $run['map_pipeline'] = $mapped;
                        $run['map_report'] = $report;
                        $run['structural_extraction'] = $structural;
                    } catch (\Throwable $semanticError) {
                        $analysis['semantic_pipeline_warning'] = $semanticError->getMessage();
                    }
                }

                if (! $mapDrawingId) {
                    $fallbackDrawing = $this->buildDwgTextFallbackDrawing($application, $cadSubmission);
                    if ($fallbackDrawing) {
                        $this->hydrateCadTextReferencesFromLayers($fallbackDrawing);
                        $mapDrawingId = $fallbackDrawing->id;
                        $analysis['dwg_text_fallback'] = 'applied';
                    }
                }

                return [
                    'status' => $status,
                    'recommendation' => $recommendation,
                    'confidence_score' => $this->confidenceFromRules($rules),
                    'analysis_json' => $this->attachImagerySignal(
                        $this->attachMlAdvisory($run, $rules),
                        $application
                    ),
                    'warnings' => (array) ($analysis['warnings'] ?? []),
                    'expert_review_items' => $this->expertItemsFromAnalysis($analysis, $rules),
                    'map_drawing_id' => $mapDrawingId,
                ];
            }

            if ($ext === 'dxf') {
                // Reuse semantic mapping + geometry/rule pipeline for DXF-first uploads.
                $mapped = $this->mapPipelineService->mapExistingCadSubmission($cadSubmission);
                $drawing = $mapped['drawing']->fresh('entities');
                $this->hydrateCadTextReferencesFromLayers($drawing);
                $structural = $this->extractAndPersistStructural($drawing);
                $geometry = $this->geometryCalculationService->calculate($drawing);
                $this->mapRuleValidationService->validate($drawing->fresh('entities'), $geometry);
                $report = $this->mapReportService->generate($drawing->fresh(['entities', 'geometryResults', 'ruleResults']));

                $rules = (array) ($report['rules'] ?? []);
                $status = (string) ($report['status'] ?? 'needs_expert_review');

                return [
                    'status' => $status,
                    'recommendation' => $this->recommendationFromReportStatus($status),
                    'confidence_score' => $this->confidenceFromRules($rules),
                    'analysis_json' => $this->attachImagerySignal(
                        $this->attachMlAdvisory([
                            'map_pipeline' => $mapped,
                            'map_report' => $report,
                            'structural_extraction' => $structural,
                        ], $rules),
                        $application
                    ),
                    'warnings' => (array) ($report['expert_review_reasons'] ?? []),
                    'expert_review_items' => (array) ($report['missing_required_entities'] ?? []),
                    'map_drawing_id' => $drawing->id,
                ];
            }

            // PDF/CAD other formats: keep app alive and request expert review.
            return [
                'status' => 'needs_expert_review',
                'recommendation' => 'Needs Expert Review',
                'confidence_score' => 0,
                'analysis_json' => [
                    'message' => 'AI CAD analysis is currently available for DWG/DXF uploads.',
                    'uploaded_type' => $ext,
                ],
                'warnings' => ['Unsupported analysis type for fully automated CAD parsing.'],
                'expert_review_items' => ['Manual expert review required for uploaded format.'],
                'map_drawing_id' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'needs_expert_review',
                'recommendation' => 'Needs Expert Review',
                'confidence_score' => 0,
                'analysis_json' => [
                    'exception' => $e->getMessage(),
                    'trace_hint' => Str::limit($e->getTraceAsString(), 2000),
                ],
                'warnings' => ['AI analysis failed; expert review fallback applied.'],
                'expert_review_items' => ['AI pipeline failed and requires expert verification.'],
                'map_drawing_id' => null,
            ];
        }
    }

    private function recommendationFromStatusAndRules(string $status, array $rules): string
    {
        if ($status === 'ok' && $this->hasAnyFail($rules) === false) {
            return 'Passed';
        }
        if ($this->hasAnyFail($rules)) {
            return 'Failed';
        }

        return 'Needs Expert Review';
    }

    private function recommendationFromReportStatus(string $status): string
    {
        return match ($status) {
            'ready_for_submission' => 'Passed',
            'needs_correction' => 'Failed',
            default => 'Needs Expert Review',
        };
    }

    private function hasAnyFail(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (($rule['status'] ?? null) === 'fail' || ($rule['pass'] ?? null) === false) {
                return true;
            }
        }

        return false;
    }

    private function confidenceFromRules(array $rules): float
    {
        if (empty($rules)) {
            return 0.0;
        }
        $scored = 0;
        $score = 0;
        foreach ($rules as $rule) {
            $status = $rule['status'] ?? null;
            if ($status === 'pass' || ($rule['pass'] ?? null) === true) {
                $score += 1;
                $scored++;
            } elseif ($status === 'fail' || ($rule['pass'] ?? null) === false) {
                $scored++;
            }
        }
        if ($scored === 0) {
            return 0.0;
        }
        return round(($score / $scored) * 100, 2);
    }

    private function expertItemsFromAnalysis(array $analysis, array $rules): array
    {
        $items = [];
        foreach ((array) ($analysis['recommended_next_step'] ?? []) as $k => $v) {
            $items[] = $k . ': ' . (is_scalar($v) ? (string) $v : json_encode($v));
        }
        foreach ($rules as $rule) {
            $isFail = (($rule['status'] ?? null) === 'fail') || (($rule['pass'] ?? null) === false);
            if ($isFail) {
                $items[] = 'Rule failed: ' . (($rule['id'] ?? $rule['rule_code'] ?? 'unknown'));
            }
        }

        return array_values(array_unique(array_filter($items)));
    }

    private function attachMlAdvisory(array $analysisJson, array $rules): array
    {
        $analysisJson['ml_advisory'] = $this->ruleRiskScoringService->score($rules);
        $analysisJson['decision_policy'] = [
            'primary_engine' => 'strict_rule_engine',
            'ml_role' => 'advisory_only',
            'note' => 'ML score supports triage and confidence interpretation only. Final pass/fail stays rule-based.',
        ];

        return $analysisJson;
    }

    private function attachImagerySignal(array $analysisJson, BpApplication $application): array
    {
        $analysisJson['imagery_signal'] = $this->imageryBuildSignalService->score($application);
        $analysisJson['imagery_policy'] = [
            'role' => 'advisory_only',
            'note' => 'Imagery signal supports triage only. Final compliance is rule-engine + expert decision.',
        ];

        return $analysisJson;
    }

    private function extractAndPersistStructural(MapDrawing $drawing): array
    {
        $structural = $this->structuralExtractionService->extract($drawing);
        $metadata = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
        $metadata['structural_extraction'] = $structural;
        $drawing->metadata_json = $metadata;
        $drawing->save();

        return $structural;
    }

    private function buildDwgTextFallbackDrawing(BpApplication $application, CadSubmission $submission): ?MapDrawing
    {
        $dwgRel = (string) ($submission->stored_dwg_path ?? '');
        if ($dwgRel === '' || ! Storage::disk('local')->exists($dwgRel)) {
            return null;
        }

        $abs = Storage::disk('local')->path($dwgRel);
        $binary = @file_get_contents($abs);
        if (! is_string($binary) || $binary === '') {
            return null;
        }

        preg_match_all('/[ -~]{4,}/', $binary, $matches);
        $chunks = array_values(array_unique(array_map(
            fn ($v) => trim((string) $v),
            is_array($matches[0] ?? null) ? $matches[0] : []
        )));

        $filtered = array_values(array_filter($chunks, function (string $text): bool {
            if (strlen($text) > 240) {
                return false;
            }
            $low = strtolower($text);
            return str_contains($low, 'plot')
                || str_contains($low, 'block')
                || str_contains($low, 'phase')
                || str_contains($low, 'scheme')
                || str_contains($low, 'street')
                || str_contains($low, 'applicant')
                || str_contains($low, 'owner')
                || str_contains($low, 'cnic')
                || str_contains($low, 'phone')
                || str_contains($low, 'contact')
                || str_contains($low, 'area')
                || str_contains($low, 'coverage')
                || str_contains($low, 'far')
                || str_contains($low, 'setback')
                || str_contains($low, 'marla')
                || str_contains($low, 'sq ft')
                || preg_match('/\b\d{1,3}\s*[\-\'xX"]\s*\d{1,3}\b/', $text) === 1;
        }));

        if (empty($filtered)) {
            return null;
        }

        $drawing = MapDrawing::create([
            'application_id' => $application->id,
            'original_file_path' => $submission->stored_dwg_path,
            'dxf_file_path' => $submission->stored_dxf_path,
            'status' => 'mapped',
            'mapping_status' => 'fallback_text_only',
            'validation_status' => 'pending',
            'metadata_json' => [
                'cad_submission_id' => $submission->id,
                'original_filename' => $submission->original_filename,
                'dwg_text_fallback' => true,
            ],
        ]);

        foreach (array_slice($filtered, 0, 700) as $idx => $text) {
            $layer = $this->guessFallbackLayer($text);
            MapEntity::create([
                'map_drawing_id' => $drawing->id,
                'handle' => 'dwg_txt_' . ($idx + 1),
                'layer_name' => $layer,
                'entity_type' => 'TEXT',
                'geometry_json' => [
                    'points' => [[(float) $idx, 0.0]],
                    'text_content' => $text,
                    'source' => 'dwg_binary_string_fallback',
                ],
                'bbox_json' => ['min_x' => (float) $idx, 'min_y' => 0, 'max_x' => (float) $idx, 'max_y' => 0],
                'area' => 0,
                'perimeter' => 0,
                'is_closed' => false,
                'confidence_score' => 55,
                'mapping_status' => 'unmapped',
                'mapping_source' => 'dwg_text_fallback',
            ]);
        }

        return $drawing;
    }

    private function guessFallbackLayer(string $text): string
    {
        $low = strtolower($text);
        if (str_contains($low, 'applicant') || str_contains($low, 'owner') || str_contains($low, 'cnic') || str_contains($low, 'phone')) {
            return 'Applicant Information';
        }
        if (str_contains($low, 'plot') || str_contains($low, 'scheme') || str_contains($low, 'phase') || str_contains($low, 'block') || str_contains($low, 'street')) {
            return 'Plot Information';
        }
        if (str_contains($low, 'submission') || str_contains($low, 'application id') || str_contains($low, 'file')) {
            return 'Submission Information';
        }

        return 'Measurement Information';
    }

    public function hydrateCadTextReferencesFromLayers(MapDrawing $drawing): void
    {
        $drawing->loadMissing('entities');
        $metadata = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];

        $existing = is_array(data_get($metadata, 'cad_text_references'))
            ? (array) data_get($metadata, 'cad_text_references')
            : [];
        $existingMetrics = is_array(data_get($metadata, 'cad_text_measurement_metrics'))
            ? (array) data_get($metadata, 'cad_text_measurement_metrics')
            : [];
        $existingRoomAreas = is_array(data_get($metadata, 'cad_text_room_areas'))
            ? (array) data_get($metadata, 'cad_text_room_areas')
            : [];
        $hasCoreMetrics = collect(['plot_area', 'ground_floor_covered', 'total_floor_covered', 'number_of_floors', 'provided_height_ft'])
            ->every(fn ($key) => ($existingMetrics[$key] ?? null) !== null);
        if (! empty($existing) && $hasCoreMetrics && ! empty($existingRoomAreas)) {
            return;
        }

        $rows = [];
        $sections = [];
        $metrics = [
            'plot_area' => null,
            'ground_floor_covered' => null,
            'basement_floor_covered' => null,
            'first_floor_covered' => null,
            'second_floor_covered' => null,
            'total_floor_covered' => null,
            'open_area' => null,
            'coverage_percent' => null,
            'far' => null,
            'number_of_floors' => null,
            'approved_height_ft' => null,
            'provided_height_ft' => null,
            'front_setback_ft' => null,
            'rear_setback_ft' => null,
            'right_setback_ft' => null,
            'left_setback_ft' => null,
        ];
        $applicant = ['name' => null, 'email' => null, 'phone' => null, 'cnic' => null, 'raw' => []];
        $plot = [
            'plot_no' => null,
            'plot_size' => null,
            'street' => null,
            'scheme' => null,
            'phase' => null,
            'block' => null,
            'sector' => null,
            'plot_category' => null,
            'building_purpose' => null,
            'raw' => [],
        ];
        $textByLayer = [];
        $textItemsByLayer = [];
        $allTextItems = [];

        foreach ($drawing->entities as $entity) {
            $text = $this->cleanCadText((string) data_get($entity->geometry_json, 'text_content', ''));
            if ($text === '') {
                continue;
            }
            $layer = (string) ($entity->layer_name ?? '');
            $layerNorm = strtolower(trim(preg_replace('/^\d+\s*[\.\-_\):\s]+\s*/', '', $layer)));
            $layerKey = $this->canonicalTextLayerKey($layerNorm);
            $textByLayer[$layerKey] = $textByLayer[$layerKey] ?? [];
            $textByLayer[$layerKey][] = $text;
            $point = data_get($entity->geometry_json, 'points.0', []);
            $item = [
                'text' => $text,
                'x' => is_numeric($point[0] ?? null) ? (float) $point[0] : null,
                'y' => is_numeric($point[1] ?? null) ? (float) $point[1] : null,
                'layer' => $layer,
            ];
            $textItemsByLayer[$layerKey] = $textItemsByLayer[$layerKey] ?? [];
            $textItemsByLayer[$layerKey][] = $item;
            $allTextItems[] = $item;

            $hints = [];
            if (str_contains($layerNorm, 'measurement') || str_contains($layerNorm, 'dimension') || str_contains($layerNorm, 'text')) {
                $hints[] = 'dimensions';
            }
            if (str_contains($layerNorm, 'front building')) $hints[] = 'front_building_line';
            if (str_contains($layerNorm, 'rear building')) $hints[] = 'rear_building_line';
            if (str_contains($layerNorm, 'side building')) $hints[] = 'side_building_line';
            if (str_contains($layerNorm, 'porch')) $hints[] = 'porch';

            if (preg_match('/\b(applicant|owner|plot|measurement|submission)\b/i', $layerNorm, $m)) {
                $sections[] = [
                    'key' => preg_match('/^(\d{1,3})\b/', trim($layer), $km) ? (string) $km[1] : null,
                    'title' => ucwords($m[1]) . ' Information',
                    'description' => $text,
                ];
            }

            $lowText = strtolower($text);
            if (str_contains($layerNorm, 'applicant') || str_contains($layerNorm, 'owner')) {
                $applicant['raw'][] = $text;
                if ($applicant['email'] === null && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) {
                    $applicant['email'] = $m[0];
                }
                if ($applicant['phone'] === null && preg_match('/\b(phone|mobile|contact|cell)\b/i', $text) && preg_match('/(?:\+?\d[\d\s\-]{8,}\d)/', $text, $m)) {
                    $applicant['phone'] = trim($m[0]);
                }
                if ($applicant['name'] === null && preg_match('/(?:applicant|owner|name)\s*[:\-]?\s*([A-Za-z][A-Za-z\s\.]{2,})/i', $text, $m)) {
                    $applicant['name'] = trim($m[1]);
                }
            }
            if (str_contains($layerNorm, 'plot')) {
                $plot['raw'][] = $text;
                if ($plot['plot_no'] === null && preg_match('/\bplot\s*(?:no\.?|number)?\s*[:\-]?\s*([A-Z0-9\-\/]+)/i', $text, $m)) {
                    $plot['plot_no'] = trim($m[1]);
                }
                if ($plot['plot_size'] === null && preg_match('/\b(\d+(?:\.\d+)?)\s*(marla|kanal|sq\s*ft|sqft|square\s*feet)\b/i', $text, $m)) {
                    $plot['plot_size'] = trim($m[0]);
                }
                if ($plot['street'] === null && preg_match('/\b(street|road)\b\s*[:\-]?\s*([A-Z0-9\-\s]+)/i', $text, $m)) {
                    $plot['street'] = trim($m[2]);
                }
            }
            if ($metrics['plot_area'] === null && str_contains($lowText, 'plot area') && preg_match('/\b(\d+(?:\.\d+)?)\b/', $lowText, $m)) {
                $metrics['plot_area'] = (float) $m[1];
            }
            if ($metrics['ground_floor_covered'] === null && str_contains($lowText, 'ground') && str_contains($lowText, 'covered') && preg_match('/\b(\d+(?:\.\d+)?)\b/', $lowText, $m)) {
                $metrics['ground_floor_covered'] = (float) $m[1];
            }
            if ($metrics['total_floor_covered'] === null && (str_contains($lowText, 'total covered') || str_contains($lowText, 'all floor covered')) && preg_match('/\b(\d+(?:\.\d+)?)\b/', $lowText, $m)) {
                $metrics['total_floor_covered'] = (float) $m[1];
            }
            if ($metrics['coverage_percent'] === null && str_contains($lowText, 'coverage') && preg_match('/(\d+(?:\.\d+)?)\s*%/', $lowText, $m)) {
                $metrics['coverage_percent'] = (float) $m[1];
            }
            if ($metrics['far'] === null && str_contains($lowText, 'far') && preg_match('/\b(\d+(?:\.\d+)?)\b/', $lowText, $m)) {
                $metrics['far'] = (float) $m[1];
            }

            $rows[] = [
                'text' => $text,
                'layer' => $layer,
                'value_ft' => null,
                'semantic_hints' => array_values(array_unique($hints)),
            ];
        }

        $measurementBlob = strtolower(implode(' ', $textByLayer['measurements'] ?? []));
        if ($measurementBlob !== '') {
            $metrics = array_replace($metrics, array_filter([
                'plot_area' => $metrics['plot_area'] ?? $this->numberAfterLabel($measurementBlob, ['plot area', 'plot size', 'plot']),
                'ground_floor_covered' => $metrics['ground_floor_covered'] ?? $this->numberAfterLabel($measurementBlob, ['ground floor covered', 'ground covered', 'ground floor coverage', 'covered area ground']),
                'total_floor_covered' => $metrics['total_floor_covered'] ?? $this->numberAfterLabel($measurementBlob, ['total floor covered', 'total covered', 'all floor covered', 'total covered area']),
                'coverage_percent' => $metrics['coverage_percent'] ?? $this->percentAfterLabel($measurementBlob, ['coverage', 'ground coverage', 'covered percentage']),
                'far' => $metrics['far'] ?? $this->numberAfterLabel($measurementBlob, ['far']),
            ], fn ($value) => $value !== null));
        }
        if (! empty($textItemsByLayer['measurements'])) {
            $metrics = array_replace($metrics, array_filter(
                $this->measurementMetricsFromRows($textItemsByLayer['measurements']),
                fn ($value) => $value !== null
            ));
        }

        $applicantBlob = implode(' ', $textByLayer['applicant information'] ?? []);
        if ($applicantBlob !== '') {
            $applicant['raw'][] = $applicantBlob;
            if ($applicant['email'] === null && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $applicantBlob, $m)) {
                $applicant['email'] = $m[0];
            }
            if ($applicant['phone'] === null && preg_match('/\b(phone|mobile|contact|cell)\b/i', $applicantBlob) && preg_match('/(?:\+?\d[\d\s\-]{8,}\d)/', $applicantBlob, $m)) {
                $applicant['phone'] = trim($m[0]);
            }
            if ($applicant['name'] === null) {
                $applicant['name'] = $this->textAfterLabel($applicantBlob, ['applicant name', 'owner name', 'name']);
            }
        }
        if (! empty($textItemsByLayer['applicant information'])) {
            $applicant = array_replace($applicant, array_filter(
                $this->applicantFromRows($textItemsByLayer['applicant information']),
                fn ($value) => $value !== null && $value !== []
            ));
        }

        $plotBlob = implode(' ', $textByLayer['plot information'] ?? []);
        if ($plotBlob !== '') {
            $plot['raw'][] = $plotBlob;
            if ($plot['plot_no'] === null && preg_match('/\bplot\s*(?:no\.?|number)?\s*[:\-]?\s*([A-Z0-9\-\/]+)/i', $plotBlob, $m)) {
                $plot['plot_no'] = trim($m[1]);
            }
            if ($plot['plot_size'] === null && preg_match('/\b(\d+(?:\.\d+)?)\s*(marla|kanal|sq\s*ft|sqft|square\s*feet)\b/i', $plotBlob, $m)) {
                $plot['plot_size'] = trim($m[0]);
            }
            if ($plot['street'] === null && preg_match('/\b(street|road)\b\s*[:\-]?\s*([A-Z0-9\-\s]+)/i', $plotBlob, $m)) {
                $plot['street'] = trim($m[2]);
            }
        }
        if (! empty($textItemsByLayer['plot information'])) {
            $plot = array_replace($plot, array_filter(
                $this->plotFromRows($textItemsByLayer['plot information']),
                fn ($value) => $value !== null && $value !== []
            ));
        }

        if ($metrics['plot_area'] === null && is_string($plot['plot_size'] ?? null)) {
            $metrics['plot_area'] = $this->plotAreaFromSizeText((string) $plot['plot_size']);
        }
        if (($plot['plot_size'] ?? null) === null && is_numeric($metrics['plot_area'] ?? null) && (float) $metrics['plot_area'] > 0) {
            $plot['plot_size'] = rtrim(rtrim((string) round(((float) $metrics['plot_area']) / 225, 2), '0'), '.') . ' Marla';
        }
        $roomAreas = $this->roomAreasFromTextItems($allTextItems);
        $metrics = $this->mergeMetricsFromRoomAreas($metrics, $roomAreas);
        $metrics = $this->mergeSetbackMetricsFromNearbyText($metrics, $allTextItems);

        if (empty($rows)) {
            return;
        }

        $metadata['cad_text_references'] = array_slice($rows, 0, 800);
        $metadata['cad_text_sections'] = array_values(array_slice($sections, 0, 100));
        $metadata['cad_text_measurement_metrics'] = $metrics;
        $metadata['cad_text_room_areas'] = $roomAreas;
        $metadata['cad_text_applicant'] = $applicant;
        $metadata['cad_text_plot'] = $plot;
        $metadata['cad_text_references_updated_at'] = now()->toISOString();
        $drawing->metadata_json = $metadata;
        $drawing->save();
    }

    private function mergeMetricsFromRoomAreas(array $metrics, array $roomAreas): array
    {
        if (empty($roomAreas)) {
            return $metrics;
        }

        $totalsByFloor = [];
        foreach ($roomAreas as $row) {
            $area = is_numeric($row['area_sqft'] ?? null) ? (float) $row['area_sqft'] : 0.0;
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

        if (($metrics['ground_floor_covered'] ?? null) === null && isset($totalsByFloor['GF'])) {
            $metrics['ground_floor_covered'] = round((float) $totalsByFloor['GF'], 4);
        }
        if (($metrics['total_floor_covered'] ?? null) === null) {
            $metrics['total_floor_covered'] = round((float) array_sum($totalsByFloor), 4);
        }
        if (($metrics['number_of_floors'] ?? null) === null) {
            $metrics['number_of_floors'] = count($totalsByFloor);
        }

        return $metrics;
    }

    private function mergeSetbackMetricsFromNearbyText(array $metrics, array $items): array
    {
        $items = array_values(array_filter($items, fn ($item) => trim((string) ($item['text'] ?? '')) !== ''));
        if (empty($items)) {
            return $metrics;
        }

        $dimensions = [];
        foreach ($items as $item) {
            $text = $this->cleanCadText((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $pair = $this->dimensionPairFromText($text);
            if ($pair) {
                $dimensions[] = array_merge($item, ['dimension' => $pair]);
            }
        }

        if (empty($dimensions)) {
            return $metrics;
        }

        $anchors = [];
        foreach ($items as $item) {
            $text = strtolower($this->cleanCadText((string) ($item['text'] ?? '')));
            if ($text === '') {
                continue;
            }

            if (str_contains($text, 'front') && str_contains($text, 'setback') || str_contains($text, 'front mandatory')) {
                $anchors['front_setback_ft'][] = $item;
            }
            if (str_contains($text, 'rear') && str_contains($text, 'setback') || str_contains($text, 'rear mandatory')) {
                $anchors['rear_setback_ft'][] = $item;
            }
            if (str_contains($text, 'left') && str_contains($text, 'setback') || str_contains($text, 'left mandatory')) {
                $anchors['left_setback_ft'][] = $item;
            }
            if (str_contains($text, 'right') && str_contains($text, 'setback') || str_contains($text, 'right mandatory')) {
                $anchors['right_setback_ft'][] = $item;
            }
        }

        foreach (['front_setback_ft', 'rear_setback_ft', 'left_setback_ft', 'right_setback_ft'] as $key) {
            if (($metrics[$key] ?? null) !== null) {
                continue;
            }
            foreach (($anchors[$key] ?? []) as $anchor) {
                $near = $this->nearestDimensionForLabel($anchor, $dimensions);
                if (! $near) {
                    continue;
                }
                $d = (array) ($near['dimension'] ?? []);
                $v = is_numeric($d['width_ft'] ?? null) ? (float) $d['width_ft'] : null;
                if ($v !== null && $v >= 0 && $v <= 100) {
                    $metrics[$key] = round($v, 4);
                    break;
                }
            }
        }

        return $metrics;
    }

    private function numberAfterLabel(string $text, array $labels): ?float
    {
        foreach ($labels as $label) {
            $pattern = '/' . preg_quote($label, '/') . '[^0-9]{0,80}([0-9]+(?:\.[0-9]+)?)/i';
            if (preg_match($pattern, $text, $m)) {
                return round((float) $m[1], 4);
            }
        }

        return null;
    }

    private function percentAfterLabel(string $text, array $labels): ?float
    {
        foreach ($labels as $label) {
            $pattern = '/' . preg_quote($label, '/') . '[^0-9%]{0,40}([0-9]+(?:\.[0-9]+)?)\s*%?/i';
            if (preg_match($pattern, $text, $m)) {
                $value = (float) $m[1];
                if ($value >= 0 && $value <= 100) {
                    return round($value, 4);
                }
            }
        }

        return null;
    }

    private function textAfterLabel(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/' . preg_quote($label, '/') . '\s*[:=\-]?\s*([A-Za-z][A-Za-z\s\.]{2,60})/i';
            if (preg_match($pattern, $text, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private function plotAreaFromSizeText(string $value): ?float
    {
        if (! preg_match('/(\d+(?:\.\d+)?)\s*(marla|kanal|sq\s*ft|sqft|square\s*feet)/i', $value, $m)) {
            return null;
        }

        $number = (float) $m[1];
        $unit = strtolower((string) $m[2]);

        return match (true) {
            str_contains($unit, 'marla') => round($number * 225, 3),
            str_contains($unit, 'kanal') => round($number * 4500, 3),
            default => round($number, 3),
        };
    }

    private function cleanCadText(string $value): string
    {
        $value = str_replace(['\\P', '\\~', '\\X'], ' ', $value);
        $value = preg_replace('/\\\\[A-Za-z][^;]*;/', ' ', (string) $value);
        $value = str_replace(['{', '}'], ' ', (string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
    }

    private function canonicalTextLayerKey(string $normalizedLayer): string
    {
        $v = strtolower(trim($normalizedLayer));
        if ($v === '') {
            return $v;
        }

        return match (true) {
            str_contains($v, 'measurement information') => 'measurements',
            str_contains($v, 'measurement text') => 'measurements',
            str_contains($v, 'dimension') => 'measurements',
            str_contains($v, 'submission information') => 'submission details',
            default => $v,
        };
    }

    private function measurementMetricsFromRows(array $items): array
    {
        $metrics = [
            'plot_area' => null,
            'ground_floor_covered' => null,
            'basement_floor_covered' => null,
            'first_floor_covered' => null,
            'second_floor_covered' => null,
            'total_floor_covered' => null,
            'open_area' => null,
            'coverage_percent' => null,
            'far' => null,
            'number_of_floors' => null,
            'approved_height_ft' => null,
            'provided_height_ft' => null,
            'front_setback_ft' => null,
            'rear_setback_ft' => null,
            'right_setback_ft' => null,
            'left_setback_ft' => null,
        ];

        foreach ($this->groupTextItemsIntoRows($items) as $row) {
            $rowText = strtolower($this->rowText($row));
            $valueText = $this->valueSideText($row);

            if ($metrics['plot_area'] === null && str_contains($rowText, 'plot area') && str_contains($rowText, 'sq')) {
                $metrics['plot_area'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['basement_floor_covered'] === null && (str_contains($rowText, 'basment') || str_contains($rowText, 'basement')) && str_contains($rowText, 'covered')) {
                $metrics['basement_floor_covered'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['ground_floor_covered'] === null && str_contains($rowText, 'ground') && str_contains($rowText, 'covered')) {
                $metrics['ground_floor_covered'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['first_floor_covered'] === null && str_contains($rowText, 'first') && str_contains($rowText, 'covered')) {
                $metrics['first_floor_covered'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['second_floor_covered'] === null && str_contains($rowText, 'second') && str_contains($rowText, 'covered')) {
                $metrics['second_floor_covered'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['total_floor_covered'] === null && str_contains($rowText, 'total') && str_contains($rowText, 'covered')) {
                $metrics['total_floor_covered'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['open_area'] === null && str_contains($rowText, 'open area')) {
                $metrics['open_area'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['coverage_percent'] === null && str_contains($rowText, 'coverage')) {
                $metrics['coverage_percent'] = $this->firstPercentOrNumber($valueText) ?? $this->firstPercentOrNumber($rowText);
            } elseif ($metrics['far'] === null && preg_match('/\bfar\b/i', $rowText)) {
                $metrics['far'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['number_of_floors'] === null && str_contains($rowText, 'number of floor')) {
                $metrics['number_of_floors'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['approved_height_ft'] === null && str_contains($rowText, 'approved height')) {
                $metrics['approved_height_ft'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['provided_height_ft'] === null && str_contains($rowText, 'provided height')) {
                $metrics['provided_height_ft'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['front_setback_ft'] === null && (str_contains($rowText, 'front mandatory') || str_contains($rowText, 'frot mandatory'))) {
                $metrics['front_setback_ft'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['rear_setback_ft'] === null && str_contains($rowText, 'rear mandatory')) {
                $metrics['rear_setback_ft'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['right_setback_ft'] === null && str_contains($rowText, 'right mandatory')) {
                $metrics['right_setback_ft'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            } elseif ($metrics['left_setback_ft'] === null && str_contains($rowText, 'left mandatory')) {
                $metrics['left_setback_ft'] = $this->firstNumber($valueText) ?? $this->firstNumber($rowText);
            }
        }

        return $metrics;
    }

    private function applicantFromRows(array $items): array
    {
        $applicant = ['name' => null, 'email' => null, 'phone' => null, 'cnic' => null, 'raw' => []];

        foreach ($this->groupTextItemsIntoRows($items) as $row) {
            $rowText = $this->rowText($row);
            $rowLower = strtolower($rowText);
            $value = $this->valueSideText($row);
            $applicant['raw'][] = $rowText;

            if ($applicant['name'] === null && (str_contains($rowLower, 'applican') || str_contains($rowLower, 'owner name'))) {
                $candidate = trim($value);
                if ($candidate !== '' && ! preg_match('/\b(applican|owner|name)\b/i', $candidate)) {
                    $applicant['name'] = $candidate;
                }
            }
            if ($applicant['email'] === null && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $rowText, $m)) {
                $applicant['email'] = $m[0];
            }
            if ($applicant['phone'] === null && preg_match('/\b(phone|mobile|contact|cell)\b/i', $rowText) && preg_match('/(?:\+?\d[\d\s\-]{8,}\d)/', $rowText, $m)) {
                $applicant['phone'] = trim($m[0]);
            }
            if ($applicant['cnic'] === null && str_contains($rowLower, 'cnic') && $this->isUsefulTableValue($value)) {
                $applicant['cnic'] = trim($value);
            }
        }

        return $applicant;
    }

    private function plotFromRows(array $items): array
    {
        $plot = [
            'plot_no' => null,
            'plot_size' => null,
            'street' => null,
            'scheme' => null,
            'phase' => null,
            'block' => null,
            'sector' => null,
            'plot_category' => null,
            'building_purpose' => null,
            'raw' => [],
        ];

        foreach ($this->groupTextItemsIntoRows($items) as $row) {
            $rowText = $this->rowText($row);
            $rowLower = strtolower($rowText);
            $value = trim($this->valueSideText($row));
            $plot['raw'][] = $rowText;

            if ($plot['plot_no'] === null && str_contains($rowLower, 'plot no') && $this->isUsefulTableValue($value)) {
                $plot['plot_no'] = $value;
            }
            if ($plot['plot_size'] === null) {
                if (preg_match('/\b(\d+(?:\.\d+)?)\s*(marla|kanal|sq\s*ft|sqft|square\s*feet)\b/i', $rowText, $m)) {
                    $plot['plot_size'] = trim($m[0]);
                } elseif (str_contains($rowLower, 'plot area') && $this->isUsefulTableValue($value)) {
                    $plot['plot_size'] = $value;
                }
            }
            if ($plot['street'] === null && (str_contains($rowLower, 'street') || str_contains($rowLower, 'road')) && $this->isUsefulTableValue($value)) {
                $plot['street'] = $value;
            }
            if ($plot['scheme'] === null && str_contains($rowLower, 'scheme') && $this->isUsefulTableValue($value)) {
                $plot['scheme'] = $value;
            }
            if ($plot['phase'] === null && str_contains($rowLower, 'phase') && $this->isUsefulTableValue($value)) {
                $plot['phase'] = $value;
            }
            if ($plot['block'] === null && str_contains($rowLower, 'block') && $this->isUsefulTableValue($value)) {
                $plot['block'] = $value;
            }
            if ($plot['sector'] === null && str_contains($rowLower, 'sector') && $this->isUsefulTableValue($value)) {
                $plot['sector'] = $value;
            }
            if ($plot['plot_category'] === null && str_contains($rowLower, 'plot category') && $this->isUsefulTableValue($value)) {
                $plot['plot_category'] = $value;
            }
            if ($plot['building_purpose'] === null && str_contains($rowLower, 'building purpose') && $this->isUsefulTableValue($value)) {
                $plot['building_purpose'] = $value;
            }
        }

        return $plot;
    }

    private function roomAreasFromTextItems(array $items): array
    {
        $items = array_values(array_filter($items, fn ($item) => trim((string) ($item['text'] ?? '')) !== ''));
        if (empty($items)) {
            return [];
        }

        $floor = $this->dominantFloorCode($items);
        $labels = [];
        $dimensions = [];
        foreach ($items as $item) {
            $text = $this->cleanCadText((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $dimension = $this->dimensionPairFromText($text);
            if ($dimension) {
                $dimensions[] = array_merge($item, ['dimension' => $dimension]);
            }

            $category = $this->roomCategoryFromText($text);
            if ($category) {
                $labels[] = array_merge($item, [
                    'category' => $category,
                    'label_text' => $this->normalizeRoomLabel($text),
                ]);
            }
        }

        $rows = [];
        $counts = [];
        foreach ($labels as $label) {
            $near = $this->nearestDimensionForLabel($label, $dimensions);
            if (! $near) {
                continue;
            }

            $labelFloor = $this->floorCodeFromText((string) ($label['text'] ?? '')) ?? $floor;
            $category = (string) $label['category'];
            $counts[$labelFloor] = $counts[$labelFloor] ?? [];
            $counts[$labelFloor][$category] = ($counts[$labelFloor][$category] ?? 0) + 1;
            $serial = $counts[$labelFloor][$category];
            $dimension = (array) ($near['dimension'] ?? []);
            $width = (float) ($dimension['width_ft'] ?? 0);
            $height = (float) ($dimension['height_ft'] ?? 0);

            $rows[] = [
                'key' => $labelFloor . '_' . $category . $serial,
                'floor' => $labelFloor,
                'category' => $category,
                'label' => (string) ($label['label_text'] ?? $category),
                'width_ft' => round($width, 4),
                'height_ft' => round($height, 4),
                'area_sqft' => round($width * $height, 4),
                'dimension_text' => (string) ($near['text'] ?? ''),
                'label_layer' => (string) ($label['layer'] ?? ''),
                'dimension_layer' => (string) ($near['layer'] ?? ''),
                'x' => $label['x'] ?? null,
                'y' => $label['y'] ?? null,
            ];
        }

        return $rows;
    }

    private function roomCategoryFromText(string $text): ?string
    {
        $low = strtolower($text);
        $low = preg_replace('/[^a-z0-9\s]/', ' ', (string) $low);
        $low = trim(preg_replace('/\s+/', ' ', (string) $low));

        return match (true) {
            preg_match('/\b(t\.?\s*v\.?|tv)\s*(lounge|loung|lounch)\b/i', $text) === 1,
            str_contains($low, 'lounge') || str_contains($low, 'loung') => 'TV_LOUNGE',
            str_contains($low, 'bed room') || str_contains($low, 'bedroom') || preg_match('/\bbed\b/i', $text) === 1 => 'ROOM',
            preg_match('/\broom\b/i', $text) === 1 => 'ROOM',
            str_contains($low, 'drawing') => 'DRAWING',
            str_contains($low, 'kitchen') => 'KITCHEN',
            str_contains($low, 'bath') || str_contains($low, 'toilet') || str_contains($low, 'wash') => 'BATH',
            str_contains($low, 'porch') => 'PORCH',
            str_contains($low, 'store') => 'STORE',
            str_contains($low, 'lobby') => 'LOBBY',
            str_contains($low, 'passage') => 'PASSAGE',
            str_contains($low, 'stair') => 'STAIR',
            default => null,
        };
    }

    private function normalizeRoomLabel(string $text): string
    {
        $value = strtoupper(trim(preg_replace('/\s+/', ' ', $text)));
        return mb_substr($value, 0, 80);
    }

    private function dimensionPairFromText(string $text): ?array
    {
        $normalized = strtolower($this->normalizeDimensionText($text));
        $normalized = str_replace(['×', '*'], 'x', $normalized);
        $normalized = preg_replace('/\s+/', ' ', (string) $normalized);

        $number = '\d+(?:\.\d+)?';
        $patterns = [
            '/(' . $number . ')\s*(?:\'|ft|feet)?\s*(?:-\s*(' . $number . ')\s*(?:"|in|inch|inches)?)?\s*x\s*(' . $number . ')\s*(?:\'|ft|feet)?\s*(?:-\s*(' . $number . ')\s*(?:"|in|inch|inches)?)?/i',
            '/(' . $number . ')\s*(?:ft|feet)\s*(' . $number . ')?\s*(?:in|inch|inches)?\s*x\s*(' . $number . ')\s*(?:ft|feet)\s*(' . $number . ')?\s*(?:in|inch|inches)?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $m)) {
                $w = $this->feetInchesToFeet($m[1] ?? null, $m[2] ?? null);
                $h = $this->feetInchesToFeet($m[3] ?? null, $m[4] ?? null);
                if ($w > 0 && $h > 0) {
                    return [
                        'width_ft' => round($w, 4),
                        'height_ft' => round($h, 4),
                    ];
                }
            }
        }

        return null;
    }

    private function normalizeDimensionText(string $text): string
    {
        $value = str_replace(['’', '`', '′', '“', '”', '″'], ["'", "'", "'", '"', '"', '"'], $text);

        // CAD text often uses architectural fractions such as 9'-1½" x 11'-0".
        $value = preg_replace_callback('/(\d+)?\s*(½|¼|¾)/u', function (array $m): string {
            $base = isset($m[1]) && $m[1] !== '' ? (float) $m[1] : 0.0;
            $fraction = match ($m[2]) {
                '½' => 0.5,
                '¼' => 0.25,
                '¾' => 0.75,
                default => 0.0,
            };

            return rtrim(rtrim((string) ($base + $fraction), '0'), '.');
        }, $value) ?? $value;

        $value = preg_replace_callback('/(\d+)?\s*(1\/2|1\/4|3\/4)/', function (array $m): string {
            $base = isset($m[1]) && $m[1] !== '' ? (float) $m[1] : 0.0;
            $fraction = match ($m[2]) {
                '1/2' => 0.5,
                '1/4' => 0.25,
                '3/4' => 0.75,
                default => 0.0,
            };

            return rtrim(rtrim((string) ($base + $fraction), '0'), '.');
        }, $value) ?? $value;

        return $value;
    }

    private function feetInchesToFeet(mixed $feet, mixed $inches): float
    {
        $ft = is_numeric($feet) ? (float) $feet : 0.0;
        $in = is_numeric($inches) ? (float) $inches : 0.0;
        return $ft + ($in / 12.0);
    }

    private function nearestDimensionForLabel(array $label, array $dimensions): ?array
    {
        $lx = is_numeric($label['x'] ?? null) ? (float) $label['x'] : null;
        $ly = is_numeric($label['y'] ?? null) ? (float) $label['y'] : null;
        if ($lx === null || $ly === null) {
            return $dimensions[0] ?? null;
        }

        $best = null;
        $bestScore = null;
        foreach ($dimensions as $dimension) {
            $dx = is_numeric($dimension['x'] ?? null) ? (float) $dimension['x'] : null;
            $dy = is_numeric($dimension['y'] ?? null) ? (float) $dimension['y'] : null;
            if ($dx === null || $dy === null) {
                continue;
            }

            $score = abs($lx - $dx) + (abs($ly - $dy) * 2.5);
            // Room names and their dimensions are usually stacked close together.
            if (abs($lx - $dx) > 80 || abs($ly - $dy) > 35) {
                continue;
            }
            if ($bestScore === null || $score < $bestScore) {
                $best = $dimension;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function dominantFloorCode(array $items): string
    {
        $text = strtolower(implode(' ', array_map(fn ($item) => (string) ($item['text'] ?? ''), $items)));
        return $this->floorCodeFromText($text) ?? 'GF';
    }

    private function floorCodeFromText(string $text): ?string
    {
        $low = strtolower($text);
        return match (true) {
            str_contains($low, 'basement') || preg_match('/\bbf\b/', $low) === 1 => 'BF',
            str_contains($low, 'first floor') || preg_match('/\bff\b/', $low) === 1 => 'FF',
            str_contains($low, 'second floor') || preg_match('/\bsf\b/', $low) === 1 => 'SF',
            str_contains($low, 'third floor') || preg_match('/\b(th|tf)\b/', $low) === 1 => 'TH',
            str_contains($low, 'ground floor') || preg_match('/\bgf\b/', $low) === 1 => 'GF',
            default => null,
        };
    }

    private function groupTextItemsIntoRows(array $items): array
    {
        $items = array_values(array_filter($items, fn ($item) => is_numeric($item['y'] ?? null)));
        usort($items, function ($a, $b) {
            $yDiff = ((float) ($b['y'] ?? 0)) <=> ((float) ($a['y'] ?? 0));
            if ($yDiff !== 0) {
                return $yDiff;
            }

            return ((float) ($a['x'] ?? 0)) <=> ((float) ($b['x'] ?? 0));
        });

        $rows = [];
        foreach ($items as $item) {
            $placed = false;
            foreach ($rows as &$row) {
                if (abs(((float) $row['y']) - ((float) $item['y'])) <= 2.0) {
                    $row['items'][] = $item;
                    $row['y'] = (((float) $row['y']) + ((float) $item['y'])) / 2;
                    $placed = true;
                    break;
                }
            }
            unset($row);
            if (! $placed) {
                $rows[] = ['y' => (float) $item['y'], 'items' => [$item]];
            }
        }

        foreach ($rows as &$row) {
            usort($row['items'], fn ($a, $b) => ((float) ($a['x'] ?? 0)) <=> ((float) ($b['x'] ?? 0)));
        }
        unset($row);

        return $rows;
    }

    private function rowText(array $row): string
    {
        return trim(implode(' ', array_map(fn ($item) => (string) ($item['text'] ?? ''), $row['items'] ?? [])));
    }

    private function valueSideText(array $row): string
    {
        $items = $row['items'] ?? [];
        if (count($items) < 2) {
            return $this->rowText($row);
        }

        // Architect table rows are label on the left and value in the right column.
        return trim(implode(' ', array_map(fn ($item) => (string) ($item['text'] ?? ''), array_slice($items, 1))));
    }

    private function firstNumber(string $text): ?float
    {
        if (preg_match('/-?\d+(?:\.\d+)?/', $text, $m)) {
            return round((float) $m[0], 4);
        }

        return null;
    }

    private function firstPercentOrNumber(string $text): ?float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*%?/', $text, $m)) {
            $value = (float) $m[1];
            if ($value >= 0 && $value <= 100) {
                return round($value, 4);
            }
        }

        return null;
    }

    private function isUsefulTableValue(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return ! in_array(strtolower($value), ['no', 'number', 'plot no', 'plot number', '-', 'n/a'], true);
    }
}
