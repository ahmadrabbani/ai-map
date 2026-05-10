<?php

namespace App\Http\Controllers;

use App\Models\BpApplication;
use App\Services\ReviewRoutingService;
use Illuminate\Http\Request;

class DdtpReviewController extends Controller
{
    public function __construct(private readonly ReviewRoutingService $reviewRoutingService)
    {
    }

    public function index()
    {
        $applications = BpApplication::query()
            ->whereIn('status', ['Forwarded to DDTP', 'Under DDTP Review'])
            ->latest('id')
            ->paginate(20);

        return view('admin.building-plan.ddtp-dashboard', compact('applications'));
    }

    public function show(BpApplication $application)
    {
        $application->load(['aiReport', 'chatMessages', 'reviewLogs']);
        if ($application->status === 'Forwarded to DDTP') {
            $this->reviewRoutingService->transition($application, 'Under DDTP Review', 'ddtp_opened_review');
            $application->refresh();
        }

        return view('admin.building-plan.ddtp-review', compact('application'));
    }

    public function update(Request $request, BpApplication $application)
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject,needs_expert_review,return_for_correction'],
            'remarks' => ['nullable', 'string', 'max:4000'],
        ]);

        $toStatus = match ($data['action']) {
            'approve' => 'Approved',
            'reject' => 'Rejected',
            'needs_expert_review' => 'Needs Expert Review',
            'return_for_correction' => 'Returned for Correction',
        };

        $this->reviewRoutingService->transition($application, $toStatus, $data['action'], $data['remarks'] ?? null);

        return back()->with('status', 'DDTP decision saved.');
    }
}
