<?php

namespace App\Services;

use App\Models\BpApplication;
use App\Models\BpEpermitSyncLog;
use Illuminate\Support\Facades\Http;
use Throwable;

class EpermitOracleSyncService
{
    public function submitCaseAndReport(BpApplication $application): array
    {
        $cfg = (array) config('services.epermit_oracle', []);
        $endpoint = trim((string) ($cfg['endpoint'] ?? ''));

        if (! (bool) ($cfg['enabled'] ?? false)) {
            return [
                'ok' => false,
                'message' => 'Oracle sync is disabled. Set EPERMIT_ORACLE_ENABLED=true.',
            ];
        }

        if ($endpoint === '') {
            return [
                'ok' => false,
                'message' => 'Oracle endpoint is missing. Set EPERMIT_ORACLE_ENDPOINT.',
            ];
        }

        $payload = $this->buildPayload($application, $cfg);
        $log = BpEpermitSyncLog::create([
            'bp_application_id' => $application->id,
            'sync_type' => 'case_submit',
            'endpoint' => $endpoint,
            'request_payload_json' => $payload,
        ]);

        try {
            $response = Http::timeout((int) ($cfg['timeout_seconds'] ?? 45))
                ->acceptJson()
                ->asForm()
                ->get($endpoint, $payload);

            $body = trim((string) $response->body());
            $parsed = $this->parseLegacyResponse($body);

            $log->update([
                'response_status' => $response->status(),
                'response_body' => $body,
                'is_success' => $parsed['ok'],
                'external_case_id' => $parsed['case_id'],
                'external_application_no' => $parsed['application_no'],
                'error_message' => $parsed['ok'] ? null : $parsed['message'],
                'synced_at' => $parsed['ok'] ? now() : null,
            ]);

            return [
                'ok' => $parsed['ok'],
                'message' => $parsed['message'],
                'external_case_id' => $parsed['case_id'],
                'external_application_no' => $parsed['application_no'],
                'raw_response' => $body,
                'payload' => $payload,
            ];
        } catch (Throwable $e) {
            $log->update([
                'is_success' => false,
                'error_message' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'message' => 'Oracle API call failed: ' . $e->getMessage(),
            ];
        }
    }

    private function buildPayload(BpApplication $application, array $cfg): array
    {
        $report = $application->aiReport;
        $plot = is_array($application->plot_data_json) ? $application->plot_data_json : [];
        $applicant = is_array($application->applicant_data_json) ? $application->applicant_data_json : [];
        $layer = is_array($application->layer_table_json) ? $application->layer_table_json : [];
        $metrics = is_array(data_get($layer, 'measurement_metrics')) ? data_get($layer, 'measurement_metrics') : [];
        $mapSel = is_array(data_get($plot, 'map_selection')) ? data_get($plot, 'map_selection') : [];

        $cnic = (string) (data_get($applicant, 'cnic') ?: '');
        $caseTypeText = (string) ($report?->ai_recommendation ?: 'Building Plan AI Review');
        $remarks = $this->buildForwardRemarks($application);

        $floorsArr = $this->buildFloorsArray($report?->analysis_json ?? []);
        $checkList = $this->buildChecklist($report?->rule_results_json ?? []);

        $params = [
            'category_id' => $this->b64((string) ($cfg['category_id'] ?? '30')),
            'sub_type_id' => $this->b64((string) ($cfg['sub_type_id'] ?? '15')),
            'type_id' => $this->b64((string) ($cfg['type_id'] ?? '1')),
            'fname' => $this->b64((string) ($application->applicant_name ?: data_get($applicant, 'name', 'Applicant'))),
            'cnic' => $this->b64($cnic),
            'contactNo' => $this->b64((string) ($application->applicant_phone ?: data_get($applicant, 'phone', ''))),
            'email' => $this->b64((string) ($application->applicant_email ?: data_get($applicant, 'email', ''))),
            'address' => $this->b64((string) data_get($plot, 'address', data_get($mapSel, 'formatted_address', ''))),
            'appartment' => $this->b64((string) data_get($plot, 'appartment', data_get($plot, 'street', ''))),
            'plot_id' => $this->b64((string) data_get($plot, 'plot_id', 'O')),
            'application_id' => $this->b64((string) $application->id),
            'image_path' => $this->b64((string) $application->uploaded_file_path),
            'road' => $this->b64((string) data_get($plot, 'street', '')),
            'mauza' => $this->b64((string) data_get($plot, 'mauza', '')),
            'khasra' => $this->b64((string) data_get($plot, 'khasra', data_get($plot, 'plot_no', ''))),
            'totalArea' => $this->b64((string) data_get($metrics, 'plot_area', '')),
            'groundArea' => $this->b64((string) data_get($metrics, 'ground_floor_covered', '')),
            'firstArea' => $this->b64((string) data_get($metrics, 'first_floor_covered', '')),
            'secondArea' => $this->b64((string) data_get($metrics, 'second_floor_covered', '')),
            'coveredArea' => $this->b64((string) data_get($metrics, 'total_floor_covered', '')),
            'openPlotArea' => $this->b64((string) data_get($metrics, 'open_area', '')),
            'Plng' => $this->b64((string) data_get($mapSel, 'lng', '')),
            'Plat' => $this->b64((string) data_get($mapSel, 'lat', '')),
            'survey_date' => $this->b64(now()->format('Y-m-d')),
            'application_no' => $this->b64((string) $application->application_number),
            'nofloors' => $this->b64((string) data_get($metrics, 'number_of_floors', count($floorsArr))),
            'approvedHeight' => $this->b64((string) data_get($metrics, 'approved_height_ft', '')),
            'floorAreaRatio' => $this->b64((string) data_get($metrics, 'far', '')),
            'buildingPurpose' => $this->b64((string) data_get($plot, 'building_purpose', data_get($plot, 'plot_category', 'RESIDENTIAL'))),
            'scheme_id' => $this->b64((string) data_get($plot, 'scheme', ($cfg['scheme_id'] ?? ''))),
            'phase_id' => $this->b64((string) data_get($plot, 'phase', ($cfg['phase_id'] ?? ''))),
            'block_id' => $this->b64((string) data_get($plot, 'block', ($cfg['block_id'] ?? ''))),
            'other_plot' => $this->b64((string) data_get($plot, 'plot_no', '')),
            'forward_remarks' => $this->b64($remarks),
            'case_type_text' => $this->b64($caseTypeText),
            'version' => $this->b64((string) ($cfg['version'] ?? '1')),
            'ebizid' => $this->b64((string) $application->application_number),
            'commercial_type_id' => $this->b64((string) ($cfg['commercial_type_id'] ?? '')),
            'is_ebiz_objection' => (string) ($cfg['is_ebiz_objection'] ?? '0'),
            'lid' => $this->b64((string) ($cfg['login_id'] ?? '31')),
            'appDate' => $this->b64(now()->format('Y-m-d')),
            'checkList' => $checkList,
            'FloorsArr' => $floorsArr,
        ];

        return $params;
    }

    private function buildChecklist(array $ruleRows): array
    {
        return collect($ruleRows)
            ->filter(fn ($row) => is_array($row))
            ->map(function ($row) {
                $code = (string) ($row['rule_code'] ?? $row['id'] ?? 'RULE');
                $status = strtolower((string) ($row['status'] ?? 'needs_review'));
                $label = in_array($status, ['pass', 'passed'], true) ? 'Clear' : (in_array($status, ['fail', 'failed'], true) ? 'Issue Found' : 'Needs Review');
                $msg = trim((string) ($row['message'] ?? ''));
                return trim(str_replace('_', ' ', $code)) . ' - ' . $label . ($msg !== '' ? (': ' . $msg) : '');
            })
            ->filter()
            ->take(30)
            ->values()
            ->all();
    }

    private function buildFloorsArray(array $analysisJson): array
    {
        $roomAreas = data_get($analysisJson, 'cad_text_room_areas', []);
        if (! is_array($roomAreas) || empty($roomAreas)) {
            return [];
        }

        return collect($roomAreas)
            ->filter(fn ($row) => is_array($row))
            ->groupBy(fn ($row) => (string) ($row['floor'] ?? 'UNKNOWN'))
            ->map(function ($rows, $floor) {
                $covered = $rows->sum(fn ($r) => (float) ($r['area_sqft'] ?? 0));
                return [
                    'floor_title' => $floor,
                    'covered_area' => (string) round($covered, 2),
                    'useable_area' => (string) round($covered, 2),
                    'non_useable_area' => '0',
                    'open_area' => '0',
                ];
            })
            ->values()
            ->all();
    }

    private function buildForwardRemarks(BpApplication $application): string
    {
        $report = $application->aiReport;
        $rec = (string) ($report?->ai_recommendation ?? 'Needs Expert Review');
        $conf = (float) ($report?->ai_confidence_score ?? 0);

        $parts = [
            'AI Recommendation: ' . $rec,
            'AI Confidence: ' . number_format($conf, 2) . '%',
            'Application No: ' . $application->application_number,
        ];

        return implode(' | ', $parts);
    }

    private function parseLegacyResponse(string $body): array
    {
        if (str_starts_with($body, '1-successfully entered-')) {
            $parts = explode('-', $body);
            return [
                'ok' => true,
                'message' => 'Submitted successfully to Oracle ePermit.',
                'application_no' => $parts[2] ?? null,
                'case_id' => $parts[3] ?? null,
            ];
        }

        return [
            'ok' => false,
            'message' => $body !== '' ? $body : 'Oracle API rejected the request.',
            'application_no' => null,
            'case_id' => null,
        ];
    }

    private function b64(string $value): string
    {
        return base64_encode($value);
    }
}
