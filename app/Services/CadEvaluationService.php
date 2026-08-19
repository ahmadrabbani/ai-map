<?php

namespace App\Services;

use App\Models\CadEvaluationMetric;
use App\Models\CadEvaluationRun;
use App\Models\CadPrediction;
use App\Models\CadSubmission;
use App\Models\CadTag;
use Illuminate\Support\Collection;

class CadEvaluationService
{
    public function __construct(private readonly GeometryService $geometry)
    {
    }

    public function evaluate(CadSubmission $submission, array $options = []): CadEvaluationRun
    {
        $iouThreshold = (float) ($options['iou_threshold'] ?? 0.75);
        $modelVersion = $options['model_version'] ?? null;
        $predictions = CadPrediction::query()
            ->where('cad_submission_id', $submission->id)
            ->when($modelVersion, fn ($query) => $query->where('model_version', $modelVersion))
            ->get();
        $truth = CadTag::query()
            ->where('cad_submission_id', $submission->id)
            ->whereIn('verification_level', ['expert_verified', 'gold_standard'])
            ->get();

        $matches = $this->matchPredictions($predictions, $truth, $iouThreshold);
        $labels = $predictions->pluck('label_key')->merge($truth->pluck('label_key'))->filter()->unique()->values();
        $perLabel = [];
        foreach ($labels as $label) {
            $tp = collect($matches)->where('predicted_label', $label)->where('truth_label', $label)->where('geometry_match', true)->count();
            $fp = $predictions->where('label_key', $label)->count() - $tp;
            $fn = $truth->where('label_key', $label)->count() - $tp;
            $precision = $this->ratio($tp, $tp + $fp);
            $recall = $this->ratio($tp, $tp + $fn);
            $perLabel[$label] = [
                'tp' => $tp, 'fp' => $fp, 'fn' => $fn,
                'precision' => $precision, 'recall' => $recall,
                'f1' => $this->f1($precision, $recall),
                'support' => $tp + $fn,
            ];
        }

        $totalTp = collect($perLabel)->sum('tp');
        $totalFp = collect($perLabel)->sum('fp');
        $totalFn = collect($perLabel)->sum('fn');
        $microPrecision = $this->ratio($totalTp, $totalTp + $totalFp);
        $microRecall = $this->ratio($totalTp, $totalTp + $totalFn);
        $support = collect($perLabel)->sum('support');
        $matched = collect($matches)->where('geometry_match', true);
        $areaErrors = $matched->pluck('area_error_percent')->filter(fn ($value) => $value !== null)->sort()->values();

        $summary = [
            'ground_truth_count' => $truth->count(),
            'prediction_count' => $predictions->count(),
            'exact_label_accuracy' => $this->ratio($totalTp, max($predictions->count(), $truth->count())),
            'macro_f1' => $perLabel ? collect($perLabel)->avg('f1') : null,
            'micro_f1' => $this->f1($microPrecision, $microRecall),
            'weighted_f1' => $support ? collect($perLabel)->sum(fn ($row) => $row['f1'] * $row['support']) / $support : null,
            'average_polygon_iou' => $matched->avg('iou'),
            'mean_absolute_area_error' => $areaErrors->avg(),
            'median_area_error' => $this->median($areaErrors),
            'area_within_2_percent' => $this->within($areaErrors, 2),
            'area_within_5_percent' => $this->within($areaErrors, 5),
            'area_within_10_percent' => $this->within($areaErrors, 10),
            'confusion_matrix' => collect($matches)
                ->filter(fn ($row) => $row['truth_label'] && $row['predicted_label'] && $row['geometry_match'])
                ->groupBy(fn ($row) => $row['truth_label'].'|'.$row['predicted_label'])
                ->map->count()->all(),
        ];

        $run = CadEvaluationRun::create([
            'cad_submission_id' => $submission->id,
            'name' => 'evaluation-'.$submission->id.'-'.now()->format('YmdHis'),
            'dataset_split' => $options['dataset_split'] ?? 'review',
            'locked_ground_truth' => $truth->isNotEmpty() && $truth->every(fn ($tag) => $tag->locked),
            'params' => ['iou_threshold' => $iouThreshold, 'model_version' => $modelVersion],
            'summary' => $summary,
        ]);

        foreach ($perLabel as $label => $metrics) {
            CadEvaluationMetric::create([
                'evaluation_run_id' => $run->id,
                'metric_scope' => 'entity',
                'entity_type' => $label,
                'metrics' => $metrics,
            ]);
        }

        CadEvaluationMetric::create([
            'evaluation_run_id' => $run->id,
            'metric_scope' => 'overall',
            'metrics' => $summary,
        ]);

        return $run->load('metrics');
    }

    private function matchPredictions(Collection $predictions, Collection $truth, float $threshold): array
    {
        $usedPredictions = [];
        $matches = [];
        foreach ($truth as $tag) {
            $best = null;
            foreach ($predictions as $prediction) {
                if (isset($usedPredictions[$prediction->id])) {
                    continue;
                }
                $iou = $this->geometryScore($prediction, $tag);
                if ($best === null || $iou > $best['iou']) {
                    $best = ['prediction' => $prediction, 'iou' => $iou];
                }
            }
            if ($best && $best['iou'] >= $threshold) {
                $prediction = $best['prediction'];
                $usedPredictions[$prediction->id] = true;
                $matches[] = [
                    'truth_id' => $tag->id,
                    'prediction_id' => $prediction->id,
                    'truth_label' => $tag->label_key,
                    'predicted_label' => $prediction->label_key,
                    'geometry_match' => true,
                    'iou' => $best['iou'],
                    'area_error_percent' => $this->areaError($prediction, $tag),
                ];
            } else {
                $matches[] = [
                    'truth_id' => $tag->id, 'prediction_id' => null,
                    'truth_label' => $tag->label_key, 'predicted_label' => null,
                    'geometry_match' => false, 'iou' => $best['iou'] ?? 0,
                    'area_error_percent' => null,
                ];
            }
        }

        return $matches;
    }

    private function geometryScore(CadPrediction $prediction, CadTag $tag): float
    {
        $predictionGeometry = $prediction->geometry_json ?: [];
        $truthGeometry = $tag->geometry_json ?: [];
        $polygonTypes = ['polygon', 'rectangle'];
        if (in_array(strtolower((string) $prediction->geometry_type), $polygonTypes, true)
            && in_array(strtolower((string) $tag->geometry_type), $polygonTypes, true)) {
            return $this->geometry->estimateIoU(
                $predictionGeometry['points'] ?? $predictionGeometry['coordinates'] ?? [],
                $truthGeometry['points'] ?? $truthGeometry['coordinates'] ?? []
            );
        }

        $a = $predictionGeometry['points'][0] ?? $predictionGeometry['coordinates'][0] ?? null;
        $b = $truthGeometry['points'][0] ?? $truthGeometry['coordinates'][0] ?? null;
        if (! is_array($a) || ! is_array($b)) {
            return 0.0;
        }
        $ax = (float) ($a['x'] ?? $a[0] ?? 0);
        $ay = (float) ($a['y'] ?? $a[1] ?? 0);
        $bx = (float) ($b['x'] ?? $b[0] ?? 0);
        $by = (float) ($b['y'] ?? $b[1] ?? 0);
        $tolerance = (float) data_get($prediction->metadata, 'distance_tolerance', 1.0);
        return hypot($ax - $bx, $ay - $by) <= $tolerance ? 1.0 : 0.0;
    }

    private function areaError(CadPrediction $prediction, CadTag $tag): ?float
    {
        $verified = (float) $tag->area_sq_ft;
        if ($verified <= 0) {
            return null;
        }
        $measurement = $this->geometry->measurements(
            $prediction->geometry_json ?: [],
            data_get($prediction->metadata, 'unit', $tag->unit),
            (float) data_get($prediction->metadata, 'scale', $tag->scale ?: 1)
        );
        return abs($measurement['area_sq_ft'] - $verified) / $verified * 100;
    }

    private function ratio(float|int $numerator, float|int $denominator): float
    {
        return $denominator > 0 ? $numerator / $denominator : 0.0;
    }

    private function f1(float $precision, float $recall): float
    {
        return ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;
    }

    private function median(Collection $values): ?float
    {
        $count = $values->count();
        if (! $count) return null;
        $middle = intdiv($count, 2);
        return $count % 2 ? (float) $values[$middle] : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    private function within(Collection $values, float $threshold): ?float
    {
        return $values->isNotEmpty() ? $values->filter(fn ($value) => $value <= $threshold)->count() / $values->count() : null;
    }
}
