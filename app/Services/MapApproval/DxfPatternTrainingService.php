<?php

namespace App\Services\MapApproval;

use App\Models\DxfPatternTrainingExample;
use App\Models\CadExpertLabel;
use App\Models\CadSubmission;
use App\Models\MapDrawing;
use App\Models\PublicBuildingPlanApplication;

class DxfPatternTrainingService
{
    public function capture(PublicBuildingPlanApplication $application): void
    {
        $decision = strtolower(trim((string) ($application->ad_epermit_decision ?? '')));
        if (! in_array($decision, ['approve', 'reject', 'observation'], true)) {
            return;
        }

        $legacy = $application->legacy_bp_application_id
            ? $application->legacyBpApplication()->with(['aiReport', 'cadSubmission', 'mapDrawing'])->first()
            : null;

        $aiReport = $legacy?->aiReport;
        $analysisJson = is_array($aiReport?->analysis_json) ? $aiReport->analysis_json : [];
        $reportData = (array) data_get($analysisJson, 'report_data', []);
        $patternProfile = (array) data_get($analysisJson, 'dxf_pattern_profile', data_get($reportData, 'dxf_pattern_profile', []));
        if (empty($patternProfile)) {
            return;
        }

        $cadSubmissionId = (int) ($legacy?->cad_submission_id ?? 0);
        $cadConfidence = (array) data_get($analysisJson, 'cad_confidence_assessment', data_get($reportData, 'cad_confidence_assessment', []));
        $ruleResults = is_array($aiReport?->rule_results_json) ? $aiReport->rule_results_json : [];
        $statusLog = $application->statusLogs()
            ->whereNotNull('remarks')
            ->whereIn('new_status', ['under_review', 'observation_marked', 'rejected_by_ad_epermit', 'approved_by_ad_epermit', 'pushed_to_dfps', 'dfps_push_failed'])
            ->latest('id')
            ->first();

        $outcome = match ($decision) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'observation' => 'observation',
            default => 'under_review',
        };

        DxfPatternTrainingExample::query()->updateOrCreate(
            $cadSubmissionId > 0
                ? ['cad_submission_id' => $cadSubmissionId]
                : ['building_plan_application_id' => $application->id],
            [
                'building_plan_application_id' => $application->id,
                'legacy_bp_application_id' => $legacy?->id,
                'bp_ai_report_id' => $aiReport?->id,
                'cad_submission_id' => $cadSubmissionId > 0 ? $cadSubmissionId : $legacy?->cad_submission_id,
                'map_drawing_id' => $legacy?->map_drawing_id,
                'ad_status_log_id' => $statusLog?->id,
                'ai_recommendation' => (string) ($aiReport?->ai_recommendation ?? data_get($reportData, 'ai_recommendation', '')),
                'ai_confidence_score' => (float) ($aiReport?->ai_confidence_score ?? data_get($reportData, 'ai_confidence_score', 0)),
                'ad_decision' => $decision,
                'ad_outcome' => $outcome,
                'ad_status' => (string) ($application->current_status ?: $application->status ?: $outcome),
                'ad_remarks' => trim((string) ($application->ad_epermit_remarks ?? '')),
                'dxf_pattern_profile_json' => $patternProfile,
                'cad_confidence_assessment_json' => $cadConfidence,
                'rule_results_json' => $ruleResults,
                'feature_snapshot_json' => $this->featureSnapshot($analysisJson, $patternProfile, $cadConfidence, $ruleResults, $application),
                'label_source' => 'ad_epermit',
                'captured_at' => now(),
            ]
        );
    }

    public function captureExpertLabel(CadSubmission $submission, CadExpertLabel $label): void
    {
        $drawing = MapDrawing::query()
            ->whereJsonContains('metadata_json->cad_submission_id', $submission->id)
            ->with(['entities'])
            ->first();

        $patternProfile = [];
        if ($drawing) {
            $patternProfile = (array) data_get($drawing->metadata_json ?? [], 'dxf_pattern_profile', []);
            if (empty($patternProfile)) {
                $patternProfile = app(DxfPatternProfileService::class)->profile($drawing);
                app(DxfPatternProfileService::class)->persist($drawing, $patternProfile);
            }
        }

        $analysisJson = (array) ($submission->analysis_result ?? []);
        $ruleResults = array_values((array) data_get($analysisJson, 'rules', []));
        $training = DxfPatternTrainingExample::query()->updateOrCreate(
            ['cad_submission_id' => $submission->id],
            [
                'building_plan_application_id' => null,
                'legacy_bp_application_id' => null,
                'bp_ai_report_id' => null,
                'cad_submission_id' => $submission->id,
                'map_drawing_id' => $drawing?->id,
                'ad_status_log_id' => null,
                'ai_recommendation' => (string) data_get($submission->analysis_result ?? [], 'recommendation', ''),
                'ai_confidence_score' => (float) data_get($submission->analysis_result ?? [], 'confidence_score', 0),
                'ad_decision' => 'expert_labeled',
                'ad_outcome' => 'expert_labeled',
                'ad_status' => 'expert_label_saved',
                'ad_remarks' => trim((string) ($label->notes ?? '')),
                'dxf_pattern_profile_json' => $patternProfile,
                'cad_confidence_assessment_json' => (array) data_get($submission->analysis_result ?? [], 'cad_confidence_assessment', []),
                'rule_results_json' => $ruleResults,
                'feature_snapshot_json' => [
                    'cad_submission_id' => $submission->id,
                    'expert_label' => [
                        'plot_layer' => $label->plot_layer,
                        'building_layer' => $label->building_layer,
                        'dimension_layer' => $label->dimension_layer,
                        'text_layer' => $label->text_layer,
                        'plot_entity_handle' => $label->plot_entity_handle,
                        'building_entity_handle' => $label->building_entity_handle,
                        'front_side' => $label->front_side,
                        'notes' => $label->notes,
                    ],
                    'pattern_family' => data_get($patternProfile, 'pattern_family', 'generic_dxf'),
                    'pattern_strength' => data_get($patternProfile, 'pattern_strength', 0),
                    'rule_count' => count($ruleResults),
                ],
                'label_source' => 'expert_label',
                'captured_at' => now(),
            ]
        );
    }

    private function featureSnapshot(array $analysisJson, array $patternProfile, array $cadConfidence, array $ruleResults, PublicBuildingPlanApplication $application): array
    {
        $ruleCounts = [
            'pass' => 0,
            'fail' => 0,
            'needs_review' => 0,
        ];

        foreach ($ruleResults as $row) {
            $status = strtolower((string) data_get($row, 'status', 'needs_review'));
            if (isset($ruleCounts[$status])) {
                $ruleCounts[$status]++;
            } else {
                $ruleCounts['needs_review']++;
            }
        }

        return [
            'application_number' => $application->application_no,
            'status' => $application->current_status,
            'ad_decision' => $application->ad_epermit_decision,
            'ai_recommendation' => data_get($analysisJson, 'recommendation', data_get($analysisJson, 'report_data.ai_recommendation')),
            'ai_confidence_score' => data_get($analysisJson, 'confidence_score', data_get($analysisJson, 'report_data.ai_confidence_score')),
            'pattern_family' => data_get($patternProfile, 'pattern_family', 'generic_dxf'),
            'pattern_strength' => data_get($patternProfile, 'pattern_strength', 0),
            'pattern_signals' => data_get($patternProfile, 'signals', []),
            'cad_confidence_score' => data_get($cadConfidence, 'confidence_score', 0),
            'cad_confidence_level' => data_get($cadConfidence, 'confidence_level', 'unknown'),
            'cad_dimension_source' => data_get($cadConfidence, 'dimension_source', 'unknown'),
            'cad_fallback_method' => data_get($cadConfidence, 'fallback_method_used', 'unknown'),
            'cad_missing_layers' => data_get($cadConfidence, 'missing_layers', []),
            'cad_warnings' => data_get($cadConfidence, 'warnings', []),
            'rule_counts' => $ruleCounts,
            'detected_layers' => data_get($analysisJson, 'detected_layers', data_get($analysisJson, 'map_report.mapping', [])),
        ];
    }
}
