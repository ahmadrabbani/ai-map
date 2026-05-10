<?php

namespace App\Services\MapApproval;

use App\Models\CadEntityFeature;
use App\Models\CadSubmission;
use App\Models\MapDrawing;
use App\Models\MapEntity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class MapApprovalPipelineService
{
    public function __construct(
        private readonly DxfEntityExtractionService $extractionService,
        private readonly CadSemanticMappingService $mappingService
    ) {
    }

    public function uploadAndMap(UploadedFile $file, ?int $applicationId = null): array
    {
        $dir = 'uploads/map-approval/' . date('Y/m/d');
        $stored = $file->storeAs($dir, uniqid('drawing_', true) . '.' . strtolower($file->getClientOriginalExtension()), 'local');
        $originalAbs = Storage::disk('local')->path($stored);

        $drawing = MapDrawing::create([
            'application_id' => $applicationId,
            'original_file_path' => $stored,
            'status' => 'uploaded',
            'mapping_status' => 'pending',
            'validation_status' => 'pending',
            'metadata_json' => [
                'original_filename' => $file->getClientOriginalName(),
            ],
        ]);

        $ext = strtolower($file->getClientOriginalExtension());
        $dxfPath = $stored;
        if ($ext === 'dwg') {
            $dxfPath = $this->convertDwgToDxf($drawing->id, $originalAbs, $stored);
        }

        $drawing->dxf_file_path = $dxfPath;
        $drawing->status = $dxfPath ? 'mapped' : 'needs_expert_review';
        $drawing->save();

        if (! $dxfPath) {
            return [
                'drawing' => $drawing,
                'mapping_summary' => [
                    'auto_mapped_entities' => 0,
                    'needs_review_entities' => 0,
                    'missing_required_entities' => ['plot_boundary', 'ground_floor_covered_polygon'],
                    'ignored_layers' => 0,
                    'blocking_issues' => ['dxf_conversion_failed'],
                ],
            ];
        }

        $entities = $this->extractionService->extract(Storage::disk('local')->path($dxfPath));
        $this->storeEntities($drawing, $entities);
        $summary = $this->mappingService->mapDrawing($drawing->fresh('entities'));

        return [
            'drawing' => $drawing->fresh(),
            'mapping_summary' => $summary,
        ];
    }

    public function mapExistingCadSubmission(CadSubmission $submission): array
    {
        $drawing = MapDrawing::create([
            'application_id' => null,
            'original_file_path' => (string) $submission->stored_dwg_path,
            'dxf_file_path' => $submission->stored_dxf_path,
            'status' => 'uploaded',
            'mapping_status' => 'pending',
            'validation_status' => 'pending',
            'metadata_json' => [
                'cad_submission_id' => $submission->id,
                'original_filename' => $submission->original_filename,
            ],
        ]);

        $pythonFeatures = CadEntityFeature::where('cad_submission_id', $submission->id)
            ->orderBy('id')
            ->get();

        $entities = [];
        $featuresHaveUsableLayers = $pythonFeatures->contains(
            fn (CadEntityFeature $feature) => $feature->layer && $feature->layer !== '(none)'
        );

        if ($submission->stored_dxf_path && Storage::disk('local')->exists($submission->stored_dxf_path)) {
            $entities = $this->extractionService->extract(Storage::disk('local')->path($submission->stored_dxf_path));
        } elseif ($pythonFeatures->isNotEmpty() && $featuresHaveUsableLayers) {
            $entities = $pythonFeatures->map(function (CadEntityFeature $feature): array {
                $points = is_array($feature->points_xy) ? $feature->points_xy : [];
                return [
                    'handle' => (string) ($feature->entity_handle ?: uniqid('h_', true)),
                    'layer_name' => (string) ($feature->layer ?: '(none)'),
                    'entity_type' => (string) ($feature->entity_type ?: 'UNKNOWN'),
                    'points' => $points,
                    'bbox' => [
                        'min_x' => (float) ($feature->bbox_x0 ?? 0),
                        'min_y' => (float) ($feature->bbox_y0 ?? 0),
                        'max_x' => (float) ($feature->bbox_x1 ?? 0),
                        'max_y' => (float) ($feature->bbox_y1 ?? 0),
                    ],
                    'area' => (float) ($feature->area ?? 0),
                    'perimeter' => 0.0,
                    'is_closed' => (bool) $feature->is_closed,
                ];
            })->all();
        }

        $this->storeEntities($drawing, $entities);
        $summary = $this->mappingService->mapDrawing($drawing->fresh('entities'));
        $drawing->status = 'mapped';
        $drawing->save();

        return [
            'drawing' => $drawing->fresh(),
            'mapping_summary' => $summary,
        ];
    }

    private function convertDwgToDxf(int $drawingId, string $dwgAbsPath, string $storedRel): ?string
    {
        $workDirRel = 'uploads/map-approval/work/' . $drawingId;
        Storage::disk('local')->makeDirectory($workDirRel);
        $dxfRel = $workDirRel . '/source.dxf';
        $dxfAbs = Storage::disk('local')->path($dxfRel);

        $dwg2dxf = env('LIBREDWG_DWG2DXF') ?: trim((string) shell_exec('command -v dwg2dxf'));
        if (! $dwg2dxf || ! is_file($dwg2dxf)) {
            return null;
        }

        $proc = new Process([$dwg2dxf, $dwgAbsPath]);
        $proc->setTimeout(120);
        $proc->setWorkingDirectory(dirname($dxfAbs));
        $proc->run();

        if (! $proc->isSuccessful()) {
            return null;
        }

        $generated = dirname($dxfAbs) . '/' . pathinfo($dwgAbsPath, PATHINFO_FILENAME) . '.dxf';
        if (! is_file($generated)) {
            return null;
        }

        copy($generated, $dxfAbs);

        return $dxfRel;
    }

    private function storeEntities(MapDrawing $drawing, array $entities): void
    {
        MapEntity::where('map_drawing_id', $drawing->id)->delete();
        foreach ($entities as $entity) {
            MapEntity::create([
                'map_drawing_id' => $drawing->id,
                'handle' => $entity['handle'],
                'layer_name' => $entity['layer_name'],
                'processing_role' => $entity['matched_layer_name'] ?? null,
                'entity_type' => $entity['entity_type'],
                'geometry_json' => [
                    'points' => $entity['points'],
                    'text_content' => $entity['text_content'] ?? null,
                ],
                'bbox_json' => $entity['bbox'],
                'area' => $entity['area'],
                'perimeter' => $entity['perimeter'],
                'is_closed' => $entity['is_closed'],
                'mapping_status' => 'unmapped',
            ]);
        }

        $this->storeSyntheticPlotBoundary($drawing);
        $this->storeSyntheticFootprints($drawing);
    }

    private function storeSyntheticPlotBoundary(MapDrawing $drawing): void
    {
        $hasClosedPlot = $drawing->entities()
            ->get()
            ->contains(fn (MapEntity $entity) => $entity->is_closed && str_contains($this->normalizeLayerName($entity->layer_name), 'plot boundary'));
        if ($hasClosedPlot) {
            return;
        }

        $items = $drawing->entities()
            ->whereIn('entity_type', ['LINE', 'LWPOLYLINE', 'POLYLINE'])
            ->get()
            ->filter(function (MapEntity $entity) {
                $layer = $this->normalizeLayerName($entity->layer_name);

                return str_contains($layer, 'plot boundary')
                    || str_contains($layer, 'plot line')
                    || str_contains($layer, 'boundary wall')
                    || $layer === 'a wall';
            })
            ->values();

        if ($items->isEmpty()) {
            return;
        }

        $bounds = null;
        foreach (['plot boundary', 'plot line', 'boundary wall', 'a wall'] as $preferredLayer) {
            $boxes = $items
                ->filter(fn (MapEntity $entity) => $this->normalizeLayerName($entity->layer_name) === $preferredLayer)
                ->map(fn (MapEntity $entity) => $entity->bbox_json)
                ->filter(fn ($bbox) => is_array($bbox))
                ->all();
            if (! empty($boxes)) {
                $bounds = $this->mergeBoxes($boxes);
                break;
            }
        }
        if (! $bounds) {
            $boxes = $items->map(fn (MapEntity $entity) => $entity->bbox_json)->filter(fn ($bbox) => is_array($bbox))->all();
            if (empty($boxes)) {
                return;
            }
            $bounds = $this->mergeBoxes($boxes);
        }

        $width = max(0, $bounds['max_x'] - $bounds['min_x']);
        $height = max(0, $bounds['max_y'] - $bounds['min_y']);
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $points = [
            [$bounds['min_x'], $bounds['min_y']],
            [$bounds['max_x'], $bounds['min_y']],
            [$bounds['max_x'], $bounds['max_y']],
            [$bounds['min_x'], $bounds['max_y']],
        ];

        MapEntity::updateOrCreate(
            [
                'map_drawing_id' => $drawing->id,
                'handle' => 'synthetic_plot_' . md5(json_encode($bounds)),
            ],
            [
                'layer_name' => 'boundary wall',
                'entity_type' => 'LWPOLYLINE',
                'semantic_entity' => 'plot_boundary',
                'geometry_json' => [
                    'points' => $points,
                    'synthetic' => true,
                    'source' => 'recognized_boundary_layer_bbox',
                ],
                'bbox_json' => $bounds,
                'area' => round($width * $height, 4),
                'perimeter' => round(($width + $height) * 2, 4),
                'is_closed' => true,
                'confidence_score' => 85,
                'mapping_source' => 'boundary_layer_bbox',
                'mapping_status' => 'expert_verified',
            ]
        );
    }

    private function storeSyntheticFootprints(MapDrawing $drawing): void
    {
        $plotBbox = $drawing->entities()
            ->get()
            ->first(fn (MapEntity $entity) => $entity->is_closed && (
                $entity->semantic_entity === 'plot_boundary'
                || str_contains($this->normalizeLayerName($entity->layer_name), 'plot boundary')
            ))
            ?->bbox_json;

        $groups = $drawing->entities()
            ->whereIn('entity_type', ['LINE', 'LWPOLYLINE', 'POLYLINE'])
            ->get()
            ->groupBy('layer_name');

        foreach ($groups as $layerName => $items) {
            if (! str_contains($this->normalizeLayerName((string) $layerName), 'external walls')) {
                continue;
            }

            $fixedSemantic = $this->semanticFromFloorLayerName((string) $layerName);
            foreach ($this->externalWallPlanClusters($items, $plotBbox) as $index => $bounds) {
                $this->storeSyntheticPlanEntity($drawing, (string) $layerName, $bounds, $fixedSemantic, $index);
            }
        }
    }

    private function externalWallPlanClusters($items, ?array $plotBbox): array
    {
        $boxes = [];
        foreach ($items as $item) {
            $bbox = $item->bbox_json;
            if (! is_array($bbox)) {
                continue;
            }

            $minX = (float) ($bbox['min_x'] ?? INF);
            $minY = (float) ($bbox['min_y'] ?? INF);
            $maxX = (float) ($bbox['max_x'] ?? -INF);
            $maxY = (float) ($bbox['max_y'] ?? -INF);
            if (! is_finite($minX) || ! is_finite($minY) || ! is_finite($maxX) || ! is_finite($maxY)) {
                continue;
            }

            $width = max(0.0, $maxX - $minX);
            $height = max(0.0, $maxY - $minY);
            if ($width === 0.0 && $height === 0.0) {
                continue;
            }
            if (is_array($plotBbox)) {
                $plotWidth = max(1.0, (float) (($plotBbox['max_x'] ?? 0) - ($plotBbox['min_x'] ?? 0)));
                $plotHeight = max(1.0, (float) (($plotBbox['max_y'] ?? 0) - ($plotBbox['min_y'] ?? 0)));
                if ($width > ($plotWidth * 1.75) || $height > ($plotHeight * 1.75)) {
                    continue;
                }
            }

            $boxes[] = [
                'min_x' => $minX,
                'min_y' => $minY,
                'max_x' => $maxX,
                'max_y' => $maxY,
                'center_x' => ($minX + $maxX) / 2,
                'center_y' => ($minY + $maxY) / 2,
            ];
        }

        if (empty($boxes)) {
            return [];
        }

        usort($boxes, fn ($a, $b) => $a['center_x'] <=> $b['center_x']);

        $typicalWidth = is_array($plotBbox)
            ? max(1.0, (float) (($plotBbox['max_x'] ?? 0) - ($plotBbox['min_x'] ?? 0)))
            : max(1.0, $this->percentile(array_map(fn ($b) => max(1.0, $b['max_x'] - $b['min_x']), $boxes), 0.75));
        $gapThreshold = max(60.0, $typicalWidth * 0.35);

        $clusters = [];
        foreach ($boxes as $box) {
            $lastIdx = count($clusters) - 1;
            if ($lastIdx < 0) {
                $clusters[] = [$box];
                continue;
            }

            $lastBounds = $this->mergeBoxes($clusters[$lastIdx]);
            $gap = $box['min_x'] - $lastBounds['max_x'];
            if ($gap > $gapThreshold) {
                $clusters[] = [$box];
            } else {
                $clusters[$lastIdx][] = $box;
            }
        }

        $bounds = array_map(fn ($cluster) => $this->mergeBoxes($cluster), $clusters);
        $bounds = array_values(array_filter($bounds, function (array $box) use ($typicalWidth) {
            $width = max(0.0, $box['max_x'] - $box['min_x']);
            $height = max(0.0, $box['max_y'] - $box['min_y']);
            return $width >= ($typicalWidth * 0.35) && $height >= 30;
        }));

        usort($bounds, function (array $a, array $b) use ($plotBbox) {
            if (is_array($plotBbox)) {
                $aTouches = $this->bboxTouchesPlot($a, $plotBbox) ? 0 : 1;
                $bTouches = $this->bboxTouchesPlot($b, $plotBbox) ? 0 : 1;
                if ($aTouches !== $bTouches) {
                    return $aTouches <=> $bTouches;
                }
            }

            return $a['min_x'] <=> $b['min_x'];
        });

        return array_slice($bounds, 0, 4);
    }

    private function storeSyntheticPlanEntity(MapDrawing $drawing, string $layerName, array $bounds, ?string $fixedSemantic, int $index): void
    {
        $width = max(0, $bounds['max_x'] - $bounds['min_x']);
        $height = max(0, $bounds['max_y'] - $bounds['min_y']);
        if ($width <= 0 || $height <= 0) {
            return;
        }

        $semanticByIndex = [
            'ground_floor_covered_polygon',
            'first_floor_covered_polygon',
            'second_floor_covered_polygon',
            'basement_covered_polygon',
        ];
        $semantic = $fixedSemantic ?: ($semanticByIndex[$index] ?? null);
        $points = [
            [$bounds['min_x'], $bounds['min_y']],
            [$bounds['max_x'], $bounds['min_y']],
            [$bounds['max_x'], $bounds['max_y']],
            [$bounds['min_x'], $bounds['max_y']],
        ];

        MapEntity::updateOrCreate(
            [
                'map_drawing_id' => $drawing->id,
                'handle' => 'synthetic_plan_' . $index . '_' . md5($layerName . json_encode($bounds)),
            ],
            [
                'layer_name' => $layerName,
                'entity_type' => 'LWPOLYLINE',
                'semantic_entity' => $semantic,
                'geometry_json' => [
                    'points' => $points,
                    'synthetic' => true,
                    'source' => 'external_wall_spatial_plan_block',
                    'plan_index' => $index,
                ],
                'bbox_json' => [
                    'min_x' => $bounds['min_x'],
                    'min_y' => $bounds['min_y'],
                    'max_x' => $bounds['max_x'],
                    'max_y' => $bounds['max_y'],
                ],
                'area' => round($width * $height, 4),
                'perimeter' => round(($width + $height) * 2, 4),
                'is_closed' => true,
                'confidence_score' => 100,
                'mapping_source' => $fixedSemantic ? 'floor_layer_name' : 'spatial_plan_block',
                'mapping_status' => $semantic ? 'expert_verified' : 'needs_expert_review',
            ]
        );
    }

    private function semanticFromFloorLayerName(string $layerName): ?string
    {
        $normalized = $this->normalizeLayerName($layerName);

        return match (true) {
            str_contains($normalized, 'ground floor') => 'ground_floor_covered_polygon',
            str_contains($normalized, 'first floor') => 'first_floor_covered_polygon',
            str_contains($normalized, 'second floor') => 'second_floor_covered_polygon',
            str_contains($normalized, 'basement') => 'basement_covered_polygon',
            default => null,
        };
    }

    private function mergeBoxes(array $boxes): array
    {
        return [
            'min_x' => min(array_map(fn ($b) => $b['min_x'], $boxes)),
            'min_y' => min(array_map(fn ($b) => $b['min_y'], $boxes)),
            'max_x' => max(array_map(fn ($b) => $b['max_x'], $boxes)),
            'max_y' => max(array_map(fn ($b) => $b['max_y'], $boxes)),
        ];
    }

    private function percentile(array $values, float $p): float
    {
        sort($values);
        if (empty($values)) {
            return 1.0;
        }
        $idx = max(0, min(count($values) - 1, (int) floor((count($values) - 1) * $p)));
        return (float) $values[$idx];
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

    private function bboxTouchesPlot(array $bbox, array $plotBbox): bool
    {
        $paddingX = max(1.0, (($plotBbox['max_x'] ?? 0) - ($plotBbox['min_x'] ?? 0)) * 0.05);
        $paddingY = max(1.0, (($plotBbox['max_y'] ?? 0) - ($plotBbox['min_y'] ?? 0)) * 0.05);

        return
            (($bbox['max_x'] ?? -INF) >= (($plotBbox['min_x'] ?? INF) - $paddingX)) &&
            (($bbox['min_x'] ?? INF) <= (($plotBbox['max_x'] ?? -INF) + $paddingX)) &&
            (($bbox['max_y'] ?? -INF) >= (($plotBbox['min_y'] ?? INF) - $paddingY)) &&
            (($bbox['min_y'] ?? INF) <= (($plotBbox['max_y'] ?? -INF) + $paddingY));
    }
}
