<?php

namespace App\Services;

use App\Models\ApplicationStatusLog;
use App\Models\DfpsPushLog;
use App\Models\PublicBuildingPlanApplication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DfpsApplicationPushService
{
    public function __construct(private readonly AttachmentZipService $zipService)
    {
    }

    public function push(PublicBuildingPlanApplication $application, ?int $pushedByUserId = null): array
    {
        $endpoint = trim((string) config('services.dfps.endpoint'));
        if ($endpoint === '') {
            return ['ok' => false, 'message' => 'DFPS endpoint is not configured.'];
        }

        $application->loadMissing(['documents', 'statusLogs', 'siteReview']);
        $payload = $this->buildPayload($application);
        $safePayloadForLog = $payload;
        $safePayloadForLog['cnic'] = $this->masked((string) ($payload['cnic'] ?? ''));

        $tmpDir = storage_path('app/private/tmp/dfps/' . $application->id);
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $siteReviewJsonPath = $tmpDir . '/site-review.json';
        $statusHistoryJsonPath = $tmpDir . '/status-history.json';
        $metaJsonPath = $tmpDir . '/application-payload.json';

        file_put_contents($siteReviewJsonPath, json_encode($application->siteReview?->site_review_json ?? [], JSON_PRETTY_PRINT));
        file_put_contents($statusHistoryJsonPath, json_encode($application->statusLogs->toArray(), JSON_PRETTY_PRINT));
        file_put_contents($metaJsonPath, json_encode($payload, JSON_PRETTY_PRINT));

        $zipRelPath = $this->zipService->buildForApplication($application, [
            $siteReviewJsonPath => 'site-review/site-review.json',
            $statusHistoryJsonPath => 'status-logs/status-history.json',
            $metaJsonPath => 'metadata/application-payload.json',
        ]);

        if (! $zipRelPath) {
            return ['ok' => false, 'message' => 'Unable to create attachments ZIP.'];
        }

        $zipAbs = Storage::disk('local')->path($zipRelPath);
        $log = DfpsPushLog::create([
            'application_id' => $application->id,
            'pushed_by_user_id' => $pushedByUserId,
            'endpoint_url' => $endpoint,
            'request_payload_json' => $safePayloadForLog,
            'zip_file_path' => $zipRelPath,
            'success' => false,
        ]);

        try {
            $response = Http::timeout((int) config('services.dfps.timeout', 60))
                ->withBasicAuth((string) config('services.dfps.username'), (string) config('services.dfps.password'))
                ->withHeaders(array_filter([
                    'Authorization' => config('services.dfps.token') ? 'Bearer ' . (string) config('services.dfps.token') : null,
                ]))
                ->attach('attachment_zip', file_get_contents($zipAbs), basename($zipAbs))
                ->asMultipart()
                ->post($endpoint, $payload);

            $ok = $response->successful();
            $log->update([
                'response_status' => $response->status(),
                'response_body' => substr((string) $response->body(), 0, 10000),
                'success' => $ok,
                'error_message' => $ok ? null : 'DFPS push failed with status ' . $response->status(),
            ]);

            return ['ok' => $ok, 'message' => $ok ? 'DFPS push successful.' : 'DFPS push failed.', 'log_id' => $log->id];
        } catch (\Throwable $e) {
            $log->update(['error_message' => $e->getMessage(), 'success' => false]);
            return ['ok' => false, 'message' => 'DFPS push exception: ' . $e->getMessage(), 'log_id' => $log->id];
        }
    }

    private function buildPayload(PublicBuildingPlanApplication $application): array
    {
        $latestDecision = $application->statusLogs()->latest('id')->first();

        return [
            'application_no' => $application->application_no,
            'applicant_name' => $application->applicant_name,
            'cnic' => (string) $application->applicant_cnic,
            'phone' => $application->applicant_phone,
            'scheme_name' => $application->scheme_name ?: $application->scheme,
            'block_name' => $application->block_name ?: $application->block,
            'plot_no' => $application->plot_no ?: $application->plot_ref,
            'plot_area' => $application->plot_area,
            'current_status' => $application->current_status ?: $application->status,
            'ad_epermit_decision' => $application->ad_epermit_decision,
            'ad_epermit_remarks' => $application->ad_epermit_remarks,
            'submitted_at' => optional($application->submitted_at)->toISOString(),
            'reviewed_at' => optional($application->reviewed_at)->toISOString(),
            'ai_report_available' => (bool) $application->ai_report_path,
            'cad_file_available' => (bool) ($application->cad_file_path ?: $application->plan_file_path),
            'site_review_available' => (bool) $application->siteReview,
            'decision_payload' => $latestDecision?->payload_json,
        ];
    }

    private function masked(string $cnic): string
    {
        if ($cnic === '') {
            return '';
        }

        return substr($cnic, 0, 5) . '-*******-' . substr($cnic, -1);
    }
}
