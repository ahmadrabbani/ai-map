<?php

namespace App\Services;

class GeometryService
{
    /**
     * Calculate polygon area using shoelace formula.
     * Points: array of [x,y]
     * Returns absolute area.
     */
    public function polygonArea(array $points): float
    {
        $n = count($points);
        if ($n < 3) return 0.0;
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x1 = $points[$i][0];
            $y1 = $points[$i][1];
            $j = ($i + 1) % $n;
            $x2 = $points[$j][0];
            $y2 = $points[$j][1];
            $sum += ($x1 * $y2) - ($x2 * $y1);
        }
        return abs($sum) / 2.0;
    }

    public function polygonPerimeter(array $points): float
    {
        if (count($points) < 2) {
            return 0.0;
        }

        $sum = 0.0;
        for ($i = 0, $n = count($points); $i < $n; $i++) {
            $next = ($i + 1) % $n;
            $sum += hypot(
                (float) $points[$next][0] - (float) $points[$i][0],
                (float) $points[$next][1] - (float) $points[$i][1]
            );
        }

        return $sum;
    }

    public function measurements(array $geometry, ?string $unit, ?float $scale): array
    {
        $points = $this->normalisePoints($geometry['points'] ?? $geometry['coordinates'] ?? []);
        $type = strtolower((string) ($geometry['type'] ?? ''));
        $factorToFeet = $this->linearFactorToFeet($unit) * ($scale ?: 1.0);
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $isPolygon = in_array($type, ['polygon', 'rectangle'], true);
        $areaCad = $isPolygon ? $this->polygonArea($points) : 0.0;
        $perimeterCad = $isPolygon ? $this->polygonPerimeter($points) : $this->polylineLength($points);
        $closed = $isPolygon && count($points) >= 3;

        return [
            'width' => $points ? (max($xs) - min($xs)) * $factorToFeet : null,
            'length' => $points ? (max($ys) - min($ys)) * $factorToFeet : null,
            'perimeter' => $perimeterCad * $factorToFeet,
            'area_sq_ft' => $areaCad * ($factorToFeet ** 2),
            'area_sq_m' => $areaCad * ($factorToFeet ** 2) * 0.09290304,
            'is_closed' => $closed,
        ];
    }

    public function endpointGap(array $points): ?float
    {
        $points = $this->normalisePoints($points);
        if (count($points) < 2) {
            return null;
        }

        return hypot(
            $points[0][0] - $points[array_key_last($points)][0],
            $points[0][1] - $points[array_key_last($points)][1]
        );
    }

    /**
     * Estimate IoU of two polygons using Monte Carlo sampling within bounding box.
     * Polygons are arrays of [x,y]. Returns float 0..1
     */
    public function estimateIoU(array $polyA, array $polyB, int $samples = 1600): float
    {
        $polyA = $this->normalisePoints($polyA);
        $polyB = $this->normalisePoints($polyB);
        if (count($polyA) < 3 || count($polyB) < 3) {
            return 0.0;
        }
        // compute bounding box
        $all = array_merge($polyA, $polyB);
        $xs = array_map(fn($p) => $p[0], $all);
        $ys = array_map(fn($p) => $p[1], $all);
        $minx = min($xs); $maxx = max($xs);
        $miny = min($ys); $maxy = max($ys);
        if ($minx == $maxx || $miny == $maxy) return 0.0;

        // A deterministic grid keeps the metric reproducible between runs.
        $side = max(10, (int) ceil(sqrt(max(100, $samples))));
        $insideA = 0; $insideB = 0; $insideBoth = 0;
        for ($row = 0; $row < $side; $row++) {
            for ($column = 0; $column < $side; $column++) {
            $x = $minx + (($column + 0.5) / $side) * ($maxx - $minx);
            $y = $miny + (($row + 0.5) / $side) * ($maxy - $miny);
            $inA = $this->pointInPolygon($x, $y, $polyA);
            $inB = $this->pointInPolygon($x, $y, $polyB);
            if ($inA) $insideA++;
            if ($inB) $insideB++;
            if ($inA && $inB) $insideBoth++;
            }
        }
        $unionCount = $insideA + $insideB - $insideBoth;
        return $unionCount > 0 ? $insideBoth / $unionCount : 0.0;
    }

    private function normalisePoints(array $points): array
    {
        return collect($points)->map(function ($point) {
            if (is_array($point) && array_is_list($point)) {
                return [(float) ($point[0] ?? 0), (float) ($point[1] ?? 0)];
            }
            return [(float) ($point['x'] ?? 0), (float) ($point['y'] ?? 0)];
        })->all();
    }

    private function polylineLength(array $points): float
    {
        $sum = 0.0;
        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $sum += hypot($points[$i][0] - $points[$i - 1][0], $points[$i][1] - $points[$i - 1][1]);
        }
        return $sum;
    }

    private function linearFactorToFeet(?string $unit): float
    {
        return match (strtoupper(trim((string) $unit))) {
            'IN', 'INCH', 'INCHES' => 1 / 12,
            'MM' => 0.003280839895,
            'CM' => 0.03280839895,
            'M', 'METRE', 'METER' => 3.280839895,
            default => 1.0,
        };
    }

    private function pointInPolygon(float $x, float $y, array $poly): bool
    {
        // ray casting algorithm
        $inside = false;
        $n = count($poly);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $poly[$i][0]; $yi = $poly[$i][1];
            $xj = $poly[$j][0]; $yj = $poly[$j][1];
            $intersect = (($yi > $y) != ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi + 0.0) + $xi);
            if ($intersect) $inside = !$inside;
        }
        return $inside;
    }
}
