<?php

namespace App\Http\Controllers;

use App\Models\PublicBuildingPlanApplication;
use App\Services\PublicBuildingPlanChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminApplicantChatController extends Controller
{
    public function __construct(
        private readonly PublicBuildingPlanChatService $chatService,
    ) {
    }

    public function index(Request $request, PublicBuildingPlanApplication $application): JsonResponse
    {
        $sinceId = max(0, (int) $request->query('since_id', 0));

        $query = $application->chatMessages()
            ->where(function ($builder) {
                $builder->where('context_json->channel', 'ad_epermit')
                    ->orWhereNull('context_json->channel');
            });

        if ($sinceId > 0) {
            $query->where('id', '>', $sinceId);
        }

        return response()->json([
            'ok' => true,
            'case' => $this->caseSummary($application),
            'messages' => $query->get()->map(fn ($msg) => [
                'id' => $msg->id,
                'role' => $msg->role,
                'message' => $msg->message,
                'context_json' => $msg->context_json,
                'sent_at' => optional($msg->sent_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request, PublicBuildingPlanApplication $application): JsonResponse
    {
        try {
            $data = $request->validate([
                'message' => ['required', 'string', 'max:4000'],
            ]);

            $message = trim((string) $data['message']);
            $this->chatService->saveMessage($application, 'ad_epermit', $message, [
                'channel' => 'ad_epermit',
                'source' => 'admin_applicant_chat',
                'case' => $this->caseSummary($application),
            ]);

            return response()->json([
                'ok' => true,
                'messages' => $application->fresh()->chatMessages()
                    ->where(function ($builder) {
                        $builder->where('context_json->channel', 'ad_epermit')
                            ->orWhereNull('context_json->channel');
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
        } catch (\Throwable $e) {
            report($e);
            Log::error('Admin applicant chat failed', [
                'application_id' => $application->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Chat is temporarily unavailable. Please retry.',
            ], 500);
        }
    }

    private function caseSummary(PublicBuildingPlanApplication $application): array
    {
        return [
            'application_no' => $application->application_no,
            'applicant_name' => $application->applicant_name,
            'applicant_cnic' => $application->applicant_cnic,
            'applicant_phone' => $application->applicant_phone,
            'applicant_email' => $application->applicant_email,
            'scheme' => $application->scheme_name ?: $application->scheme,
            'block' => $application->block_name ?: $application->block,
            'plot' => $application->plot_no ?: $application->plot_ref,
            'status' => $application->current_status ?: $application->status,
            'reviewed_at' => optional($application->reviewed_at)->toIso8601String(),
        ];
    }
}
