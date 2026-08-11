<?php

namespace App\Http\Controllers;

use App\Models\BpApplication;
use App\Services\BpChatBrainService;
use App\Services\BuildingPlanChatService;
use App\Services\ReviewRoutingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BuildingPlanChatController extends Controller
{
    public function __construct(
        private readonly BuildingPlanChatService $chatService,
        private readonly BpChatBrainService $chatBrainService,
        private readonly ReviewRoutingService $reviewRoutingService,
    ) {
    }

    public function index(Request $request, BpApplication $application)
    {
        $sinceId = max(0, (int) $request->query('since_id', 0));
        $channel = (string) $request->query('channel', 'ai');
        $messagesQuery = $application->chatMessages()->with('application');
        $messagesQuery->where(function ($query) use ($channel) {
            $query->where('context_json->channel', $channel);
            if ($channel === 'ai') {
                $query->orWhereNull('context_json->channel');
            }
        });
        if ($sinceId > 0) {
            $messagesQuery->where('id', '>', $sinceId);
        }

        return response()->json([
            'messages' => $messagesQuery->get()->map(fn ($msg) => [
                'id' => $msg->id,
                'role' => $msg->role,
                'message' => $msg->message,
                'context_json' => $msg->context_json,
                'sent_at' => optional($msg->sent_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request, BpApplication $application)
    {
        try {
            $data = $request->validate([
                'message' => ['required', 'string', 'max:4000'],
                'channel' => ['nullable', 'string', 'in:ai,ad_epermit'],
            ]);

            $channel = (string) ($data['channel'] ?? 'ai');
            $message = trim((string) $data['message']);
            $isAdUser = $this->isAdEpermitUser($request->user());

            if ($channel === 'ad_epermit' && ! $this->isOfficeHours()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'AD ePermit live chat is available only during office hours.',
                ], 422);
            }

            if ($channel === 'ad_epermit') {
                $senderRole = $isAdUser ? 'ad_epermit' : 'user';
                $this->chatService->saveMessage($application, $senderRole, $message, [
                    'channel' => 'ad_epermit',
                    'source' => 'human_live_chat',
                ]);
            } else {
                $this->chatService->saveMessage($application, 'user', $message, [
                    'channel' => 'ai',
                ]);
                $assistant = $this->chatService->reply($application->fresh('aiReport'), $message);
                $this->chatService->saveMessage($application, 'assistant', $assistant, array_merge([
                    'source' => 'analysis+rules+schema+report+history',
                    'channel' => 'ai',
                ], $this->chatService->lastReplyContext()));
            }
            $this->chatBrainService->learnFromConversation($application->fresh('aiReport'));

            if (! in_array($application->status, ['Submitted to AD ePermit', 'Under AD ePermit Review', 'Forwarded to DDTP', 'Under DDTP Review', 'Approved', 'Rejected'], true)) {
                $this->reviewRoutingService->transition($application, 'User Chat Completed', 'chat_message_exchanged');
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'messages' => $application->chatMessages()
                        ->where(function ($query) use ($channel) {
                            $query->where('context_json->channel', $channel);
                            if ($channel === 'ai') {
                                $query->orWhereNull('context_json->channel');
                            }
                        })
                        ->get()
                        ->map(fn ($msg) => [
                            'id' => $msg->id,
                            'role' => $msg->role,
                            'message' => $msg->message,
                            'context_json' => $msg->context_json,
                            'sent_at' => optional($msg->sent_at)->toIso8601String(),
                        ])->values(),
                ]);
            }

            return back()->with('status', 'Chat message saved.');
        } catch (\Throwable $e) {
            report($e);
            Log::error('Building plan chat failed', [
                'application_id' => $application->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Chat is temporarily unavailable. Please retry.',
            ], 500);
        }
    }

    private function isAdEpermitUser($user): bool
    {
        if (! $user) {
            return false;
        }

        $role = strtolower((string) data_get($user, 'role', ''));

        return (bool) data_get($user, 'is_ad_epermit', false) || in_array($role, ['ad_epermit', 'admin'], true);
    }

    private function isOfficeHours(): bool
    {
        $now = Carbon::now(config('app.timezone'));
        if ($now->isWeekend()) {
            return false;
        }

        $hour = (int) $now->format('G');

        return $hour >= 9 && $hour < 17;
    }
}
