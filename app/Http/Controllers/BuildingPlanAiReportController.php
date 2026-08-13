<?php

namespace App\Http\Controllers;

use App\Models\BpApplication;
use App\Models\MapDrawing;
use App\Services\AiMapAnalysisService;
use App\Services\AiReportGenerationService;
use App\Services\MapApproval\GeometryCalculationService;
use App\Services\MapApproval\RuleValidationService;
use App\Services\MapApproval\StructuralExtractionService;

class BuildingPlanAiReportController extends Controller
{
    public function __construct(
        private readonly AiMapAnalysisService $analysisService,
        private readonly GeometryCalculationService $geometryCalculationService,
        private readonly RuleValidationService $mapRuleValidationService,
        private readonly StructuralExtractionService $structuralExtractionService,
    ) {
    }

    public function show(BpApplication $application)
    {
        return view('admin.building-plan.report', $this->reportViewData($application));
    }

    public function download(BpApplication $application)
    {
        $data = $this->reportViewData($application);
        $filename = 'building-plan-ai-report-' . $application->application_number . '.pdf';

        if (app()->bound('dompdf.wrapper')) {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('admin.building-plan.report-pdf', $data);

            return $pdf->download($filename);
        }

        // Fallback when a PDF engine is not installed: open a print-optimized page
        // so the browser can save the report as PDF without exposing raw HTML.
        return response()
            ->view('admin.building-plan.report-pdf', array_merge($data, ['autoPrint' => true]))
            ->header('Content-Disposition', 'inline; filename="' . $filename . '.html"');
    }

    public function verify(string $token)
    {
        $application = BpApplication::query()
            ->where('qr_token', $token)
            ->with('aiReport')
            ->firstOrFail();

        return view('admin.building-plan.verify', [
            'application' => $application,
            'report' => $application->aiReport,
        ]);
    }

    private function textualComparison(BpApplication $application): array
    {
        $report = $application->aiReport;
        $metrics = (array) data_get($application->layer_table_json, 'measurement_metrics', []);
        $plot = (array) ($application->plot_data_json ?? []);
        $patternProfile = [];
        $roomAreas = [];

        if ($application->map_drawing_id) {
            $drawing = MapDrawing::find($application->map_drawing_id);
            if ($drawing) {
                $this->analysisService->hydrateCadTextReferencesFromLayers($drawing);
                $this->rerunRulesFromHydratedDrawing($application, $drawing);
                $drawing->refresh();
                $meta = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
                $metrics = array_replace($metrics, (array) data_get($meta, 'cad_text_measurement_metrics', []));
                $plot = array_replace($plot, (array) data_get($meta, 'cad_text_plot', []));
                $patternProfile = is_array(data_get($meta, 'dxf_pattern_profile'))
                    ? (array) data_get($meta, 'dxf_pattern_profile')
                    : [];
                $roomAreas = is_array(data_get($meta, 'cad_text_room_areas'))
                    ? (array) data_get($meta, 'cad_text_room_areas')
                    : [];
            }
        }

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

        $ruleRows = collect($report?->rule_results_json ?? []);
        $aiActual = fn (string $ruleCode) => $this->ruleActual($ruleRows->first(fn ($row) => ($row['rule_code'] ?? $row['id'] ?? null) === $ruleCode));
        $geometryByKey = [];
        if ($application->map_drawing_id) {
            $drawing = MapDrawing::find($application->map_drawing_id);
            if ($drawing) {
                $geometryByKey = $drawing->geometryResults()
                    ->orderByDesc('id')
                    ->get()
                    ->groupBy('key')
                    ->map(fn ($rows) => $this->number(optional($rows->first())->value))
                    ->toArray();
            }
        }
        $aiGroundFloorArea = $this->number($geometryByKey['ground_floor_area_sqft'] ?? null);
        $aiTotalCoveredArea = $this->number($geometryByKey['total_covered_area_sqft'] ?? null);
        $aiStoreyCount = $this->number($geometryByKey['storey_count'] ?? null);
        $aiFrontSetback = $this->number($geometryByKey['front_setback_ft'] ?? null);
        $aiRearSetback = $this->number($geometryByKey['rear_setback_ft'] ?? null);
        $aiLeftSetback = $this->number($geometryByKey['left_setback_ft'] ?? null);
        $aiRightSetback = $this->number($geometryByKey['right_setback_ft'] ?? null);
        $aiSideSetback = ! empty(array_filter([$aiLeftSetback, $aiRightSetback], fn ($v) => $v !== null))
            ? max(array_filter([$aiLeftSetback, $aiRightSetback], fn ($v) => $v !== null))
            : null;
        $aiHeight = $this->number($aiActual('MAX_HEIGHT')) ?? $this->number($metrics['provided_height_ft'] ?? null);

        $sideSetbacks = array_values(array_filter([
            $this->number($metrics['left_setback_ft'] ?? null),
            $this->number($metrics['right_setback_ft'] ?? null),
        ], fn ($v) => $v !== null));

        $rows = [
            $this->comparisonRow('Plot Area', $metrics['plot_area'] ?? null, null, 'Base plot area read from layer 39 Measurements', null, $maxGroundCoveredArea === null ? null : (($thresholds['ground_coverage_percent'] ?? 75) . '% coverage area = ' . $maxGroundCoveredArea . ' sqft')),
            $this->comparisonRow('Ground Floor Covered', $metrics['ground_floor_covered'] ?? null, $aiGroundFloorArea, 'Ground coverage area check from textual table', '<=', $maxGroundCoveredArea),
            $this->comparisonRow('Total Floor Covered', $metrics['total_floor_covered'] ?? null, $aiTotalCoveredArea, 'FAR area check from textual table', '<=', $maxFarCoveredArea),
            $this->comparisonRow('Ground Coverage %', $coverageFormula, $aiActual('GROUND_COVERAGE'), 'Text formula: ground floor covered / plot area x 100', '<=', $thresholds['ground_coverage_percent']),
            $this->comparisonRow('FAR', $farFormula, $aiActual('FAR_LIMIT'), 'Text formula: all floor covered / plot area', '<=', $thresholds['far_limit']),
            $this->comparisonRow('Number of Floors', $metrics['number_of_floors'] ?? null, $aiStoreyCount ?? $aiActual('MAX_STOREYS'), 'From layer 39 Measurements', '<=', $thresholds['max_storeys']),
            $this->comparisonRow('Provided Height (ft)', $metrics['provided_height_ft'] ?? null, $aiHeight, 'From layer 39 Measurements', '<=', $thresholds['max_height_ft']),
            $this->comparisonRow('Front Setback (ft)', $metrics['front_setback_ft'] ?? null, $aiFrontSetback ?? $aiActual('SETBACK_FRONT'), 'From layer 39 Measurements', '>=', $thresholds['front_setback_ft']),
            $this->comparisonRow('Rear Setback (ft)', $metrics['rear_setback_ft'] ?? null, $aiRearSetback ?? $aiActual('SETBACK_REAR'), 'From layer 39 Measurements', '>=', $thresholds['rear_setback_ft']),
            $this->comparisonRow('Side Setback (ft)', empty($sideSetbacks) ? null : max($sideSetbacks), $aiSideSetback ?? $aiActual('SETBACK_SIDE'), 'Either left or right side mandatory space from text', (string) ($thresholds['side_setback_operator'] ?? '=='), $thresholds['side_setback_ft']),
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
        if (! empty($patternProfile)) {
            $findings[] = 'DXF pattern recognized as ' . (string) data_get($patternProfile, 'pattern_family', 'generic_dxf')
                . ' with strength ' . number_format((float) data_get($patternProfile, 'pattern_strength', 0), 2) . '.';
        }

        return [
            'rows' => $rows,
            'recommendation' => $recommendation,
            'findings' => $findings,
            'roomAreas' => $roomAreas,
            'roomAreaTotals' => $this->roomAreaTotals($roomAreas),
            'patternProfile' => $patternProfile,
        ];
    }

    private function reportViewData(BpApplication $application): array
    {
        $application->load('aiReport', 'chatMessages');
        $comparison = $this->textualComparison($application);
        $analysisJson = is_array($application->aiReport?->analysis_json) ? $application->aiReport->analysis_json : [];
        $structural = (array) data_get($analysisJson, 'structural_extraction', []);
        $needsStructuralRefresh = empty($structural)
            || empty(data_get($structural, 'graph.edges.0.distance_unit'))
            || empty(data_get($structural, 'graph.edges.0.raw_distance_unit'));
        if ($needsStructuralRefresh && $application->map_drawing_id) {
            $drawing = MapDrawing::find($application->map_drawing_id);
            if ($drawing) {
                $meta = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
                $cached = (array) data_get($meta, 'structural_extraction', []);
                $cachedUsable = ! empty($cached)
                    && ! empty(data_get($cached, 'graph.edges.0.distance_unit'))
                    && ! empty(data_get($cached, 'graph.edges.0.raw_distance_unit'));
                if ($cachedUsable) {
                    $structural = $cached;
                } else {
                    $structural = $this->structuralExtractionService->extract($drawing);
                    $meta['structural_extraction'] = $structural;
                    $drawing->metadata_json = $meta;
                    $drawing->save();
                }

                if (! empty($structural) && $application->aiReport) {
                    $analysisJson['structural_extraction'] = $structural;
                    $application->aiReport->analysis_json = $analysisJson;
                    $application->aiReport->save();
                }
            }
        }

        $structuralSummary = (array) data_get($structural, 'summary', []);
        $structuralEntities = (array) data_get($structural, 'entities', []);
        $structuralConfidence = (float) data_get($structural, 'confidence', 0);
        $structuralGraph = (array) data_get($structural, 'graph', []);
        $graphNodes = is_array(data_get($structuralGraph, 'nodes')) ? data_get($structuralGraph, 'nodes') : [];
        $graphEdges = is_array(data_get($structuralGraph, 'edges')) ? data_get($structuralGraph, 'edges') : [];
        $patternProfile = (array) data_get($analysisJson, 'dxf_pattern_profile', []);
        $graphRelationCounts = collect($graphEdges)
            ->groupBy(fn ($edge) => (string) data_get($edge, 'relation', 'unknown'))
            ->map(fn ($items) => count($items))
            ->toArray();
        $reportRuleRows = collect($application->aiReport?->rule_results_json ?? [])
            ->map(function ($row) {
                $code = (string) ($row['id'] ?? $row['rule_code'] ?? '');
                $row['clause_reference'] = $this->clauseReferenceForRuleCode($code);

                return $row;
            })
            ->values()
            ->all();

        return [
            'application' => $application,
            'report' => $application->aiReport,
            'reportRuleRows' => $reportRuleRows,
            'comparisonRows' => $comparison['rows'],
            'textualRecommendation' => $comparison['recommendation'],
            'textualFindings' => $comparison['findings'],
            'roomAreas' => $comparison['roomAreas'],
            'roomAreaTotals' => $comparison['roomAreaTotals'],
            'dxfPatternProfile' => $comparison['patternProfile'] ?: $patternProfile,
            'structuralSummary' => $structuralSummary,
            'structuralEntities' => $structuralEntities,
            'structuralConfidence' => $structuralConfidence,
            'structuralGraph' => $structuralGraph,
            'structuralGraphNodes' => $graphNodes,
            'structuralGraphEdges' => $graphEdges,
            'structuralGraphRelationCounts' => $graphRelationCounts,
            'disclaimer' => AiReportGenerationService::DISCLAIMER,
        ];
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

    private function rerunRulesFromHydratedDrawing(BpApplication $application, MapDrawing $drawing): void
    {
        $geometry = $this->geometryCalculationService->calculate($drawing->fresh('entities'));
        $this->mapRuleValidationService->validate($drawing->fresh('entities'), $geometry);
        $drawing->refresh();

        if (! $application->aiReport) {
            return;
        }

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

        $application->aiReport->rule_results_json = $ruleRows;
        $application->aiReport->save();
        $application->unsetRelation('aiReport');
        $application->load('aiReport');
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
