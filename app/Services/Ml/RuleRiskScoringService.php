<?php

namespace App\Services\Ml;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class RuleRiskScoringService
{
    public function score(array $rules): array
    {
        $features = $this->buildFeatures($rules);
        $advisory = $this->scoreWithPythonModel($features);

        if ($advisory !== null) {
            return $advisory + [
                'features' => $features,
                'mode' => 'lightgbm',
            ];
        }

        return $this->scoreHeuristically($features);
    }

    private function buildFeatures(array $rules): array
    {
        $pass = 0;
        $fail = 0;
        $needsReview = 0;
        $warn = 0;

        foreach ($rules as $rule) {
            $status = (string) ($rule['status'] ?? '');
            if ($status === 'pass' || (($rule['pass'] ?? null) === true)) {
                $pass++;
                continue;
            }
            if ($status === 'fail' || (($rule['pass'] ?? null) === false)) {
                $fail++;
                continue;
            }
            if ($status === 'warn') {
                $warn++;
                continue;
            }
            if ($status === 'needs_review' || $status === 'needs_expert_review') {
                $needsReview++;
            }
        }

        $total = max(1, count($rules));

        return [
            'total_rules' => count($rules),
            'pass_count' => $pass,
            'fail_count' => $fail,
            'needs_review_count' => $needsReview,
            'warn_count' => $warn,
            'pass_ratio' => round($pass / $total, 6),
            'fail_ratio' => round($fail / $total, 6),
            'needs_review_ratio' => round($needsReview / $total, 6),
            'warn_ratio' => round($warn / $total, 6),
        ];
    }

    private function scoreWithPythonModel(array $features): ?array
    {
        $enabled = (bool) config('ml.lightgbm.enabled', false);
        $script = (string) config('ml.lightgbm.predict_script', base_path('scripts/ml/predict_rule_risk.py'));
        $modelPath = (string) config('ml.lightgbm.model_path', base_path('storage/app/ml/lightgbm_rule_risk.txt'));
        $pythonBin = (string) config('ml.lightgbm.python_bin', 'python3');
        $timeout = (int) config('ml.lightgbm.timeout_seconds', 5);

        if (! $enabled || ! is_file($script) || ! is_file($modelPath)) {
            return null;
        }

        $process = new Process([
            $pythonBin,
            $script,
            '--model',
            $modelPath,
            '--features',
            json_encode($features, JSON_UNESCAPED_SLASHES),
        ]);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('LightGBM advisory scoring failed; falling back to heuristic', [
                'error' => $process->getErrorOutput(),
                'output' => $process->getOutput(),
            ]);

            return null;
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (! is_array($decoded)) {
            return null;
        }

        return [
            'risk_score' => round((float) ($decoded['risk_score'] ?? 0), 2),
            'needs_expert_review_probability' => round((float) ($decoded['needs_expert_review_probability'] ?? 0), 4),
            'advisory_label' => (string) ($decoded['advisory_label'] ?? 'unknown'),
        ];
    }

    private function scoreHeuristically(array $features): array
    {
        $risk = 0.0;
        $risk += $features['fail_ratio'] * 0.75;
        $risk += $features['needs_review_ratio'] * 0.45;
        $risk += $features['warn_ratio'] * 0.20;
        $risk = min(1.0, max(0.0, $risk));

        $label = $risk >= 0.7 ? 'high_risk' : ($risk >= 0.35 ? 'medium_risk' : 'low_risk');

        return [
            'mode' => 'heuristic_fallback',
            'risk_score' => round($risk * 100, 2),
            'needs_expert_review_probability' => round($risk, 4),
            'advisory_label' => $label,
            'features' => $features,
        ];
    }
}

