<?php

namespace App\Services;

use App\Models\BpApplication;
use App\Models\CadSubmission;
use App\Models\MapDrawing;
use App\Services\MapApproval\GeometryCalculationService;
use App\Services\MapApproval\MapApprovalPipelineService;
use App\Services\MapApproval\MapApprovalReportService;
use App\Services\MapApproval\RuleValidationService;
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
    ) {
    }

    public function prepareCadSubmission(BpApplication $application, UploadedFile $file, string $storedPath): CadSubmission
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());

        return CadSubmission::create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_dwg_path' => $ext === 'dwg' ? $storedPath : null,
            'stored_dxf_path' => $ext === 'dxf' ? $storedPath : null,
            'ruleset_key' => '5_marla_residential',
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
                        $geometry = $this->geometryCalculationService->calculate($drawing);
                        $semanticRules = $this->mapRuleValidationService->validate($drawing->fresh('entities'), $geometry);
                        $report = $this->mapReportService->generate($drawing->fresh(['entities', 'geometryResults', 'ruleResults']));
                        $mapDrawingId = $drawing->id;
                        $rules = ! empty($semanticRules) ? $semanticRules : $rules;
                        $status = (string) ($report['status'] ?? $status);
                        $recommendation = $this->recommendationFromReportStatus($status);
                        $run['map_pipeline'] = $mapped;
                        $run['map_report'] = $report;
                    } catch (\Throwable $semanticError) {
                        $analysis['semantic_pipeline_warning'] = $semanticError->getMessage();
                    }
                }

                return [
                    'status' => $status,
                    'recommendation' => $recommendation,
                    'confidence_score' => $this->confidenceFromRules($rules),
                    'analysis_json' => $run,
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
                $geometry = $this->geometryCalculationService->calculate($drawing);
                $this->mapRuleValidationService->validate($drawing->fresh('entities'), $geometry);
                $report = $this->mapReportService->generate($drawing->fresh(['entities', 'geometryResults', 'ruleResults']));

                $rules = (array) ($report['rules'] ?? []);
                $status = (string) ($report['status'] ?? 'needs_expert_review');

                return [
                    'status' => $status,
                    'recommendation' => $this->recommendationFromReportStatus($status),
                    'confidence_score' => $this->confidenceFromRules($rules),
                    'analysis_json' => [
                        'map_pipeline' => $mapped,
                        'map_report' => $report,
                    ],
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
        $hasCoreMetrics = collect(['plot_area', 'ground_floor_covered', 'total_floor_covered', 'number_of_floors', 'provided_height_ft'])
            ->every(fn ($key) => ($existingMetrics[$key] ?? null) !== null);
        if (! empty($existing) && $hasCoreMetrics) {
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

        foreach ($drawing->entities as $entity) {
            $text = $this->cleanCadText((string) data_get($entity->geometry_json, 'text_content', ''));
            if ($text === '') {
                continue;
            }
            $layer = (string) ($entity->layer_name ?? '');
            $layerNorm = strtolower(trim(preg_replace('/^\d+\s*[\.\-_\):\s]+\s*/', '', $layer)));
            $textByLayer[$layerNorm] = $textByLayer[$layerNorm] ?? [];
            $textByLayer[$layerNorm][] = $text;
            $point = data_get($entity->geometry_json, 'points.0', []);
            $item = [
                'text' => $text,
                'x' => is_numeric($point[0] ?? null) ? (float) $point[0] : null,
                'y' => is_numeric($point[1] ?? null) ? (float) $point[1] : null,
                'layer' => $layer,
            ];
            $textItemsByLayer[$layerNorm] = $textItemsByLayer[$layerNorm] ?? [];
            $textItemsByLayer[$layerNorm][] = $item;

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

        if (empty($rows)) {
            return;
        }

        $metadata['cad_text_references'] = array_slice($rows, 0, 800);
        $metadata['cad_text_sections'] = array_values(array_slice($sections, 0, 100));
        $metadata['cad_text_measurement_metrics'] = $metrics;
        $metadata['cad_text_applicant'] = $applicant;
        $metadata['cad_text_plot'] = $plot;
        $metadata['cad_text_references_updated_at'] = now()->toISOString();
        $drawing->metadata_json = $metadata;
        $drawing->save();
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
