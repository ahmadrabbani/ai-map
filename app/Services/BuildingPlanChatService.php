<?php

namespace App\Services;

use App\Models\BpApplication;
use App\Models\BpChatMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BuildingPlanChatService
{
    private array $lastReplyContext = ['source' => 'local_fallback'];
    public function __construct(
        private readonly BpChatBrainService $brainService,
    ) {
    }

    public function reply(BpApplication $application, string $question): string
    {
        $this->lastReplyContext = [
            'source' => 'local_fallback',
            'model' => null,
            'gemini_configured' => (string) config('services.gemini.api_key', '') !== '',
            'openai_configured' => (string) config('services.openai.api_key', '') !== '',
        ];

        if ((string) config('services.gemini.api_key', '') !== '') {
            try {
                $reply = $this->geminiReply($application, $question);
                $this->lastReplyContext = [
                    'source' => 'gemini',
                    'model' => (string) config('services.gemini.chat_model', 'gemini-3-flash-preview'),
                    'gemini_configured' => true,
                    'openai_configured' => (string) config('services.openai.api_key', '') !== '',
                ];

                return $reply;
            } catch (\Throwable $e) {
                report($e);
                $this->lastReplyContext = [
                    'source' => 'local_fallback',
                    'model' => (string) config('services.gemini.chat_model', 'gemini-3-flash-preview'),
                    'gemini_configured' => true,
                    'openai_configured' => (string) config('services.openai.api_key', '') !== '',
                    'fallback_reason' => Str::limit($e->getMessage(), 180),
                ];

                return $this->fallbackReply($application, $question);
            }
        }

        if ((string) config('services.openai.api_key', '') !== '') {
            try {
                $reply = $this->openAiReply($application, $question);
                $this->lastReplyContext = [
                    'source' => 'openai',
                    'model' => (string) config('services.openai.chat_model', 'gpt-4o-mini'),
                    'gemini_configured' => (string) config('services.gemini.api_key', '') !== '',
                    'openai_configured' => true,
                ];

                return $reply;
            } catch (\Throwable $e) {
                report($e);
                $this->lastReplyContext = [
                    'source' => 'local_fallback',
                    'model' => (string) config('services.openai.chat_model', 'gpt-4o-mini'),
                    'gemini_configured' => (string) config('services.gemini.api_key', '') !== '',
                    'openai_configured' => true,
                    'fallback_reason' => Str::limit($e->getMessage(), 180),
                ];
            }
        }

        return $this->fallbackReply($application, $question);
    }

    public function lastReplyContext(): array
    {
        return $this->lastReplyContext;
    }

    private function geminiReply(BpApplication $application, string $question): string
    {
        $report = $application->aiReport;
        if (! $report) {
            return 'AI report is not generated yet. Please run analysis first.';
        }

        $history = $application->chatMessages()
            ->latest('id')
            ->limit(12)
            ->get()
            ->reverse()
            ->map(fn ($message) => [
                'role' => $message->role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) $message->message]],
            ])
            ->values()
            ->all();

        $context = $this->chatContext($application);
        $contextPrompt = "MANDATORY CURRENT CASE FACTS - use these first and cite exact values in the answer:\n"
            . $this->currentCaseFactsText($application)
            . "\n\nFull application context JSON:\n"
            . Str::limit(json_encode($context, JSON_PRETTY_PRINT), 18000, "\n...[truncated]")
            . "\n\nUser question:\n"
            . $question;

        $model = (string) config('services.gemini.chat_model', 'gemini-3-flash-preview');
        $apiKey = (string) config('services.gemini.api_key');
        $response = Http::acceptJson()
            ->timeout(30)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                'contents' => [
                    ...$history,
                    [
                        'role' => 'user',
                        'parts' => [['text' => $contextPrompt]],
                    ],
                ],
                'systemInstruction' => [
                    'parts' => [
                        ['text' => $this->mapApprovalSpecialistPrompt()],
                    ],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 700,
                    'temperature' => 0.2,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Gemini chat request failed: ' . $response->status() . ' ' . Str::limit($response->body(), 500));
        }

        $payload = $response->json();
        $text = collect((array) data_get($payload, 'candidates.0.content.parts', []))
            ->map(fn ($part) => data_get($part, 'text'))
            ->filter()
            ->implode("\n");
        $text = trim((string) $text);
        if (! $this->isUsableProviderReply($text, $application)) {
            throw new \RuntimeException('Gemini returned an incomplete or generic reply.');
        }

        return $text;
    }

    private function openAiReply(BpApplication $application, string $question): string
    {
        $report = $application->aiReport;
        if (! $report) {
            return 'AI report is not generated yet. Please run analysis first.';
        }

        $history = $application->chatMessages()
            ->latest('id')
            ->limit(12)
            ->get()
            ->reverse()
            ->map(fn ($message) => [
                'role' => $message->role === 'assistant' ? 'assistant' : 'user',
                'content' => (string) $message->message,
            ])
            ->values()
            ->all();

        $context = $this->chatContext($application);

        $system = $this->mapApprovalSpecialistPrompt();

        $response = Http::withToken((string) config('services.openai.api_key'))
            ->acceptJson()
            ->timeout(30)
            ->post('https://api.openai.com/v1/responses', [
                'model' => (string) config('services.openai.chat_model', 'gpt-4o-mini'),
                'input' => [
                    [
                        'role' => 'system',
                        'content' => $system,
                    ],
                    [
                        'role' => 'user',
                        'content' => "MANDATORY CURRENT CASE FACTS - use these first and cite exact values in the answer:\n"
                            . $this->currentCaseFactsText($application)
                            . "\n\nFull application context JSON:\n"
                            . Str::limit(json_encode($context, JSON_PRETTY_PRINT), 18000, "\n...[truncated]")
                            . "\n\nUser question:\n"
                            . $question,
                    ],
                ],
                'max_output_tokens' => 700,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI chat request failed: ' . $response->status() . ' ' . Str::limit($response->body(), 500));
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
        if (! $this->isUsableProviderReply($text, $application)) {
            throw new \RuntimeException('OpenAI returned an incomplete or generic reply.');
        }

        return $text;
    }

    private function mapApprovalSpecialistPrompt(): string
    {
        return <<<'PROMPT'
You are the "Map Approval Specialist," an expert AI consultant for a land survey and mapping department. Your goal is to guide users through the approval process for map submissions and provide a Confidence Score for their potential approval based on provided rules.

Operational guidelines:
0. Treat system_brain as the learned memory brain for this application and use it to keep guidance consistent.
1. Strictly adhere to the provided rules_json in the application context. Do not approve requests that violate these parameters.
2. If information is incomplete, ask for the specific missing data points required by the rules_json.
3. Maintain a professional, helpful, transparent tone. Use clear headings and bullet points.
4. Answer only from the provided application context: uploaded map analysis result, detected layers, rule results, layer schema/report data, textual table data, and chat history.
5. Never claim final approval or rejection. State only approval confidence and explain that final decision rests with the concerned Directorate / competent authority.
6. Do not give vague or generic answers. Every answer must mention the application number, the calculated confidence score, and at least two exact values from current_case_facts when available.
7. If prior chat history conflicts with current_case_facts, use current_case_facts.

Approval Confidence Metric:
- 100%: all JSON criteria are met and verified.
- 70-90%: most criteria are met, but documentation or minor adjustments are pending.
- Below 50%: major violations or missing critical data.
- Always explain the score.

Response structure:
1. Status Summary:
2. Guidance:
3. Confidence Score:
4. Action Items:
PROMPT;
    }

    private function chatContext(BpApplication $application): array
    {
        $report = $application->aiReport;

        return [
            'system_brain' => $this->brainService->getMemory($application),
            'rules_json' => $this->publicRulesJson(),
            'current_case_facts' => $this->currentCaseFacts($application),
            'approval_checklist' => $this->approvalChecklist($application),
            'approval_confidence_score' => $this->approvalConfidenceScore($application),
            'application' => [
                'number' => $application->application_number,
                'status' => $application->status,
                'applicant_name' => $application->applicant_name,
                'uploaded_file' => $application->uploaded_file_name,
            ],
            'ai_report' => [
                'analysis_status' => $report?->analysis_status,
                'recommendation' => $report?->ai_recommendation,
                'confidence_score' => $report?->ai_confidence_score,
                'rule_results' => $report?->rule_results_json,
                'warnings' => $report?->warnings_json,
                'expert_review_items' => $report?->expert_review_items_json,
                'detected_layers' => $report?->detected_layers_json,
            ],
            'application_textual_data' => [
                'applicant' => $application->applicant_data_json,
                'plot' => $application->plot_data_json,
                'layer_table' => $application->layer_table_json,
                'textual_measurements' => $this->textualMeasurements($application),
            ],
        ];
    }

    private function currentCaseFacts(BpApplication $application): array
    {
        $report = $application->aiReport;
        $metrics = $this->textualMeasurements($application);
        $plotArea = $this->numeric($metrics['plot_area'] ?? null);
        $groundCovered = $this->numeric($metrics['ground_floor_covered'] ?? null);
        $totalCovered = $this->numeric($metrics['total_floor_covered'] ?? null);
        $coverageFormula = ($plotArea !== null && $plotArea > 0 && $groundCovered !== null)
            ? round(($groundCovered / $plotArea) * 100, 2)
            : null;
        $farFormula = ($plotArea !== null && $plotArea > 0 && $totalCovered !== null)
            ? round($totalCovered / $plotArea, 4)
            : null;

        $rules = (array) ($report?->rule_results_json ?? []);
        $failedRules = array_values(array_filter($rules, fn ($row) => in_array(strtolower((string) ($row['status'] ?? '')), ['fail', 'failed'], true) || (($row['pass'] ?? null) === false)));
        $reviewRules = array_values(array_filter($rules, fn ($row) => in_array(strtolower((string) ($row['status'] ?? '')), ['needs_review', 'review', 'warn'], true)));
        $passedRules = array_values(array_filter($rules, fn ($row) => in_array(strtolower((string) ($row['status'] ?? '')), ['pass', 'passed'], true) || (($row['pass'] ?? null) === true)));

        return [
            'application_number' => $application->application_number,
            'application_status' => $application->status,
            'uploaded_file' => $application->uploaded_file_name,
            'uploaded_file_type' => strtoupper((string) ($application->uploaded_file_type ?: pathinfo((string) $application->uploaded_file_name, PATHINFO_EXTENSION))),
            'ai_recommendation' => $report?->ai_recommendation,
            'calculated_confidence_score' => $this->approvalConfidenceScore($application),
            'textual_measurements' => $metrics,
            'calculated_from_text' => [
                'ground_coverage_percent' => $coverageFormula,
                'far' => $farFormula,
                'coverage_rule' => '<= 75%',
                'far_rule' => '<= 2.3',
                'coverage_clear' => $coverageFormula !== null ? $coverageFormula <= 75.0 : null,
                'far_clear' => $farFormula !== null ? $farFormula <= 2.3 : null,
            ],
            'rule_counts' => [
                'passed' => count($passedRules),
                'failed' => count($failedRules),
                'needs_review' => count($reviewRules) + count((array) ($report?->expert_review_items_json ?? [])),
            ],
            'top_rule_results' => array_slice($rules, 0, 8),
            'approval_checklist' => $this->approvalChecklist($application),
        ];
    }

    private function currentCaseFactsText(BpApplication $application): string
    {
        $facts = $this->currentCaseFacts($application);
        $measurements = (array) ($facts['textual_measurements'] ?? []);
        $calculated = (array) ($facts['calculated_from_text'] ?? []);
        $checklist = collect($facts['approval_checklist'] ?? [])
            ->map(fn ($row) => ($row['label'] ?? $row['key']) . ': ' . ($row['status'] ?? 'unknown') . ' (' . ($row['detail'] ?? '-') . ')')
            ->implode("\n- ");

        return implode("\n", array_filter([
            'Application: ' . ($facts['application_number'] ?? '-'),
            'Status: ' . ($facts['application_status'] ?? '-'),
            'Uploaded file: ' . ($facts['uploaded_file'] ?? '-') . ' (' . ($facts['uploaded_file_type'] ?? '-') . ')',
            'AI recommendation: ' . ($facts['ai_recommendation'] ?? '-'),
            'Calculated confidence score: ' . ($facts['calculated_confidence_score'] ?? 0) . '%',
            'Text plot area: ' . ($measurements['plot_area'] ?? '-'),
            'Text ground floor covered: ' . ($measurements['ground_floor_covered'] ?? '-'),
            'Text total floor covered: ' . ($measurements['total_floor_covered'] ?? '-'),
            'Text front setback ft: ' . ($measurements['front_setback_ft'] ?? '-'),
            'Text rear setback ft: ' . ($measurements['rear_setback_ft'] ?? '-'),
            'Text left/right setback ft: ' . ($measurements['left_setback_ft'] ?? '-') . '/' . ($measurements['right_setback_ft'] ?? '-'),
            'Calculated coverage from text: ' . ($calculated['ground_coverage_percent'] ?? '-') . ' against rule <= 75%',
            'Calculated FAR from text: ' . ($calculated['far'] ?? '-') . ' against rule <= 2.3',
            'Rule counts: passed ' . data_get($facts, 'rule_counts.passed', 0) . ', failed ' . data_get($facts, 'rule_counts.failed', 0) . ', needs review ' . data_get($facts, 'rule_counts.needs_review', 0),
            "Checklist:\n- " . $checklist,
        ]));
    }

    private function fallbackReply(BpApplication $application, string $question): string
    {
        $report = $application->aiReport;
        if (! $report) {
            return $this->formatSpecialistReply(
                'AI report is not generated yet, so the submission cannot be evaluated against the map approval rules.',
                ['Upload a supported PDF or DWG file.', 'Run AI analysis to detect boundary, layers, and rule results.'],
                25,
                'Critical analysis data is missing.',
                ['Generate the AI report.', 'Confirm scale, boundary verification, zoning compliance, and professional seal.']
            );
        }

        $analysis = (array) ($report->analysis_json ?? []);
        $rules = (array) ($report->rule_results_json ?? []);
        $warnings = (array) ($report->warnings_json ?? []);
        $expertItems = (array) ($report->expert_review_items_json ?? []);
        $checklist = $this->approvalChecklist($application);
        $score = $this->approvalConfidenceScore($application);
        $missing = array_values(array_filter($checklist, fn ($row) => ($row['status'] ?? '') !== 'met'));

        $q = strtolower(trim($question));

        if (str_contains($q, 'approval') || str_contains($q, 'approve') || str_contains($q, 'when will') || str_contains($q, 'when can')) {
            $failed = array_values(array_filter($rules, fn ($row) => in_array(strtolower((string) ($row['status'] ?? '')), ['fail', 'failed'], true) || (($row['pass'] ?? null) === false)));
            $needsReview = array_values(array_filter($rules, fn ($row) => in_array(strtolower((string) ($row['status'] ?? '')), ['needs_review', 'review', 'warn'], true)));
            $passed = array_values(array_filter($rules, fn ($row) => in_array(strtolower((string) ($row['status'] ?? '')), ['pass', 'passed'], true) || (($row['pass'] ?? null) === true)));
            $nextStep = in_array($application->status, ['Submitted to AD ePermit', 'Under AD ePermit Review'], true)
                ? 'Your application is already with AD ePermit review. The next official step is officer review and routing/decision by the competent authority.'
                : 'Submit the application to AD ePermit after reviewing the AI report and chat. AD ePermit/DDTP will make the final decision.';

            return $this->formatSpecialistReply(
                'Current application status is "' . $application->status . '". AI recommendation is "' . $report->ai_recommendation . '". Final approval is not issued by the AI system.',
                [
                    'Passed rules: ' . count($passed) . '. Failed rules: ' . count($failed) . '. Review items: ' . (count($needsReview) + count($expertItems)) . '.',
                    ...$this->textualFormulaGuidance($application),
                    $nextStep,
                ],
                $score,
                empty($failed) ? 'No failed rules are currently listed, but pending checklist items still affect confidence.' : 'Failed rule results reduce approval confidence until corrected or reviewed.',
                $this->actionItemsFromChecklist($missing)
            );
        }

        if (str_contains($q, 'status') || str_contains($q, 'recommend')) {
            return $this->formatSpecialistReply(
                'Current AI recommendation is "' . $report->ai_recommendation . '". Application status is "' . $application->status . '".',
                ['Review the checklist below before submission to AD ePermit.'],
                $score,
                'Score is calculated from supported format, scale, boundary evidence, zoning/rule status, and common rejection risks.',
                $this->actionItemsFromChecklist($missing)
            );
        }

        if (str_contains($q, 'fail') || str_contains($q, 'failed')) {
            $failed = array_values(array_filter($rules, fn ($row) => (($row['status'] ?? null) === 'fail') || (($row['pass'] ?? null) === false)));
            if (empty($failed)) {
                return $this->formatSpecialistReply(
                    'No failed rules were found in the current AI report.',
                    ['Continue checking pending documentation and expert-review items.'],
                    $score,
                    'No failed rules improves confidence, but missing checklist items can still prevent a high score.',
                    $this->actionItemsFromChecklist($missing)
                );
            }
            $codes = array_map(fn ($row) => (string) ($row['id'] ?? $row['rule_code'] ?? 'unknown_rule'), array_slice($failed, 0, 10));
            return $this->formatSpecialistReply(
                'Failed rules were found: ' . implode(', ', $codes) . '.',
                ['Correct the failed rule items or wait for officer review if the AI result is uncertain.'],
                min($score, 45),
                'Failed rules are a major approval risk under the rule-based workflow.',
                array_merge(['Correct failed rules: ' . implode(', ', $codes) . '.'], $this->actionItemsFromChecklist($missing))
            );
        }

        if (str_contains($q, 'warning') || str_contains($q, 'expert')) {
            if (empty($warnings) && empty($expertItems)) {
                return $this->formatSpecialistReply(
                    'No warnings or expert review items are currently listed.',
                    ['Proceed to verify submission requirements and then submit to AD ePermit.'],
                    $score,
                    'No expert-review items improves confidence, but official approval remains with the authority.',
                    $this->actionItemsFromChecklist($missing)
                );
            }
            $all = array_slice(array_values(array_unique(array_merge($warnings, $expertItems))), 0, 8);
            return $this->formatSpecialistReply(
                'The application has review items: ' . implode(' | ', array_map(fn ($x) => (string) $x, $all)),
                ['Resolve these items or submit for officer/manual review.'],
                min($score, 70),
                'Expert-review items keep the case below full-confidence approval.',
                $this->actionItemsFromChecklist($missing)
            );
        }

        if (str_contains($q, 'plot') || str_contains($q, 'boundary')) {
            $plotHandle = data_get($analysis, 'analysis_result.auto_handles.plot_handle');
            return $this->formatSpecialistReply(
                $plotHandle ? ('Plot boundary evidence is detected. Handle: ' . $plotHandle . '.') : 'Plot boundary is not confidently detected.',
                [$plotHandle ? 'Boundary verification appears available from the AI analysis.' : 'Boundary verification is required by the public approval rules.'],
                $plotHandle ? $score : min($score, 50),
                $plotHandle ? 'Boundary evidence supports the score.' : 'Missing boundary verification is a critical approval risk.',
                $this->actionItemsFromChecklist($missing)
            );
        }

        if (str_contains($q, 'layer')) {
            $layers = (array) ($report->detected_layers_json ?? []);
            if (empty($layers)) {
                return $this->formatSpecialistReply(
                    'No parsed layer list is available in the current AI output.',
                    ['Layer parsing should be rerun or reviewed by an officer.'],
                    min($score, 55),
                    'Missing layer evidence reduces approval confidence.',
                    $this->actionItemsFromChecklist($missing)
                );
            }
            $preview = substr(json_encode($layers), 0, 500);
            return $this->formatSpecialistReply(
                'Detected layer evidence is available from the AI analysis.',
                ['Layer preview: ' . $preview],
                $score,
                'Layer evidence supports review, but final approval depends on all checklist requirements.',
                $this->actionItemsFromChecklist($missing)
            );
        }

        return $this->formatSpecialistReply(
            'I can guide this application using the public map approval rules and the AI report data already extracted.',
            [
                'Supported file formats: PDF and DWG.',
                'Scale must meet the minimum requirement of 1:500.',
                'Boundary verification and zoning compliance are required.',
                ...$this->textualFormulaGuidance($application),
            ],
            $score,
            'Score is based on the current application file, AI report, boundary evidence, zoning/rule status, and rejection-risk checklist.',
            $this->actionItemsFromChecklist($missing)
        );
    }

    private function publicRulesJson(): array
    {
        return [
            'submission_requirements' => [
                'file_format' => ['PDF', 'DWG'],
                'scale_min' => '1:500',
                'boundary_verification' => true,
                'zoning_compliance' => 'Required',
            ],
            'common_rejection_reasons' => [
                'Overlapping boundaries',
                'Missing professional seal',
                'Inaccurate coordinates',
            ],
        ];
    }

    private function approvalChecklist(BpApplication $application): array
    {
        $report = $application->aiReport;
        $analysis = (array) ($report?->analysis_json ?? []);
        $rules = (array) ($report?->rule_results_json ?? []);
        $warnings = array_map('strtolower', array_map('strval', (array) ($report?->warnings_json ?? [])));
        $expertItems = (array) ($report?->expert_review_items_json ?? []);

        $fileType = strtoupper((string) ($application->uploaded_file_type ?: pathinfo((string) $application->uploaded_file_name, PATHINFO_EXTENSION)));
        $formatOk = in_array($fileType, ['PDF', 'DWG'], true);
        $plotHandle = data_get($analysis, 'analysis_result.auto_handles.plot_handle')
            ?? data_get($analysis, 'auto_handles.plot_handle');
        $hasBoundaryEvidence = $plotHandle !== null || $this->ruleSourcesContain($rules, ['plot boundary', '1 plot boundary']);
        $failedRules = array_values(array_filter($rules, fn ($row) => in_array(strtolower((string) ($row['status'] ?? '')), ['fail', 'failed'], true) || (($row['pass'] ?? null) === false)));
        $needsReviewRules = array_values(array_filter($rules, fn ($row) => in_array(strtolower((string) ($row['status'] ?? '')), ['needs_review', 'review', 'warn'], true)));

        $hasScale = $this->applicationHasScaleEvidence($application);
        $hasProfessionalSealRisk = collect($warnings)->contains(fn ($warning) => str_contains($warning, 'seal'));
        $hasOverlapRisk = collect($warnings)->contains(fn ($warning) => str_contains($warning, 'overlap'));
        $hasCoordinateRisk = collect($warnings)->contains(fn ($warning) => str_contains($warning, 'coordinate'));

        return [
            [
                'key' => 'file_format',
                'label' => 'Accepted file format (PDF or DWG)',
                'status' => $formatOk ? 'met' : 'failed',
                'detail' => $fileType !== '' ? "Uploaded file type: {$fileType}" : 'Uploaded file type missing.',
            ],
            [
                'key' => 'scale_min',
                'label' => 'Scale minimum 1:500',
                'status' => $hasScale ? 'met' : 'missing',
                'detail' => $hasScale ? 'Scale evidence was found in uploaded textual/report data.' : 'Scale evidence is missing or not confidently extracted.',
            ],
            [
                'key' => 'boundary_verification',
                'label' => 'Boundary verification',
                'status' => $hasBoundaryEvidence ? 'met' : 'missing',
                'detail' => $plotHandle ? "Plot boundary handle: {$plotHandle}" : ($hasBoundaryEvidence ? 'Plot boundary evidence appears in mapped rule source entities.' : 'Plot boundary could not be confidently detected.'),
            ],
            [
                'key' => 'zoning_compliance',
                'label' => 'Zoning/rule compliance',
                'status' => empty($failedRules) ? (empty($needsReviewRules) && empty($expertItems) ? 'met' : 'review') : 'failed',
                'detail' => 'Failed rules: ' . count($failedRules) . ', review items: ' . (count($needsReviewRules) + count($expertItems)) . '.',
            ],
            [
                'key' => 'common_rejection_risks',
                'label' => 'Common rejection risks',
                'status' => ($hasProfessionalSealRisk || $hasOverlapRisk || $hasCoordinateRisk) ? 'review' : 'met',
                'detail' => ($hasProfessionalSealRisk || $hasOverlapRisk || $hasCoordinateRisk)
                    ? 'Possible seal, overlap, or coordinate warning found.'
                    : 'No overlap, professional seal, or coordinate warning is currently listed.',
            ],
        ];
    }

    private function textualMeasurements(BpApplication $application): array
    {
        $metadata = is_array($application->mapDrawing?->metadata_json) ? $application->mapDrawing->metadata_json : [];
        $fromDrawing = is_array(data_get($metadata, 'cad_text_measurement_metrics'))
            ? (array) data_get($metadata, 'cad_text_measurement_metrics')
            : [];

        $fromPlot = is_array($application->plot_data_json)
            ? (array) data_get($application->plot_data_json, 'measurements', [])
            : [];

        return array_filter(array_replace($fromPlot, $fromDrawing), fn ($value) => $value !== null && $value !== '');
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function ruleSourcesContain(array $rules, array $needles): bool
    {
        foreach ($rules as $row) {
            $sourceText = strtolower(json_encode($row['source_entities'] ?? []));
            foreach ($needles as $needle) {
                if ($sourceText !== '' && str_contains($sourceText, strtolower($needle))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function approvalConfidenceScore(BpApplication $application): int
    {
        $checklist = $this->approvalChecklist($application);
        $score = 100;

        foreach ($checklist as $item) {
            $status = (string) ($item['status'] ?? 'missing');
            if ($status === 'failed') {
                $score -= 30;
            } elseif ($status === 'missing') {
                $score -= 22;
            } elseif ($status === 'review') {
                $score -= 12;
            }
        }

        return max(0, min(100, $score));
    }

    private function applicationHasScaleEvidence(BpApplication $application): bool
    {
        $haystack = strtolower(json_encode([
            'plot' => $application->plot_data_json,
            'layer_table' => $application->layer_table_json,
            'report' => $application->aiReport?->analysis_json,
            'markdown' => $application->aiReport?->report_markdown,
        ]));

        return str_contains($haystack, 'scale') || preg_match('/1\s*[:\/]\s*500/', $haystack) === 1;
    }

    private function actionItemsFromChecklist(array $missing): array
    {
        if (empty($missing)) {
            return ['Submit to AD ePermit for official review.', 'Keep professional seal and coordinate documents ready for officer verification.'];
        }

        return array_values(array_map(function (array $item): string {
            return match ($item['key'] ?? '') {
                'file_format' => 'Upload the map in PDF or DWG format.',
                'scale_min' => 'Provide or correct the drawing scale. Minimum required scale is 1:500.',
                'boundary_verification' => 'Ensure plot boundary is clear and can be verified from the uploaded map.',
                'zoning_compliance' => 'Resolve failed or review-required zoning/rule items.',
                'common_rejection_risks' => 'Check professional seal, boundary overlap, and coordinate accuracy.',
                default => (string) ($item['label'] ?? 'Complete missing requirement.'),
            };
        }, $missing));
    }

    private function textualFormulaGuidance(BpApplication $application): array
    {
        $facts = $this->currentCaseFacts($application);
        $measurements = (array) ($facts['textual_measurements'] ?? []);
        $calculated = (array) ($facts['calculated_from_text'] ?? []);
        $lines = [];

        if (($measurements['plot_area'] ?? null) !== null && ($measurements['ground_floor_covered'] ?? null) !== null) {
            $lines[] = 'Textual coverage check: ground floor covered ' . $measurements['ground_floor_covered']
                . ' / plot area ' . $measurements['plot_area']
                . ' = ' . ($calculated['ground_coverage_percent'] ?? '-') . '% against allowed 75%.';
        }
        if (($measurements['plot_area'] ?? null) !== null && ($measurements['total_floor_covered'] ?? null) !== null) {
            $lines[] = 'Textual FAR check: total floor covered ' . $measurements['total_floor_covered']
                . ' / plot area ' . $measurements['plot_area']
                . ' = ' . ($calculated['far'] ?? '-') . ' against allowed 2.3.';
        }
        if (($measurements['front_setback_ft'] ?? null) !== null || ($measurements['rear_setback_ft'] ?? null) !== null) {
            $lines[] = 'Textual setback values: front ' . ($measurements['front_setback_ft'] ?? '-')
                . ' ft, rear ' . ($measurements['rear_setback_ft'] ?? '-')
                . ' ft, left/right ' . ($measurements['left_setback_ft'] ?? '-') . '/' . ($measurements['right_setback_ft'] ?? '-') . ' ft.';
        }

        return $lines;
    }

    private function isUsableProviderReply(string $reply, BpApplication $application): bool
    {
        if (strlen(trim($reply)) < 180) {
            return false;
        }

        $lower = strtolower($reply);
        if (! str_contains($lower, 'confidence score')) {
            return false;
        }

        $appNo = strtolower((string) $application->application_number);
        if ($appNo !== '' && ! str_contains($lower, $appNo)) {
            return false;
        }

        return true;
    }

    private function formatSpecialistReply(string $summary, array $guidance, int $score, string $scoreReason, array $actionItems): string
    {
        return implode("\n\n", [
            "Status Summary:\n{$summary}",
            "Guidance:\n- " . implode("\n- ", array_filter($guidance)),
            "Confidence Score:\n**{$score}%** - {$scoreReason}",
            "Action Items:\n- " . implode("\n- ", array_filter($actionItems)),
            'Important Notice: This is an AI-assisted confidence assessment only. Final approval, rejection, or correction is reserved by the concerned Directorate / competent authority.',
        ]);
    }

    public function saveMessage(BpApplication $application, string $role, string $message, array $context = []): BpChatMessage
    {
        return BpChatMessage::create([
            'bp_application_id' => $application->id,
            'role' => $role,
            'message' => $message,
            'context_json' => $context,
            'sent_at' => now(),
        ]);
    }
}
