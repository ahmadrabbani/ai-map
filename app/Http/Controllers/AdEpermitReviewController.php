<?php

namespace App\Http\Controllers;

use App\Models\BpApplication;
use App\Services\ReviewRoutingService;
use Illuminate\Http\Request;

class AdEpermitReviewController extends Controller
{
    public function __construct(private readonly ReviewRoutingService $reviewRoutingService)
    {
    }

    public function index()
    {
        $applications = BpApplication::query()
            ->whereIn('status', ['Submitted to AD ePermit', 'Under AD ePermit Review', 'Needs Expert Review', 'Returned for Correction'])
            ->latest('id')
            ->paginate(20);

        return view('admin.building-plan.ad-dashboard', compact('applications'));
    }

    public function show(BpApplication $application)
    {
        $application->load(['aiReport', 'chatMessages', 'reviewLogs']);
        if ($application->status === 'Submitted to AD ePermit') {
            $this->reviewRoutingService->transition($application, 'Under AD ePermit Review', 'ad_opened_review');
            $application->refresh();
        }

        return view('admin.building-plan.ad-review', compact('application'));
    }

    public function update(Request $request, BpApplication $application)
    {
        $data = $request->validate([
            'action' => ['required', 'in:add_remarks,return_for_correction,needs_expert_review,forward_to_ddtp'],
            'remarks' => ['nullable', 'string', 'max:4000'],
        ]);

        $toStatus = match ($data['action']) {
            'add_remarks' => 'Under AD ePermit Review',
            'return_for_correction' => 'Returned for Correction',
            'needs_expert_review' => 'Needs Expert Review',
            'forward_to_ddtp' => 'Forwarded to DDTP',
        };

        $this->reviewRoutingService->transition($application, $toStatus, $data['action'], $data['remarks'] ?? null);

        return back()->with('status', 'AD ePermit action saved.');
    }
}
