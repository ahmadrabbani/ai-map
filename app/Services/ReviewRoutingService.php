<?php

namespace App\Services;

use App\Models\BpApplication;
use App\Models\BpReviewLog;

class ReviewRoutingService
{
    public function transition(BpApplication $application, string $toStatus, string $action, ?string $remarks = null, array $meta = []): BpApplication
    {
        $from = $application->status;

        $application->status = $toStatus;
        if ($toStatus === 'Submitted to AD ePermit') {
            $application->submitted_to_ad_at = now();
        }
        if ($toStatus === 'Forwarded to DDTP') {
            $application->forwarded_to_ddtp_at = now();
        }
        if ($toStatus === 'Approved') {
            $application->approved_at = now();
        }
        if ($toStatus === 'Rejected') {
            $application->rejected_at = now();
        }
        $application->save();

        BpReviewLog::create([
            'bp_application_id' => $application->id,
            'actor_type' => auth()->check() ? 'user' : 'system',
            'actor_id' => auth()->id(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $toStatus,
            'remarks' => $remarks,
            'meta_json' => $meta,
        ]);

        return $application->fresh();
    }
}
