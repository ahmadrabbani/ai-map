<?php

namespace App\Http\Controllers;

use App\Models\BpApplication;
use App\Models\MapDrawing;
use App\Services\AiMapAnalysisService;
use App\Services\AiReportGenerationService;

class BuildingPlanAiReportController extends Controller
{
    public function __construct(
        private readonly AiMapAnalysisService $analysisService,
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

        if ($application->map_drawing_id) {
            $drawing = MapDrawing::find($application->map_drawing_id);
            if ($drawing) {
                $this->analysisService->hydrateCadTextReferencesFromLayers($drawing);
                $drawing->refresh();
                $meta = is_array($drawing->metadata_json) ? $drawing->metadata_json : [];
                $metrics = array_replace($metrics, (array) data_get($meta, 'cad_text_measurement_metrics', []));
                $plot = array_replace($plot, (array) data_get($meta, 'cad_text_plot', []));
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

        $ruleRows = collect($report?->rule_results_json ?? []);
        $aiActual = fn (string $ruleCode) => $this->ruleActual($ruleRows->first(fn ($row) => ($row['rule_code'] ?? $row['id'] ?? null) === $ruleCode));

        $sideSetbacks = array_values(array_filter([
            $this->number($metrics['left_setback_ft'] ?? null),
            $this->number($metrics['right_setback_ft'] ?? null),
        ], fn ($v) => $v !== null));

        $rows = [
            $this->comparisonRow('Plot Area', $metrics['plot_area'] ?? null, null, 'Reference value from layer 39 Measurements', null, null),
            $this->comparisonRow('Ground Floor Covered', $metrics['ground_floor_covered'] ?? null, null, 'Used for coverage formula', null, null),
            $this->comparisonRow('Total Floor Covered', $metrics['total_floor_covered'] ?? null, null, 'Used for FAR formula', null, null),
            $this->comparisonRow('Ground Coverage %', $coverageFormula, $aiActual('GROUND_COVERAGE'), 'Text formula: ground floor covered / plot area x 100', '<=', 75),
            $this->comparisonRow('FAR', $farFormula, $aiActual('FAR_LIMIT'), 'Text formula: all floor covered / plot area', '<=', 2.3),
            $this->comparisonRow('Number of Floors', $metrics['number_of_floors'] ?? null, $aiActual('MAX_STOREYS'), 'From layer 39 Measurements', '<=', 3),
            $this->comparisonRow('Provided Height (ft)', $metrics['provided_height_ft'] ?? null, $aiActual('MAX_HEIGHT'), 'From layer 39 Measurements', '<=', 38),
            $this->comparisonRow('Front Setback (ft)', $metrics['front_setback_ft'] ?? null, $aiActual('SETBACK_FRONT'), 'From layer 39 Measurements', '<=', 5),
            $this->comparisonRow('Rear Setback (ft)', $metrics['rear_setback_ft'] ?? null, $aiActual('SETBACK_REAR'), 'From layer 39 Measurements', '<=', 5.5),
            $this->comparisonRow('Side Setback (ft)', empty($sideSetbacks) ? null : min($sideSetbacks), $aiActual('SETBACK_SIDE'), 'Minimum of left/right mandatory spaces from text', '==', 0),
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
            $findings[] = 'Ground coverage from text is ' . $coverageFormula . '% against allowed 75%.';
        }
        if ($farFormula !== null) {
            $findings[] = 'FAR from text is ' . $farFormula . ' against allowed 2.3.';
        }
        if (! empty($failed)) {
            $findings[] = 'One or more textual checks failed and should be corrected or reviewed.';
        } elseif ($recommendation !== 'Needs Expert Review') {
            $findings[] = 'Textual table checks available in the uploaded drawing are within configured limits.';
        }

        return [
            'rows' => $rows,
            'recommendation' => $recommendation,
            'findings' => $findings,
        ];
    }

    private function reportViewData(BpApplication $application): array
    {
        $application->load('aiReport', 'chatMessages');
        $comparison = $this->textualComparison($application);

        return [
            'application' => $application,
            'report' => $application->aiReport,
            'comparisonRows' => $comparison['rows'],
            'textualRecommendation' => $comparison['recommendation'],
            'textualFindings' => $comparison['findings'],
            'disclaimer' => AiReportGenerationService::DISCLAIMER,
        ];
    }

    private function comparisonRow(string $label, mixed $textual, mixed $ai, string $basis, ?string $operator, mixed $required): array
    {
        $textualNumber = $this->number($textual);
        $status = 'needs_review';
        if ($operator !== null && $required !== null && $textualNumber !== null) {
            $requiredNumber = (float) $required;
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
}
