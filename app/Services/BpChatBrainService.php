<?php

namespace App\Services;

use App\Models\BpApplication;
use App\Models\BpChatBrain;

class BpChatBrainService
{
    public function getMemory(BpApplication $application): array
    {
        $brain = BpChatBrain::query()->where('bp_application_id', $application->id)->first();
        if (! $brain) {
            return [
                'summary' => null,
                'signals' => [],
                'last_learned_at' => null,
            ];
        }

        return [
            'summary' => $brain->learning_summary,
            'signals' => (array) $brain->memory_json,
            'last_learned_at' => optional($brain->last_learned_at)->toIso8601String(),
        ];
    }

    public function learnFromConversation(BpApplication $application): void
    {
        $messages = $application->chatMessages()
            ->where(function ($q) {
                $q->whereNull('context_json->channel')->orWhere('context_json->channel', 'ai');
            })
            ->latest('id')
            ->limit(30)
            ->get()
            ->reverse()
            ->values();

        if ($messages->isEmpty()) {
            return;
        }

        $userMsgs = $messages->where('role', 'user')->pluck('message')->filter()->values();
        $assistantMsgs = $messages->where('role', 'assistant')->pluck('message')->filter()->values();

        $latestUser = (string) ($userMsgs->last() ?? '');
        $latestAssistant = (string) ($assistantMsgs->last() ?? '');
        $report = (array) ($application->aiReport?->analysis_json ?? []);
        $patternProfile = (array) data_get($report, 'dxf_pattern_profile', []);

        $signals = [
            'conversation_count' => (int) $messages->count(),
            'user_intents' => $this->extractIntents($userMsgs->all()),
            'latest_user_need' => $latestUser,
            'latest_assistant_guidance' => $latestAssistant,
            'application_status' => (string) $application->status,
            'ai_recommendation' => (string) optional($application->aiReport)->ai_recommendation,
            'ai_confidence_score' => (float) (optional($application->aiReport)->ai_confidence_score ?? 0),
            'dxf_pattern_family' => (string) data_get($patternProfile, 'pattern_family', 'generic_dxf'),
            'dxf_pattern_strength' => (float) data_get($patternProfile, 'pattern_strength', 0),
        ];

        $summary = $this->buildSummary($signals);

        BpChatBrain::query()->updateOrCreate(
            ['bp_application_id' => $application->id],
            [
                'learning_summary' => $summary,
                'memory_json' => $signals,
                'last_learned_at' => now(),
            ]
        );
    }

    private function extractIntents(array $messages): array
    {
        $text = strtolower(implode("\n", $messages));
        $intentMap = [
            'setback' => ['setback', 'front', 'rear', 'side'],
            'coverage_far' => ['coverage', 'far', 'plot area', 'ground covered'],
            'height_storey' => ['height', 'storey', 'floor'],
            'submission' => ['submit', 'ad epermit', 'approval', 'authority'],
            'documents' => ['document', 'missing', 'checklist', 'upload'],
            'geo' => ['map', 'location', 'plot', 'zoom', 'marker'],
        ];

        $hits = [];
        foreach ($intentMap as $intent => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $hits[] = $intent;
                    break;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    private function buildSummary(array $signals): string
    {
        $intents = implode(', ', (array) ($signals['user_intents'] ?? []));

        return trim(sprintf(
            'User focus: %s. Current need: %s. Latest guidance: %s. Case status: %s. AI recommendation: %s (%.2f%%). DXF pattern: %s (%.2f).',
            $intents !== '' ? $intents : 'general compliance clarification',
            (string) ($signals['latest_user_need'] ?? ''),
            (string) ($signals['latest_assistant_guidance'] ?? ''),
            (string) ($signals['application_status'] ?? ''),
            (string) ($signals['ai_recommendation'] ?? ''),
            (float) ($signals['ai_confidence_score'] ?? 0),
            (string) ($signals['dxf_pattern_family'] ?? 'generic_dxf'),
            (float) ($signals['dxf_pattern_strength'] ?? 0)
        ));
    }
}
