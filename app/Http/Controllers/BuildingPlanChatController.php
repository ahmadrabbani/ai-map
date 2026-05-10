<?php

namespace App\Http\Controllers;

use App\Models\BpApplication;
use App\Services\BuildingPlanChatService;
use App\Services\ReviewRoutingService;
use Illuminate\Http\Request;

class BuildingPlanChatController extends Controller
{
    public function __construct(
        private readonly BuildingPlanChatService $chatService,
        private readonly ReviewRoutingService $reviewRoutingService,
    ) {
    }

    public function store(Request $request, BpApplication $application)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $this->chatService->saveMessage($application, 'user', $data['message']);
        $assistant = $this->chatService->reply($application->fresh('aiReport'), $data['message']);
        $this->chatService->saveMessage($application, 'assistant', $assistant, [
            'source' => 'analysis+rules+schema+report+history',
        ]);

        if (! in_array($application->status, ['Submitted to AD ePermit', 'Under AD ePermit Review', 'Forwarded to DDTP', 'Under DDTP Review', 'Approved', 'Rejected'], true)) {
            $this->reviewRoutingService->transition($application, 'User Chat Completed', 'chat_message_exchanged');
        }

        return back()->with('status', 'Chat message saved.');
    }
}
