<?php

namespace App\Services;

use App\Models\PublicBuildingPlanApplication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PublicBuildingPlanChatService
{
    public function saveMessage(PublicBuildingPlanApplication $application, string $role, string $message, array $context = []): void
    {
        $application->chatMessages()->create([
            'role' => $role,
            'message' => trim($message),
            'context_json' => $context ?: null,
            'sent_at' => now(),
        ]);
    }

    public function reply(PublicBuildingPlanApplication $application, string $question): string
    {
        if ((string) config('services.gemini.api_key', '') !== '') {
            try {
                return $this->geminiReply($application, $question);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ((string) config('services.openai.api_key', '') !== '') {
            try {
                return $this->openAiReply($application, $question);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->fallbackReply($application, $question);
    }

    private function openAiReply(PublicBuildingPlanApplication $application, string $question): string
    {
        $report = (array) ($application->ai_report_json ?? []);
        $history = $application->chatMessages()
            ->where(function ($q) {
                $q->whereNull('context_json->channel')->orWhere('context_json->channel', 'ai');
            })
            ->latest('id')
            ->limit(4)
            ->get()
            ->reverse()
            ->map(fn ($msg) => [
                'role' => in_array((string) $msg->role, ['assistant', 'ai'], true) ? 'assistant' : 'user',
                'content' => Str::limit((string) $msg->message, 300),
            ])
            ->values()
            ->all();

        $compactReport = $this->compactReport($report);

        $prompt = [
            [
                'role' => 'system',
                'content' => 'You are Building Plan AI Assistant. Use application facts and AI report only. Keep response practical and concise. Never claim final legal approval. Always mention this is preliminary and authority decision is final. If cad_confidence_assessment is present, explain whether measurements are verified, calculated, or estimated, and lower certainty when marked CAD layers or textual dimensions are missing. If dxf_pattern_profile is present, use the recognized DXF pattern family and strength to explain whether the case is text-table driven, geometry dominant, or only partially recognized.',
            ],
            ...$history,
            [
                'role' => 'user',
                'content' => 'Application Context: ' . json_encode([
                    'application_no' => $application->application_no,
                    'status' => $application->status,
                    'ai_status' => $application->ai_status,
                    'scheme' => $application->scheme,
                    'phase' => $application->phase,
                    'block' => $application->block,
                    'plot_ref' => $application->plot_ref,
                    'selected_address' => $application->selected_address,
                    'ai_report_compact' => $compactReport,
                ], JSON_UNESCAPED_SLASHES)
                . "\n\nUser question: {$question}",
            ],
        ];

        $response = Http::withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->timeout(30)
            ->post('https://api.openai.com/v1/responses', [
                'model' => (string) config('services.openai.chat_model', 'gpt-4o-mini'),
                'input' => $prompt,
                'max_output_tokens' => 500,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI chat failed: ' . $response->status() . ' ' . Str::limit($response->body(), 280));
        }

        $payload = $response->json();
        $text = data_get($payload, 'output_text');
        if (! is_string($text) || trim($text) === '') {
            $text = collect((array) data_get($payload, 'output', []))
                ->flatMap(fn ($item) => (array) data_get($item, 'content', []))
                ->map(fn ($content) => data_get($content, 'text'))
                ->filter()
                ->implode("\n");
        }

        $text = trim((string) $text);

        return $text !== '' ? $text : $this->fallbackReply($application, $question);
    }

    private function geminiReply(PublicBuildingPlanApplication $application, string $question): string
    {
        $report = (array) ($application->ai_report_json ?? []);
        $compactReport = $this->compactReport($report);

        $history = $application->chatMessages()
            ->where(function ($q) {
                $q->whereNull('context_json->channel')->orWhere('context_json->channel', 'ai');
            })
            ->latest('id')
            ->limit(4)
            ->get()
            ->reverse()
            ->map(function ($msg) {
                $role = in_array((string) $msg->role, ['assistant', 'ai'], true) ? 'model' : 'user';
                return [
                    'role' => $role,
                    'parts' => [[
                        'text' => Str::limit((string) $msg->message, 300),
                    ]],
                ];
            })
            ->values()
            ->all();

        $prompt = 'Application Context: ' . json_encode([
            'application_no' => $application->application_no,
            'status' => $application->status,
            'ai_status' => $application->ai_status,
            'scheme' => $application->scheme,
            'phase' => $application->phase,
            'block' => $application->block,
            'plot_ref' => $application->plot_ref,
            'selected_address' => $application->selected_address,
            'ai_report_compact' => $compactReport,
        ], JSON_UNESCAPED_SLASHES)
        . "\n\nUser question: {$question}";

        $model = (string) config('services.gemini.chat_model', 'gemini-3-flash-preview');
        $apiKey = (string) config('services.gemini.api_key');
        $response = Http::acceptJson()
            ->timeout(30)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                'contents' => [
                    ...$history,
                    [
                        'role' => 'user',
                        'parts' => [['text' => $prompt]],
                    ],
                ],
                'systemInstruction' => [
                    'parts' => [[
                        'text' => 'You are Building Plan AI Assistant. Use application facts and AI report only. Keep response practical and concise. Never claim final legal approval. Always mention this is preliminary and authority decision is final. If cad_confidence_assessment is present, explain whether measurements are verified, calculated, or estimated, and lower certainty when marked CAD layers or textual dimensions are missing. If dxf_pattern_profile is present, use the recognized DXF pattern family and strength to explain whether the case is text-table driven, geometry dominant, or only partially recognized.',
                    ]],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 500,
                    'temperature' => 0.2,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Gemini chat failed: ' . $response->status() . ' ' . Str::limit($response->body(), 280));
        }

        $payload = $response->json();
        $text = collect((array) data_get($payload, 'candidates.0.content.parts', []))
            ->map(fn ($part) => data_get($part, 'text'))
            ->filter()
            ->implode("\n");

        $text = trim((string) $text);

        return $text !== '' ? $text : $this->fallbackReply($application, $question);
    }

    private function fallbackReply(PublicBuildingPlanApplication $application, string $question): string
    {
        $report = (array) ($application->ai_report_json ?? []);
        $confidence = $this->extractConfidencePercent($report);
        $clear = (int) data_get($report, 'summary.clear_count', 0);
        $review = (int) data_get($report, 'summary.needs_review_count', 0);
        $issues = (int) data_get($report, 'summary.issue_count', 0);

        $patternFamily = (string) data_get($report, 'dxf_pattern_profile.pattern_family', 'generic_dxf');
        $patternStrength = number_format((float) data_get($report, 'dxf_pattern_profile.pattern_strength', 0), 2);

        return "Preliminary guidance for {$application->application_no}: AI status {$application->ai_status}, confidence " . number_format($confidence, 2) . "%, Clear {$clear}, Under Review {$review}, Issues {$issues}. "
            . "DXF pattern {$patternFamily} ({$patternStrength}). Key checks are based on text layers and AI report summary. For human review, use AD ePermit Live during office hours. "
            . "Final approval remains with authority.";
    }

    private function compactReport(array $report): array
    {
        $rules = array_values(array_slice((array) data_get($report, 'rule_results', data_get($report, 'checks', [])), 0, 20));
        $warnings = array_values(array_slice((array) data_get($report, 'warnings', []), 0, 12));
        $cadConfidence = (array) data_get($report, 'cad_confidence_assessment', []);
        $patternProfile = (array) data_get($report, 'dxf_pattern_profile', []);

        return [
            'recommendation' => data_get($report, 'recommendation', data_get($report, 'summary.recommendation')),
            'confidence' => $this->extractConfidencePercent($report),
            'cad_confidence_assessment' => [
                'score' => (float) data_get($cadConfidence, 'confidence_score', 0),
                'level' => (string) data_get($cadConfidence, 'confidence_level', 'unknown'),
                'missing_layers' => (array) data_get($cadConfidence, 'missing_layers', []),
                'available_layers' => (array) data_get($cadConfidence, 'available_layers', []),
                'fallback_method_used' => (string) data_get($cadConfidence, 'fallback_method_used', 'unknown'),
                'dimension_source' => (string) data_get($cadConfidence, 'dimension_source', 'unknown'),
                'warnings' => (array) data_get($cadConfidence, 'warnings', []),
            ],
            'dxf_pattern_profile' => [
                'family' => (string) data_get($patternProfile, 'pattern_family', 'generic_dxf'),
                'strength' => (float) data_get($patternProfile, 'pattern_strength', 0),
            ],
            'clear_count' => (int) data_get($report, 'summary.clear_count', 0),
            'needs_review_count' => (int) data_get($report, 'summary.needs_review_count', 0),
            'issue_count' => (int) data_get($report, 'summary.issue_count', 0),
            'key_findings' => array_values(array_slice((array) data_get($report, 'key_findings', []), 0, 15)),
            'rule_results' => array_map(function ($row) {
                return [
                    'name' => (string) data_get($row, 'name', data_get($row, 'rule', '')),
                    'status' => (string) data_get($row, 'status', ''),
                    'actual' => data_get($row, 'actual'),
                    'required' => data_get($row, 'required'),
                    'clause' => data_get($row, 'clause_reference', data_get($row, 'clause')),
                ];
            }, $rules),
            'warnings' => $warnings,
        ];
    }

    private function extractConfidencePercent(array $report): float
    {
        $candidates = [
            data_get($report, 'analysis.confidence_score'),
            data_get($report, 'analysis.analysis.confidence_score'),
            data_get($report, 'report_data.ai_confidence_score'),
            data_get($report, 'summary.ai_confidence'),
            data_get($report, 'confidence'),
            data_get($report, 'ai_confidence_score'),
        ];

        foreach ($candidates as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $num = (float) $value;
            if ($num > 0 && $num <= 1) {
                $num *= 100;
            }

            return round(max(0, min(100, $num)), 2);
        }

        return 0.0;
    }
}
