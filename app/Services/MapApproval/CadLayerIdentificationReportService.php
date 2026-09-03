<?php

namespace App\Services\MapApproval;

use App\Models\BpApplication;
use App\Models\CadExpertLabel;
use App\Models\CadSubmission;
use App\Models\PublicBuildingPlanApplication;
use Illuminate\Support\Str;

class CadLayerIdentificationReportService
{
    public function forSubmission(CadSubmission $submission): array
    {
        $label = CadExpertLabel::query()
            ->where('cad_submission_id', $submission->id)
            ->first();

        $layerMap = is_string($label?->layer_map_json)
            ? json_decode($label->layer_map_json, true)
            : [];

        return $this->build(
            is_array($layerMap) ? $layerMap : [],
            $label?->labeled_by,
            $label?->updated_at?->toIso8601String(),
        );
    }

    public function build(array $layerMap, ?string $reviewer = null, ?string $markedAt = null): array
    {
        $objects = [];

        foreach ($layerMap as $cadLayer => $metadata) {
            if (! is_array($metadata)) {
                continue;
            }

            $objectKey = trim((string) ($metadata['tag'] ?? ''));
            if ($objectKey === '') {
                continue;
            }

            $objects[] = [
                'cad_layer' => (string) $cadLayer,
                'object_key' => $objectKey,
                'object_name' => Str::headline($objectKey),
                'visible' => (bool) ($metadata['visible'] ?? true),
                'verification_status' => 'officer_verified',
                'training_status' => 'captured_as_expert_label',
            ];
        }

        usort($objects, fn (array $left, array $right) => strcmp($left['cad_layer'], $right['cad_layer']));

        return [
            'status' => $objects === [] ? 'awaiting_officer_marking' : 'updated_from_officer_marking',
            'object_count' => count($objects),
            'objects' => $objects,
            'reviewer' => $reviewer,
            'marked_at' => $markedAt,
            'training_capture' => $objects === [] ? 'not_available' : 'saved',
            'model_retraining' => 'separate_governed_step',
        ];
    }

    public function syncLinkedReports(CadSubmission $submission, array $identificationReport): void
    {
        $applications = BpApplication::query()
            ->where('cad_submission_id', $submission->id)
            ->with('aiReport')
            ->get();

        foreach ($applications as $application) {
            if ($application->aiReport) {
                $analysis = is_array($application->aiReport->analysis_json)
                    ? $application->aiReport->analysis_json
                    : [];
                $analysis['officer_verified_layer_identifications'] = $identificationReport;
                $application->aiReport->analysis_json = $analysis;
                $application->aiReport->save();
            }

            PublicBuildingPlanApplication::query()
                ->where('legacy_bp_application_id', $application->id)
                ->get()
                ->each(function (PublicBuildingPlanApplication $publicApplication) use ($identificationReport) {
                    $report = is_array($publicApplication->ai_report_json)
                        ? $publicApplication->ai_report_json
                        : [];
                    $report['officer_verified_layer_identifications'] = $identificationReport;
                    data_set($report, 'analysis.officer_verified_layer_identifications', $identificationReport);
                    $publicApplication->ai_report_json = $report;
                    $publicApplication->save();
                });
        }
    }
}
