<?php

namespace App\Services\Ml;

use App\Models\BpApplication;
use Illuminate\Support\Facades\Process;

class ImageryBuildSignalService
{
    public function score(BpApplication $application): array
    {
        $mapSelection = is_array(data_get($application->plot_data_json, 'map_selection'))
            ? (array) data_get($application->plot_data_json, 'map_selection')
            : [];

        $geoJson = is_array($mapSelection['geocode_json'] ?? null) ? (array) $mapSelection['geocode_json'] : [];
        $diag = is_array(data_get($geoJson, 'signal_diagnostics')) ? (array) data_get($geoJson, 'signal_diagnostics') : [];

        $baseline = $this->baselineFromDiagnostics($diag);

        $enabled = (bool) config('ml.imagery.enabled', false);
        if (! $enabled) {
            return array_merge($baseline, [
                'source' => 'baseline_heuristic',
                'model_enabled' => false,
            ]);
        }

        $python = (string) config('ml.imagery.python_bin', 'python3');
        $script = (string) config('ml.imagery.predict_script', base_path('scripts/ml/predict_imagery_signal.py'));
        $modelPath = (string) config('ml.imagery.model_path', base_path('storage/app/ml/imagery/imagery_signal_model.json'));
        $timeout = (int) config('ml.imagery.timeout_seconds', 5);

        if (! is_file($script) || ! is_file($modelPath)) {
            return array_merge($baseline, [
                'source' => 'baseline_heuristic',
                'model_enabled' => true,
                'model_used' => false,
                'note' => 'Imagery model/script missing; fallback baseline used.',
            ]);
        }

        $payload = [
            'features' => [
                'premise_count_140m' => (float) ($diag['premise_count_140m'] ?? 0),
                'poi_count_170m' => (float) ($diag['poi_count_170m'] ?? 0),
                'establishment_count_170m' => (float) ($diag['establishment_count_170m'] ?? 0),
                'has_premise' => (bool) ($diag['has_premise'] ?? false),
                'has_street_number' => (bool) ($diag['has_street_number'] ?? false),
                'normalized_score_0_100' => (float) ($diag['normalized_score_0_100'] ?? 0),
            ],
            'application_id' => $application->id,
            'model_path' => $modelPath,
        ];

        $result = Process::timeout($timeout)
            ->input(json_encode($payload, JSON_UNESCAPED_SLASHES))
            ->run([$python, $script]);
        if (! $result->successful()) {
            return array_merge($baseline, [
                'source' => 'baseline_heuristic',
                'model_enabled' => true,
                'model_used' => false,
                'note' => 'Imagery model inference failed; baseline used.',
            ]);
        }

        $decoded = json_decode(trim($result->output()), true);
        if (! is_array($decoded) || ! isset($decoded['class'])) {
            return array_merge($baseline, [
                'source' => 'baseline_heuristic',
                'model_enabled' => true,
                'model_used' => false,
                'note' => 'Imagery model returned invalid output; baseline used.',
            ]);
        }

        return [
            'source' => 'ml_model',
            'model_enabled' => true,
            'model_used' => true,
            'class' => (string) ($decoded['class'] ?? $baseline['class']),
            'built_probability' => (float) ($decoded['built_probability'] ?? $baseline['built_probability']),
            'open_probability' => (float) ($decoded['open_probability'] ?? $baseline['open_probability']),
            'mixed_probability' => (float) ($decoded['mixed_probability'] ?? $baseline['mixed_probability']),
            'confidence' => (float) ($decoded['confidence'] ?? $baseline['confidence']),
            'model_version' => (string) ($decoded['model_version'] ?? 'unknown'),
            'provenance' => 'Imagery signal is advisory only; CAD/rule engine remains authoritative.',
        ];
    }

    private function baselineFromDiagnostics(array $diag): array
    {
        $score = (float) ($diag['normalized_score_0_100'] ?? 0);
        $premise = (float) ($diag['premise_count_140m'] ?? 0);

        if ($premise >= 3 || $score >= 70) {
            $class = 'built';
            $built = min(0.95, max(0.55, $score / 100));
            $open = max(0.02, 1 - $built - 0.08);
            $mixed = 1 - $built - $open;
        } elseif ($premise <= 0 && $score <= 35) {
            $class = 'open';
            $open = min(0.95, max(0.55, 1 - ($score / 100)));
            $built = max(0.02, 1 - $open - 0.08);
            $mixed = 1 - $open - $built;
        } else {
            $class = 'mixed';
            $mixed = 0.52;
            $built = 0.24;
            $open = 0.24;
        }

        return [
            'class' => $class,
            'built_probability' => round($built, 4),
            'open_probability' => round($open, 4),
            'mixed_probability' => round($mixed, 4),
            'confidence' => round(max($built, $open, $mixed), 4),
            'model_version' => 'baseline-v1',
            'provenance' => 'Derived from geocode/place-density diagnostics; advisory only.',
        ];
    }
}
