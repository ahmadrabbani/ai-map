<?php

namespace App\Http\Controllers;

use App\Models\PublicBuildingPlanApplication;
use App\Services\PublicBuildingPlanChatService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicBuildingPlanChatController extends Controller
{
    public function __construct(
        private readonly PublicBuildingPlanChatService $chatService,
    ) {
    }

    public function index(Request $request, int $id): JsonResponse
    {
        $application = $this->applicationForApplicant($request, $id);

        $sinceId = max(0, (int) $request->query('since_id', 0));
        $channel = (string) $request->query('channel', 'ai');
        $peekOnly = $request->boolean('peek');

        $query = $application->chatMessages();
        $query->where(function ($q) use ($channel) {
            $q->where('context_json->channel', $channel);
            if ($channel === 'ai') {
                $q->orWhereNull('context_json->channel');
            }
        });

        if ($sinceId > 0) {
            $query->where('id', '>', $sinceId);
        }

        $messages = $query->get();
        $unreadBeforeRead = $this->unreadAdEpermitCount($application);

        if ($channel === 'ad_epermit' && ! $peekOnly) {
            $this->markAdEpermitMessagesRead($application);
        }

        return response()->json([
            'unread_ad_epermit_messages_count' => $channel === 'ad_epermit' && ! $peekOnly ? 0 : $unreadBeforeRead,
            'messages' => $messages->map(fn ($msg) => [
                'id' => $msg->id,
                'role' => $msg->role,
                'message' => $msg->message,
                'context_json' => $msg->context_json,
                'sent_at' => optional($msg->sent_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        try {
            $application = $this->applicationForApplicant($request, $id);

            $data = $request->validate([
                'message' => ['required', 'string', 'max:4000'],
                'channel' => ['nullable', 'string', 'in:ai,ad_epermit'],
            ]);

            $channel = (string) ($data['channel'] ?? 'ai');
            $message = trim((string) $data['message']);

            if ($channel === 'ad_epermit' && ! $this->isOfficeHours()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'AD ePermit live chat is available only during office hours.',
                ], 422);
            }

            if ($channel === 'ad_epermit') {
                $this->chatService->saveMessage($application, 'user', $message, [
                    'channel' => 'ad_epermit',
                    'source' => 'public_live_chat',
                ]);
            } else {
                $this->chatService->saveMessage($application, 'user', $message, ['channel' => 'ai']);
                $reply = $this->chatService->reply($application->fresh(), $message);
                $this->chatService->saveMessage($application, 'assistant', $reply, [
                    'channel' => 'ai',
                    'source' => 'llm',
                ]);
            }

            $messages = $application->fresh()->chatMessages()
                ->where(function ($q) use ($channel) {
                    $q->where('context_json->channel', $channel);
                    if ($channel === 'ai') {
                        $q->orWhereNull('context_json->channel');
                    }
                })
                ->get()
                ->map(fn ($msg) => [
                    'id' => $msg->id,
                    'role' => $msg->role,
                    'message' => $msg->message,
                    'context_json' => $msg->context_json,
                    'sent_at' => optional($msg->sent_at)->toIso8601String(),
                ])->values();

            return response()->json([
                'ok' => true,
                'unread_ad_epermit_messages_count' => $this->unreadAdEpermitCount($application->fresh()),
                'messages' => $messages,
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('Public building plan chat failed', [
                'application_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Chat is temporarily unavailable. Please retry.',
            ], 500);
        }
    }

    private function applicationForApplicant(Request $request, int $id): PublicBuildingPlanApplication
    {
        $application = PublicBuildingPlanApplication::findOrFail($id);
        $applicant = $request->attributes->get('bpApplicant');

        abort_unless($applicant && (int) $application->user_id === (int) $applicant->id, 403);

        return $application;
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

    private function unreadAdEpermitCount(PublicBuildingPlanApplication $application): int
    {
        return (int) $application->chatMessages()
            ->where('role', 'ad_epermit')
            ->where('context_json->channel', 'ad_epermit')
            ->whereNull('read_by_applicant_at')
            ->count();
    }

    private function markAdEpermitMessagesRead(PublicBuildingPlanApplication $application): void
    {
        $application->chatMessages()
            ->where('role', 'ad_epermit')
            ->where('context_json->channel', 'ad_epermit')
            ->whereNull('read_by_applicant_at')
            ->update(['read_by_applicant_at' => now()]);
    }
}
