<?php

namespace App\Services;

use App\Models\BpApplication;

class AiReportGenerationService
{
    public const DISCLAIMER = 'This report is generated through an AI-assisted map analysis system based on available digital map data and configured planning rules. It is intended to support faster and more reliable scrutiny. The final decision to approve, reject, or require correction of the building plan is reserved by the competent authority. This AI-generated report does not constitute final approval.';

    public function generate(BpApplication $application, array $analysis): array
    {
        $ai = (array) ($analysis['analysis_json'] ?? []);
        $mapReport = (array) data_get($ai, 'map_report', []);
        $cadAnalysis = (array) data_get($ai, 'analysis_result', []);
        $cadConfidence = (array) data_get($ai, 'cad_confidence_assessment', []);
        $dxfPatternProfile = (array) data_get($ai, 'dxf_pattern_profile', []);
        $structural = (array) data_get($ai, 'structural_extraction', []);
        $imagerySignal = (array) data_get($ai, 'imagery_signal', []);

        $rules = array_values(array_filter(array_merge(
            (array) data_get($ai, 'rules', []),
            (array) data_get($mapReport, 'rules', [])
        ), fn ($row) => is_array($row)));

        $passedRules = array_values(array_filter($rules, function ($row) {
            return ($row['status'] ?? null) === 'pass' || ($row['pass'] ?? null) === true;
        }));

        $failedRules = array_values(array_filter($rules, function ($row) {
            return ($row['status'] ?? null) === 'fail' || ($row['pass'] ?? null) === false;
        }));

        $detectedLayers = (array) data_get($ai, 'analysis_result.detected_layers', data_get($mapReport, 'mapping', []));
        $detectedEntities = (array) data_get($ai, 'entity_features', data_get($mapReport, 'mapping', []));

        $warnings = array_values(array_unique(array_merge(
            (array) ($analysis['warnings'] ?? []),
            (array) data_get($cadAnalysis, 'warnings', []),
            (array) data_get($mapReport, 'expert_review_reasons', [])
        )));

        $expertItems = array_values(array_unique(array_merge(
            (array) ($analysis['expert_review_items'] ?? []),
            (array) data_get($mapReport, 'missing_required_entities', [])
        )));

        $plotDetected = $this->booleanLabel(
            (bool) data_get($cadAnalysis, 'auto_handles.plot_handle') ||
            str_contains(strtolower(json_encode($detectedLayers)), 'plot_boundary')
        );

        $footprintDetected = $this->booleanLabel(
            str_contains(strtolower(json_encode($detectedLayers)), 'ground_floor') ||
            str_contains(strtolower(json_encode($detectedLayers)), 'external_walls')
        );

        $chatSummary = $application->chatMessages()
            ->latest('id')
            ->limit(5)
            ->get()
            ->reverse()
            ->map(fn ($m) => strtoupper($m->role) . ': ' . $m->message)
            ->values()
            ->all();

        $reportData = [
            'application_number' => $application->application_number,
            'qr_code' => $application->qr_code_url,
            'verification_url' => $application->verification_url,
            'uploaded_file' => [
                'name' => $application->uploaded_file_name,
                'type' => $application->uploaded_file_type,
                'size' => $application->uploaded_file_size,
            ],
            'ai_analysis_status' => $analysis['status'] ?? 'needs_expert_review',
            'detected_cad_layers_entities' => [
                'layers' => $detectedLayers,
                'entities' => $detectedEntities,
            ],
            'plot_boundary_detection' => $plotDetected,
            'building_footprint_detection' => $footprintDetected,
            'rule_wise_compliance_results' => $rules,
            'passed_rules' => $passedRules,
            'failed_rules' => $failedRules,
            'warnings' => $warnings,
            'items_requiring_expert_review' => $expertItems,
            'ai_confidence_score' => $analysis['confidence_score'] ?? 0,
            'cad_confidence_assessment' => $cadConfidence,
            'dxf_pattern_profile' => $dxfPatternProfile,
            'ai_recommendation' => $analysis['recommendation'] ?? 'Needs Expert Review',
            'chatbot_conversation_summary' => $chatSummary,
            'imagery_signal' => $imagerySignal,
            'structural_findings' => [
                'summary' => (array) data_get($structural, 'summary', []),
                'confidence' => (float) data_get($structural, 'confidence', 0),
                'entities' => (array) data_get($structural, 'entities', []),
                'graph' => (array) data_get($structural, 'graph', []),
                'notes' => (array) data_get($structural, 'notes', []),
            ],
            'disclaimer' => self::DISCLAIMER,
        ];

        return [
            'report_data' => $reportData,
            'report_markdown' => $this->markdownReport($reportData),
            'report_html' => $this->htmlReport($reportData),
        ];
    }

    private function booleanLabel(bool $value): string
    {
        return $value ? 'Detected' : 'Not Detected';
    }

    private function markdownReport(array $report): string
    {
        $lines = [];
        $lines[] = '# Building Plan AI Preliminary Report';
        $lines[] = "Application Number: **{$report['application_number']}**";
        $lines[] = "Verification URL: {$report['verification_url']}";
        $lines[] = "AI Recommendation: **{$report['ai_recommendation']}**";
        $lines[] = "AI Confidence Score: **{$report['ai_confidence_score']}%**";
        $lines[] = "CAD Confidence Score: **" . number_format((float) data_get($report, 'cad_confidence_assessment.confidence_score', 0), 2) . "%**";
        $lines[] = "CAD Confidence Level: **" . strtoupper((string) data_get($report, 'cad_confidence_assessment.confidence_level', 'unknown')) . "**";
        $lines[] = "DXF Pattern Family: **" . (string) data_get($report, 'dxf_pattern_profile.pattern_family', 'generic_dxf') . "**";
        $lines[] = "DXF Pattern Strength: **" . number_format((float) data_get($report, 'dxf_pattern_profile.pattern_strength', 0), 2) . "**";
        $lines[] = "CAD Confidence Source: " . (string) data_get($report, 'cad_confidence_assessment.dimension_source', 'unknown');
        $lines[] = "Missing Layers: " . implode(', ', (array) data_get($report, 'cad_confidence_assessment.missing_layers', []));
        $lines[] = "Plot Boundary: {$report['plot_boundary_detection']}";
        $lines[] = "Building Footprint: {$report['building_footprint_detection']}";
        $lines[] = '';
        $lines[] = '## Compliance Summary';
        $lines[] = '- Passed Rules: ' . count($report['passed_rules']);
        $lines[] = '- Failed Rules: ' . count($report['failed_rules']);
        $lines[] = '- Warnings: ' . count($report['warnings']);
        $lines[] = '- Expert Review Items: ' . count($report['items_requiring_expert_review']);
        $lines[] = '';
        $lines[] = '## Disclaimer';
        $lines[] = $report['disclaimer'];

        return implode("\n", $lines);
    }

    private function htmlReport(array $report): string
    {
        $ruleRows = '';
        foreach ((array) $report['rule_wise_compliance_results'] as $row) {
            $code = e((string) ($row['id'] ?? $row['rule_code'] ?? 'N/A'));
            $status = e((string) ($row['status'] ?? (($row['pass'] ?? null) === true ? 'pass' : (($row['pass'] ?? null) === false ? 'fail' : 'needs_review'))));
            $required = e((string) ($row['required'] ?? $row['required_value'] ?? ''));
            $actual = e((string) ($row['measured'] ?? $row['actual'] ?? $row['actual_value'] ?? ''));
            $ruleRows .= "<tr><td>{$code}</td><td>{$status}</td><td>{$required}</td><td>{$actual}</td></tr>";
        }

        $warningItems = '';
        foreach ((array) $report['warnings'] as $w) {
            $warningItems .= '<li>' . e((string) $w) . '</li>';
        }

        $expertItems = '';
        foreach ((array) $report['items_requiring_expert_review'] as $item) {
            $expertItems .= '<li>' . e((string) $item) . '</li>';
        }

        $cadWarnings = '';
        foreach ((array) data_get($report, 'cad_confidence_assessment.warnings', []) as $item) {
            $cadWarnings .= '<li>' . e((string) $item) . '</li>';
        }

        return "
<!doctype html>
<html><head><meta charset='utf-8'><title>AI Report {$report['application_number']}</title>
<style>body{font-family:Arial,sans-serif;font-size:14px;color:#222}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px}th{background:#f6f6f6}</style>
</head><body>
<h2>Building Plan AI Preliminary Report</h2>
<p><b>Application Number:</b> " . e((string) $report['application_number']) . "</p>
<p><b>AI Analysis Status:</b> " . e((string) $report['ai_analysis_status']) . "</p>
<p><b>AI Recommendation:</b> " . e((string) $report['ai_recommendation']) . "</p>
<p><b>AI Confidence Score:</b> " . e((string) $report['ai_confidence_score']) . "%</p>
<p><b>CAD Confidence Score:</b> " . e(number_format((float) data_get($report, 'cad_confidence_assessment.confidence_score', 0), 2)) . "%</p>
<p><b>CAD Confidence Level:</b> " . e(strtoupper((string) data_get($report, 'cad_confidence_assessment.confidence_level', 'unknown'))) . "</p>
<p><b>DXF Pattern Family:</b> " . e((string) data_get($report, 'dxf_pattern_profile.pattern_family', 'generic_dxf')) . "</p>
<p><b>DXF Pattern Strength:</b> " . e(number_format((float) data_get($report, 'dxf_pattern_profile.pattern_strength', 0), 2)) . "</p>
<p><b>CAD Confidence Source:</b> " . e((string) data_get($report, 'cad_confidence_assessment.dimension_source', 'unknown')) . "</p>
<p><b>Fallback Method:</b> " . e((string) data_get($report, 'cad_confidence_assessment.fallback_method_used', 'unknown')) . "</p>
<p><b>Missing Layers:</b> " . e(implode(', ', (array) data_get($report, 'cad_confidence_assessment.missing_layers', [])) ?: '-') . "</p>
<p><b>Plot Boundary Detection:</b> " . e((string) $report['plot_boundary_detection']) . "</p>
<p><b>Building Footprint Detection:</b> " . e((string) $report['building_footprint_detection']) . "</p>
<h3>CAD Confidence Warnings</h3><ul>{$cadWarnings}</ul>
<h3>Rule-wise Compliance</h3>
<table><thead><tr><th>Rule</th><th>Status</th><th>Required</th><th>Actual</th></tr></thead><tbody>{$ruleRows}</tbody></table>
<h3>Warnings</h3><ul>{$warningItems}</ul>
<h3>Items Requiring Expert Review</h3><ul>{$expertItems}</ul>
<div style='margin-top:16px;padding:12px;border:1px solid #ddd;background:#fafafa'><b>Disclaimer:</b> " . e((string) $report['disclaimer']) . "</div>
</body></html>";
    }
}
