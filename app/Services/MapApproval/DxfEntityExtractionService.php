<?php

namespace App\Services\MapApproval;

use App\Services\LayerAliasService;

class DxfEntityExtractionService
{
    private ?array $allowedLayerLookup = null;
    private ?array $aliasLookup = null;

    public function extract(string $dxfAbsPath): array
    {
        if (! is_file($dxfAbsPath)) {
            return [];
        }

        $lines = @file($dxfAbsPath, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            return [];
        }

        $entities = [];
        $current = null;
        $count = count($lines);

        for ($i = 0; $i < $count - 1; $i += 2) {
            $code = trim((string) $lines[$i]);
            $value = trim((string) $lines[$i + 1]);

            if ($code === '0') {
                if (is_array($current) && ! empty($current['entity_type'])) {
                    $normalized = $this->normalizeEntity($current);
                    if ($normalized !== null) {
                        $entities[] = $normalized;
                    }
                }

                $current = [
                    'entity_type' => strtoupper($value),
                    'layer_name' => '',
                    'handle' => '',
                    'points' => [],
                    'closed_flag' => false,
                    'text_content' => '',
                ];
                continue;
            }

            if (! is_array($current)) {
                continue;
            }

            if ($code === '5') {
                $current['handle'] = $value;
            } elseif ($code === '8') {
                $current['layer_name'] = $value;
            } elseif ($code === '70') {
                $flag = (int) $value;
                $current['closed_flag'] = ($flag & 1) === 1;
            } elseif ($code === '10') {
                $x = is_numeric($value) ? (float) $value : null;
                $y = null;
                if ($i + 3 < $count && trim((string) $lines[$i + 2]) === '20' && is_numeric(trim((string) $lines[$i + 3]))) {
                    $y = (float) trim((string) $lines[$i + 3]);
                }
                if ($x !== null && $y !== null) {
                    $current['points'][] = [$x, $y];
                }
            } elseif ($code === '11') {
                $x = is_numeric($value) ? (float) $value : null;
                $y = null;
                if ($i + 3 < $count && trim((string) $lines[$i + 2]) === '21' && is_numeric(trim((string) $lines[$i + 3]))) {
                    $y = (float) trim((string) $lines[$i + 3]);
                }
                if ($x !== null && $y !== null) {
                    $current['points'][] = [$x, $y];
                }
            } elseif (in_array($code, ['1', '3'], true)) {
                $current['text_content'] = trim(($current['text_content'] ?? '') . ' ' . $this->normalizeCadText($value));
            }
        }

        if (is_array($current) && ! empty($current['entity_type'])) {
            $normalized = $this->normalizeEntity($current);
            if ($normalized !== null) {
                $entities[] = $normalized;
            }
        }

        return $entities;
    }

    private function normalizeEntity(array $raw): ?array
    {
        $type = strtoupper((string) ($raw['entity_type'] ?? ''));
        if (! in_array($type, ['LWPOLYLINE', 'POLYLINE', 'LINE', 'TEXT', 'MTEXT'], true)) {
            return null;
        }

        $layerName = (string) (($raw['layer_name'] ?? '') ?: '(none)');
        $matchedLayer = $this->matchAllowedLayer($layerName);
        if ($matchedLayer === null && ! $this->isPublicTextLayer($layerName, $type)) {
            return null;
        }
        $matchedLayer = $matchedLayer ?: $layerName;

        $points = $raw['points'] ?? [];
        if (! is_array($points)) {
            $points = [];
        }
        $textContent = trim((string) ($raw['text_content'] ?? ''));

        $bbox = $this->bbox($points);
        $isClosed = (bool) ($raw['closed_flag'] ?? false);

        if (($type === 'LWPOLYLINE' || $type === 'POLYLINE') && count($points) >= 3) {
            $first = $points[0];
            $last = $points[count($points) - 1];
            if (abs($first[0] - $last[0]) < 0.0001 && abs($first[1] - $last[1]) < 0.0001) {
                $isClosed = true;
            }
        }

        $area = ($isClosed && count($points) >= 3) ? $this->polygonArea($points) : 0.0;
        $perimeter = count($points) >= 2 ? $this->polylinePerimeter($points, $isClosed) : 0.0;

        return [
            'handle' => (string) (($raw['handle'] ?? '') ?: uniqid('h_', true)),
            'layer_name' => $layerName,
            'matched_layer_name' => $matchedLayer,
            'entity_type' => $type,
            'points' => $points,
            'text_content' => $textContent,
            'bbox' => $bbox,
            'area' => round($area, 4),
            'perimeter' => round($perimeter, 4),
            'is_closed' => $isClosed,
        ];
    }

    private function matchAllowedLayer(string $layerName): ?string
    {
        $lookup = $this->allowedLayerLookup();
        if (empty($lookup)) {
            return $layerName;
        }

        $normalized = $this->normalizeLayerName($layerName);
        if (isset($lookup[$normalized])) {
            return $lookup[$normalized];
        }

        $aliased = $this->resolveByAlias($normalized);
        if ($aliased !== null) {
            return $aliased;
        }

        foreach ($lookup as $allowedNormalized => $officialLayer) {
            if ($allowedNormalized === '0') {
                continue;
            }

            if (preg_match('/^(ground floor|first floor|second floor|basement|roof)\s+' . preg_quote($allowedNormalized, '/') . '$/', $normalized)) {
                return $officialLayer;
            }
        }

        return null;
    }

    private function allowedLayerLookup(): array
    {
        if ($this->allowedLayerLookup !== null) {
            return $this->allowedLayerLookup;
        }

        $path = is_file(base_path('rules/layer_35.json'))
            ? base_path('rules/layer_35.json')
            : base_path('rules/layers.json');
        if (! is_file($path)) {
            return $this->allowedLayerLookup = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $layers = is_array($decoded['layers'] ?? null) ? array_keys($decoded['layers']) : [];
        $lookup = [];
        foreach ($layers as $layer) {
            if (in_array($this->normalizeLayerName((string) $layer), ['0', 'defpoints'], true)) {
                continue;
            }
            $lookup[$this->normalizeLayerName((string) $layer)] = (string) $layer;
        }

        return $this->allowedLayerLookup = $lookup;
    }



    private function resolveByAlias(string $normalizedLayer): ?string
    {
        $aliases = $this->aliasLookup();
        if (empty($aliases)) {
            return null;
        }

        return $aliases[$normalizedLayer] ?? null;
    }

    private function aliasLookup(): array
    {
        if ($this->aliasLookup !== null) {
            return $this->aliasLookup;
        }

        try {
            $svc = app(LayerAliasService::class);
            $rows = \App\Models\LayerAlias::query()->where('is_active', true)->get(['alias_name_normalized', 'official_layer_name'])->all();
            $lookup = [];
            foreach ($rows as $row) {
                $norm = (string) ($row->alias_name_normalized ?? '');
                $off = (string) ($row->official_layer_name ?? '');
                if ($norm !== '' && $off !== '') {
                    $lookup[$svc->normalize($norm)] = $off;
                    $lookup[$norm] = $off;
                }
            }
            return $this->aliasLookup = $lookup;
        } catch (\Throwable $e) {
            return $this->aliasLookup = [];
        }
    }

    private function normalizeLayerName(string $layerName): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $layerName);
        $value = trim((string) $value);
        $value = preg_replace('/^\d+\s*[\.\-_\):\s]+\s*/', '', $value);
        $value = preg_replace('/[-_]+/', ' ', (string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return strtolower(trim((string) $value));
    }

    private function normalizeCadText(string $value): string
    {
        $value = str_replace(['\\P', '\\~', '\\X'], ' ', $value);
        $value = preg_replace('/\\\\[A-Za-z][^;]*;/', ' ', $value);
        $value = str_replace(['{', '}'], ' ', (string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
    }

    private function isPublicTextLayer(string $layerName, string $type): bool
    {
        if (! in_array($type, ['TEXT', 'MTEXT'], true)) {
            return false;
        }

        $normalized = $this->normalizeLayerName($layerName);

        return in_array($normalized, [
            'applicant information',
            'plot information',
            'measurements',
            'measurement information',
            'submission details',
            'submission information',
            'measurement text',
            'text general',
            'dimension',
            'dimensions',
        ], true);
    }

    private function bbox(array $points): array
    {
        if (empty($points)) {
            return ['min_x' => 0, 'min_y' => 0, 'max_x' => 0, 'max_y' => 0];
        }

        $xs = array_map(fn ($p) => (float) $p[0], $points);
        $ys = array_map(fn ($p) => (float) $p[1], $points);

        return [
            'min_x' => min($xs),
            'min_y' => min($ys),
            'max_x' => max($xs),
            'max_y' => max($ys),
        ];
    }

    private function polygonArea(array $points): float
    {
        $sum = 0.0;
        $n = count($points);
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $sum += ($points[$i][0] * $points[$j][1]) - ($points[$j][0] * $points[$i][1]);
        }

        return abs($sum) / 2.0;
    }

    private function polylinePerimeter(array $points, bool $closed): float
    {
        $sum = 0.0;
        $n = count($points);
        for ($i = 0; $i < $n - 1; $i++) {
            $sum += hypot($points[$i + 1][0] - $points[$i][0], $points[$i + 1][1] - $points[$i][1]);
        }
        if ($closed && $n > 2) {
            $sum += hypot($points[0][0] - $points[$n - 1][0], $points[0][1] - $points[$n - 1][1]);
        }

        return $sum;
    }
}
