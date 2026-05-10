<?php

namespace App\Services;

use App\Models\BpApplication;
use App\Models\BpChatMessage;

class BuildingPlanChatService
{
    public function reply(BpApplication $application, string $question): string
    {
        $report = $application->aiReport;
        if (! $report) {
            return 'AI report is not generated yet. Please run analysis first.';
        }

        $analysis = (array) ($report->analysis_json ?? []);
        $rules = (array) ($report->rule_results_json ?? []);
        $warnings = (array) ($report->warnings_json ?? []);
        $expertItems = (array) ($report->expert_review_items_json ?? []);

        $q = strtolower(trim($question));

        if (str_contains($q, 'status') || str_contains($q, 'recommend')) {
            return 'Current AI recommendation is ' . $report->ai_recommendation . ' with confidence ' . ((float) $report->ai_confidence_score) . '%. Application status is ' . $application->status . '.';
        }

        if (str_contains($q, 'fail') || str_contains($q, 'failed')) {
            $failed = array_values(array_filter($rules, fn ($row) => (($row['status'] ?? null) === 'fail') || (($row['pass'] ?? null) === false)));
            if (empty($failed)) {
                return 'No failed rules were found in the current AI report.';
            }
            $codes = array_map(fn ($row) => (string) ($row['id'] ?? $row['rule_code'] ?? 'unknown_rule'), array_slice($failed, 0, 10));
            return 'Failed rules: ' . implode(', ', $codes) . '.';
        }

        if (str_contains($q, 'warning') || str_contains($q, 'expert')) {
            if (empty($warnings) && empty($expertItems)) {
                return 'No warnings or expert review items are currently listed.';
            }
            $all = array_slice(array_values(array_unique(array_merge($warnings, $expertItems))), 0, 8);
            return 'Review items: ' . implode(' | ', array_map(fn ($x) => (string) $x, $all));
        }

        if (str_contains($q, 'plot') || str_contains($q, 'boundary')) {
            $plotHandle = data_get($analysis, 'analysis_result.auto_handles.plot_handle');
            return $plotHandle
                ? ('Detected plot boundary handle: ' . $plotHandle . '.')
                : 'Plot boundary is not confidently detected and may require expert confirmation.';
        }

        if (str_contains($q, 'layer')) {
            $layers = (array) ($report->detected_layers_json ?? []);
            if (empty($layers)) {
                return 'No parsed layer list is available in the current AI output.';
            }
            $preview = substr(json_encode($layers), 0, 500);
            return 'Layer evidence from current AI analysis: ' . $preview;
        }

        return 'Based on current map analysis, rules JSON, layer schema, and report data, please confirm required geometry (plot boundary, building footprint, setbacks) and resolve any failed or expert-review rules before submission.';
    }

    public function saveMessage(BpApplication $application, string $role, string $message, array $context = []): BpChatMessage
    {
        return BpChatMessage::create([
            'bp_application_id' => $application->id,
            'role' => $role,
            'message' => $message,
            'context_json' => $context,
            'sent_at' => now(),
        ]);
    }
}
