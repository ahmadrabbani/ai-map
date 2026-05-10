<?php
namespace App\Services;

use App\Models\CadExpertLabel;
use App\Models\CadSubmission;
use App\Models\CadTrainingLabel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * End-to-end CAD compliance runner:
 * - DWG -> DXF
 * - DXF -> polygon discovery using --list-polys
 * - Auto-select likely plot + floor handles
 * - DXF -> overlay PDF + rule evaluation using selected handles/layers
 */
class CadComplianceService
{
    public function processSubmission(CadSubmission $submission, string $dwgAbsPath, array $options = []): array
    {
        $workDirRel = 'uploads/cad/work/' . $submission->id;
        Storage::disk('local')->makeDirectory($workDirRel);
        $workDir = Storage::disk('local')->path($workDirRel);

        /*
    |--------------------------------------------------------------------------
    | 1) Convert DWG -> DXF
    |--------------------------------------------------------------------------
    */
        try {
            $useStoredDxf = (bool) ($options['use_stored_dxf'] ?? false);
            $storedDxfRel = $submission->stored_dxf_path;

            if ($useStoredDxf && $storedDxfRel && Storage::disk('local')->exists($storedDxfRel)) {
                $dxfAbsPath = Storage::disk('local')->path($storedDxfRel);
            } else {
                $dxfAbsPath = $workDir . '/source.dxf';

                $this->convertDwgToDxf($dwgAbsPath, $dxfAbsPath, $workDir);

                $storedDxfRel                = $workDirRel . '/source.dxf';
                $submission->stored_dxf_path = $storedDxfRel;
                $submission->save();
            }
        } catch (\Throwable $e) {
            $report = [
                'status'                => 'needs_expert_review',
                'report_type'           => 'dwg_to_dxf_failed',
                'error_code'            => 'dwg_to_dxf_failed',
                'message'               => $e->getMessage(),
                'recommended_next_step' => [
                    'action'       => 'check_converter',
                    'instructions' => 'Verify DWG to DXF converter path, file permissions, and DWG compatibility.',
                ],
            ];

            Log::error('CAD report generated: DWG to DXF failed', [
                'submission_id' => $submission->id,
                'report'        => $report,
            ]);

            return $this->saveAnalysisReport($submission, $report);
        }

        /*
    |--------------------------------------------------------------------------
    | 2) Prepare paths
    |--------------------------------------------------------------------------
    */
        $rulesPath    = $this->resolveRulesPath($submission);
        $layersPath   = is_file(base_path('rules/layer_35.json'))
            ? base_path('rules/layer_35.json')
            : base_path('rules/layers.json');
        $pythonScript = base_path('scripts/process_cad_rules.py');

        $overlayRel = $workDirRel . '/overlay.pdf';
        $overlayAbs = Storage::disk('local')->path($overlayRel);

        $drawingRel = $workDirRel . '/drawing.pdf';
        $drawingAbs = Storage::disk('local')->path($drawingRel);

        /*
    |--------------------------------------------------------------------------
    | 3) First Python pass: polygon discovery
    |--------------------------------------------------------------------------
    */
        $baseLayerMapJson = $this->getLayerMapJson($submission);

        $listArgs = [
            $this->pythonBin(),
            $pythonScript,
            '--dxf', $dxfAbsPath,
            '--rules', $rulesPath,
            '--layers-json', $layersPath,
            '--list-polys',
            '--layer-map-json', $baseLayerMapJson,
        ];

        if (! empty($options['unit'])) {
            $listArgs[] = '--unit';
            $listArgs[] = (string) $options['unit'];
        }

        Log::info('CAD list-polys Python args', [
            'submission_id' => $submission->id,
            'args'          => $listArgs,
        ]);

        try {
            $listProc = new Process($listArgs);
            $listProc->setTimeout(300);
            $listProc->run();

            if (! $listProc->isSuccessful()) {
                throw new \RuntimeException(
                    trim($listProc->getErrorOutput() ?: $listProc->getOutput())
                );
            }

            $polyJson   = trim($listProc->getOutput());
            $polyResult = json_decode($polyJson, true);

            if (! is_array($polyResult)) {
                throw new \RuntimeException(
                    'Python --list-polys did not return valid JSON. Output: ' .
                    substr($polyJson, 0, 1000)
                );
            }
        } catch (\Throwable $e) {
            $report = [
                'status'                => 'needs_expert_review',
                'report_type'           => 'polygon_discovery_failed',
                'error_code'            => 'list_polys_failed',
                'message'               => $e->getMessage(),
                'list_args_debug'       => $listArgs,
                'recommended_next_step' => [
                    'action'       => 'check_python_list_polys',
                    'instructions' => 'Run the generated list-polys command manually and verify Python dependencies, DXF parsing, and layers.json.',
                ],
            ];

            Log::error('CAD report generated: list-polys failed', [
                'submission_id' => $submission->id,
                'report'        => $report,
            ]);

            return $this->saveAnalysisReport($submission, $report, $overlayAbs, $drawingAbs);
        }

        /*
    |--------------------------------------------------------------------------
    | 4) Extract handles from polygons
    |--------------------------------------------------------------------------
    */
        $autoHandles = $this->extractHandlesFromPolys($polyResult);

        Log::info('CAD auto extracted handles', [
            'submission_id' => $submission->id,
            'auto_handles'  => $autoHandles,
            'poly_count'    => $polyResult['count'] ?? null,
        ]);

        if (! empty($options['list_polys_only'])) {
            $report = [
                'status'                => 'needs_expert_review',
                'report_type'           => 'polygon_discovery_only',
                'message'               => 'Polygon discovery completed. Expert can now select plot and floor handles.',
                'polygon_discovery'     => [
                    'count'           => $polyResult['count'] ?? null,
                    'auto_handles'    => $autoHandles,
                    'sample_polygons' => array_slice($polyResult['polys'] ?? [], 0, 100),
                ],
                'recommended_next_step' => [
                    'action'       => 'select_handles',
                    'instructions' => 'Select actual plot boundary handle and ground floor footprint handle, then rerun compliance.',
                ],
            ];

            return $this->saveAnalysisReport($submission, $report, $overlayAbs, $drawingAbs);
        }

        /*
    |--------------------------------------------------------------------------
    | 5) Manual options override auto-detected values
    |--------------------------------------------------------------------------
    */
        $plotHandle    = $options['plot_handle'] ?? ($autoHandles['plot_handle'] ?? null);
        $floorHandles  = $options['floor_handles'] ?? ($autoHandles['floor_handles'] ?? []);
        $plotLayer     = $options['plot_layer'] ?? ($autoHandles['plot_layer'] ?? null);
        $buildingLayer = $options['building_layer'] ?? ($autoHandles['building_layer'] ?? null);

        /*
    |--------------------------------------------------------------------------
    | 6) DB labels override auto values only when use_labels=true
    |--------------------------------------------------------------------------
    */
        if (! empty($options['use_labels'])) {
            $label    = CadExpertLabel::where('cad_submission_id', $submission->id)->first();
            $training = CadTrainingLabel::where('cad_submission_id', $submission->id)->first();

            $labelPlotHandle = $label?->plot_entity_handle ?: $training?->plot_boundary_handle;
            if ($labelPlotHandle) {
                $plotHandle = (string) $labelPlotHandle;
            }

            $labelFloorHandles = $this->normalizeFloorHandles($label, $training);
            if (! empty($labelFloorHandles)) {
                $floorHandles = $labelFloorHandles;
            }

            $labelPlotLayer = $label?->plot_layer;
            if (! $labelPlotLayer && $training && is_array($training->layer_map)) {
                $labelPlotLayer = $training->layer_map['plot'] ?? null;
            }
            if ($labelPlotLayer) {
                $plotLayer = (string) $labelPlotLayer;
            }

            $labelBuildingLayer = $label?->building_layer;
            if (! $labelBuildingLayer && $training && is_array($training->layer_map)) {
                $labelBuildingLayer = $training->layer_map['building'] ?? null;
            }
            if ($labelBuildingLayer) {
                $buildingLayer = (string) $labelBuildingLayer;
            }
        }

        /*
    |--------------------------------------------------------------------------
    | 7) Always generate expert report if plot handle is missing
    |--------------------------------------------------------------------------
    */
        if (empty($plotHandle)) {
            $report = [
                'status'                => 'needs_expert_review',
                'report_type'           => 'expert_review_required',
                'error_code'            => 'plot_handle_not_detected',
                'message'               => 'System could not confidently detect the actual plot polygon. Expert marking is required.',
                'polygon_discovery'     => [
                    'count'           => $polyResult['count'] ?? null,
                    'auto_handles'    => $autoHandles,
                    'sample_polygons' => array_slice($polyResult['polys'] ?? [], 0, 100),
                ],
                'recommended_next_step' => [
                    'action'       => 'open_expert_marking',
                    'instructions' => 'Select the actual plot boundary and ground floor footprint from the drawing, then rerun compliance.',
                ],
            ];

            Log::warning('CAD expert report generated: plot handle missing', [
                'submission_id' => $submission->id,
                'report'        => $report,
            ]);

            return $this->saveAnalysisReport($submission, $report, $overlayAbs, $drawingAbs);
        }

        /*
    |--------------------------------------------------------------------------
    | 8) Build detected layer map
    |--------------------------------------------------------------------------
    */
        $finalLayerMapJson = $this->buildDetectedLayerMapJson($submission, $plotLayer, $buildingLayer);

        /*
    |--------------------------------------------------------------------------
    | 9) Second Python pass: final compliance analysis
    |--------------------------------------------------------------------------
    */
        $args = [
            $this->pythonBin(),
            $pythonScript,
            '--dxf', $dxfAbsPath,
            '--rules', $rulesPath,
            '--layers-json', $layersPath,
            '--out', $overlayAbs,
            '--drawing-out', $drawingAbs,
            '--layer-map-json', $finalLayerMapJson,
            '--plot-handle', (string) $plotHandle,
            '--min-confidence', (string) ($options['min_confidence'] ?? '0'),
        ];

        if (! empty($plotLayer)) {
            $args[] = '--plot-layer';
            $args[] = (string) $plotLayer;
        }

        if (! empty($floorHandles)) {
            $args[] = '--floor-handles';
            $args[] = json_encode($floorHandles);
        }

        if (! empty($buildingLayer)) {
            $args[] = '--building-layer';
            $args[] = (string) $buildingLayer;
        }

        if (! empty($options['front_side'])) {
            $args[] = '--front-side';
            $args[] = (string) $options['front_side'];
        }

        if (! empty($options['unit'])) {
            $args[] = '--unit';
            $args[] = (string) $options['unit'];
        }

        if (! empty($options['allow_heuristic_fallback'])) {
            $args[] = '--allow-heuristic-fallback';
        }

        Log::info('CAD final Python args', [
            'submission_id'  => $submission->id,
            'args'           => $args,
            'plot_handle'    => $plotHandle,
            'floor_handles'  => $floorHandles,
            'plot_layer'     => $plotLayer,
            'building_layer' => $buildingLayer,
            'layer_map_json' => $finalLayerMapJson,
        ]);

        try {
            $proc = new Process($args);
            $proc->setTimeout(300);
            $proc->run();

            if (! $proc->isSuccessful()) {
                throw new \RuntimeException(
                    trim($proc->getErrorOutput() ?: $proc->getOutput())
                );
            }

            $json   = trim($proc->getOutput());
            $result = json_decode($json, true);

            if (! is_array($result)) {
                throw new \RuntimeException(
                    'Python did not return valid JSON. Output: ' .
                    substr($json, 0, 1000)
                );
            }
        } catch (\Throwable $e) {
            $report = [
                'status'                => 'needs_expert_review',
                'report_type'           => 'runtime_exception',
                'error_code'            => 'python_runtime_exception',
                'message'               => $e->getMessage(),
                'polygon_discovery'     => [
                    'count'           => $polyResult['count'] ?? null,
                    'auto_handles'    => $autoHandles,
                    'sample_polygons' => array_slice($polyResult['polys'] ?? [], 0, 100),
                ],
                'final_args_debug'      => $args,
                'recommended_next_step' => [
                    'action'       => 'open_expert_marking',
                    'instructions' => 'Use polygon discovery result to manually mark plot and ground floor footprint.',
                ],
            ];

            Log::error('CAD expert report generated after final Python exception', [
                'submission_id' => $submission->id,
                'report'        => $report,
            ]);

            return $this->saveAnalysisReport($submission, $report, $overlayAbs, $drawingAbs);
        }

        /*
    |--------------------------------------------------------------------------
    | 10) Save Python error as expert report
    |--------------------------------------------------------------------------
    */
        if (($result['status'] ?? null) !== 'ok') {
            $report = [
                'status'                => 'needs_expert_review',
                'report_type'           => 'python_analysis_error',
                'error_code'            => $result['error_code'] ?? 'python_analysis_error',
                'message'               => $result['message'] ?? 'Python CAD analysis returned an error.',
                'python_result'         => $result,
                'polygon_discovery'     => [
                    'count'           => $polyResult['count'] ?? null,
                    'auto_handles'    => $autoHandles,
                    'sample_polygons' => array_slice($polyResult['polys'] ?? [], 0, 100),
                ],
                'final_args_debug'      => $args,
                'recommended_next_step' => [
                    'action'       => 'open_expert_marking',
                    'instructions' => 'Verify selected plot and floor handles, then rerun compliance.',
                ],
            ];

            Log::warning('CAD expert report generated: final analysis failed', [
                'submission_id' => $submission->id,
                'report'        => $report,
            ]);

            return $this->saveAnalysisReport($submission, $report, $overlayAbs, $drawingAbs);
        }

        /*
    |--------------------------------------------------------------------------
    | 11) Successful report
    |--------------------------------------------------------------------------
    */
        $result['polygon_discovery'] = [
            'auto_handles'        => $autoHandles,
            'polygon_count'       => $polyResult['count'] ?? null,
            'plot_handle_used'    => $plotHandle,
            'floor_handles_used'  => $floorHandles,
            'plot_layer_used'     => $plotLayer,
            'building_layer_used' => $buildingLayer,
        ];

        return $this->saveAnalysisReport($submission, $result, $overlayAbs, $drawingAbs);
    }

    private function resolveRulesPath(CadSubmission $submission): string
    {
        $rulesetKey = (string) ($submission->ruleset_key ?: '5_marla_residential');

        $map = [
            '5_marla_residential' => base_path('rules/5MRulesJSON.json'),
            'residential_building_approval' => base_path('rules/approval_rules_meta.json'),
        ];

        $path = $map[$rulesetKey] ?? null;

        if ($path && is_file($path)) {
            return $path;
        }

        return base_path('rules/5MRulesJSON.json');
    }

    private function saveAnalysisReport(
        CadSubmission $submission,
        array $report,
        ?string $overlayAbs = null,
        ?string $drawingAbs = null
    ): array {
        $submission->analysis_result = $report;

        $publicDir = 'cad_reports/' . $submission->id;

        if ($overlayAbs && file_exists($overlayAbs)) {
            Storage::disk('public')->makeDirectory($publicDir);

            $publicPdfPath = $publicDir . '/overlay.pdf';
            Storage::disk('public')->put($publicPdfPath, file_get_contents($overlayAbs));

            $submission->overlay_pdf_path = $publicPdfPath;
        }

        if ($drawingAbs && file_exists($drawingAbs)) {
            Storage::disk('public')->makeDirectory($publicDir);

            $publicDrawingPath = $publicDir . '/drawing.pdf';
            Storage::disk('public')->put($publicDrawingPath, file_get_contents($drawingAbs));

            $submission->drawing_pdf_path = $publicDrawingPath;
        }

        $submission->save();

        return $report;
    }

    private function extractHandlesFromPolys(array $polyResult): array
    {
        $polys = $polyResult['polys'] ?? [];

        if (! is_array($polys) || empty($polys)) {
            return [
                'plot_handle'    => null,
                'floor_handles'  => [],
                'plot_layer'     => null,
                'building_layer' => null,
                'debug'          => ['reason' => 'No polygons found from --list-polys'],
            ];
        }

        $usable      = [];
        $ignoredHuge = [];

        foreach ($polys as $poly) {
            if (! is_array($poly)) {
                continue;
            }

            $handle         = (string) ($poly['handle'] ?? '');
            $area           = (float) ($poly['area'] ?? $poly['area_sqft'] ?? 0);
            $rawLayer       = strtolower((string) ($poly['raw_layer'] ?? ''));
            $standardLayer  = strtoupper((string) ($poly['standard_layer'] ?? ''));
            $roleHint       = strtolower((string) ($poly['role_hint'] ?? ''));
            $bboxW          = (float) ($poly['bbox_w'] ?? 0);
            $bboxH          = (float) ($poly['bbox_h'] ?? 0);
            $rectangularity = (float) ($poly['rectangularity'] ?? 0);

            if ($handle === '' || $area <= 0) {
                continue;
            }

            $isHugeSheet =
            $area > 5000 ||
            ($bboxW > 100 && $bboxH > 100) ||
            str_contains($rawLayer, 'sheet') ||
            str_contains($rawLayer, 'title') ||
            str_contains($rawLayer, 'border') ||
            str_contains($rawLayer, 'frame') ||
            str_contains($rawLayer, 'table') ||
            str_contains($rawLayer, 'schedule') ||
            str_contains($rawLayer, 'elevation') ||
            str_contains($rawLayer, 'section') ||
            str_contains($rawLayer, 'detail');

            if ($isHugeSheet) {
                $ignoredHuge[] = [
                    'handle'         => $handle,
                    'area'           => $area,
                    'raw_layer'      => $rawLayer,
                    'standard_layer' => $standardLayer,
                    'bbox_w'         => $bboxW,
                    'bbox_h'         => $bboxH,
                ];
                continue;
            }

            $plotScore  = 0;
            $floorScore = 0;

            if ($area >= 650 && $area <= 2200) {
                $plotScore += 50;
            } elseif ($area >= 400 && $area <= 3000) {
                $plotScore += 25;
            }

            if (in_array($standardLayer, ['SITE-PL', 'SITE-BW'], true)) {
                $plotScore += 40;
            }

            if (
                str_contains($rawLayer, 'plot') ||
                str_contains($rawLayer, 'site') ||
                str_contains($rawLayer, 'boundary') ||
                str_contains($rawLayer, 'boundry') ||
                str_contains($rawLayer, 'b/w') ||
                str_contains($rawLayer, 'bw') ||
                $roleHint === 'plot_candidate'
            ) {
                $plotScore += 35;
            }

            if ($rectangularity >= 0.80) {
                $plotScore += 10;
            }

            if ($area >= 200 && $area <= 1600) {
                $floorScore += 35;
            }

            if (in_array($standardLayer, ['GF-WE', 'FF-WE', 'SF-WE', 'BSM-WE'], true)) {
                $floorScore += 45;
            }

            if (
                str_contains($rawLayer, 'wall') ||
                str_contains($rawLayer, 'building') ||
                str_contains($rawLayer, 'floor') ||
                str_contains($rawLayer, 'ground') ||
                str_contains($rawLayer, 'gf') ||
                str_contains($rawLayer, 'external') ||
                $roleHint === 'floor_candidate'
            ) {
                $floorScore += 35;
            }

            $poly['_plot_score']  = $plotScore;
            $poly['_floor_score'] = $floorScore;
            $poly['_area']        = $area;

            $usable[] = $poly;
        }

        $plotCandidates = array_values(array_filter($usable, function ($p) {
            return ((int) ($p['_plot_score'] ?? 0)) > 0;
        }));

        usort($plotCandidates, function ($a, $b) {
            $scoreCompare = ((int) ($b['_plot_score'] ?? 0)) <=> ((int) ($a['_plot_score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return ((float) ($b['_area'] ?? 0)) <=> ((float) ($a['_area'] ?? 0));
        });

        $plot = $plotCandidates[0] ?? null;

        if (! $plot) {
            $fallbackPlotCandidates = array_values(array_filter($usable, function ($p) {
                $area = (float) ($p['_area'] ?? 0);
                return $area >= 400 && $area <= 3000;
            }));

            usort($fallbackPlotCandidates, function ($a, $b) {
                return ((float) ($b['_area'] ?? 0)) <=> ((float) ($a['_area'] ?? 0));
            });

            $plot = $fallbackPlotCandidates[0] ?? null;
        }

        $plotHandle = $plot['handle'] ?? null;
        $plotArea   = (float) ($plot['_area'] ?? 0);

        $floorCandidates = array_values(array_filter($usable, function ($p) use ($plotHandle, $plotArea) {
            if (($p['handle'] ?? null) === $plotHandle) {
                return false;
            }

            $area = (float) ($p['_area'] ?? 0);

            if ($plotArea > 0 && $area >= ($plotArea * 0.98)) {
                return false;
            }

            return ((int) ($p['_floor_score'] ?? 0)) > 0 || ($area >= 150 && $area <= 1800);
        }));

        usort($floorCandidates, function ($a, $b) {
            $scoreCompare = ((int) ($b['_floor_score'] ?? 0)) <=> ((int) ($a['_floor_score'] ?? 0));
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return ((float) ($b['_area'] ?? 0)) <=> ((float) ($a['_area'] ?? 0));
        });

        $ground  = $floorCandidates[0] ?? null;

        return [
            'plot_handle'    => $plotHandle,
            'floor_handles'  => $ground ? [
                [
                    'floor'  => 0,
                    'handle' => (string) $ground['handle'],
                ],
            ] : [],
            'plot_layer'     => $plot['raw_layer'] ?? null,
            'building_layer' => $ground['raw_layer'] ?? null,
            'debug'          => [
                'usable_count'           => count($usable),
                'plot_candidates_count'  => count($plotCandidates),
                'floor_candidates_count' => count($floorCandidates),
                'selected_plot'          => $plot,
                'selected_ground_floor'  => $ground,
                'ignored_huge_count'     => count($ignoredHuge),
                'ignored_huge_sample'    => array_slice($ignoredHuge, 0, 10),
                'top_polygons_sample'    => array_slice($polys, 0, 30),
            ],
        ];
    }

    private function buildDetectedLayerMapJson(CadSubmission $submission, ?string $plotLayer, ?string $buildingLayer): string
    {
        $base = json_decode($this->getLayerMapJson($submission), true);

        if (! is_array($base)) {
            $base = [];
        }

        if ($plotLayer) {
            $base[$plotLayer] = [
                'tag'    => 'plot_boundary',
                'source' => 'auto_detected',
            ];
        }

        if ($buildingLayer) {
            $base[$buildingLayer] = [
                'tag'    => 'ground_floor',
                'source' => 'auto_detected',
            ];
        }

        return json_encode($base);
    }

    private function buildLabelArgs(CadSubmission $submission, array $options): array
    {
        $args = [];

        if (! ($options['use_labels'] ?? false)) {
            return $args;
        }

        $label    = CadExpertLabel::where('cad_submission_id', $submission->id)->first();
        $training = CadTrainingLabel::where('cad_submission_id', $submission->id)->first();

        $plotHandle = $label?->plot_entity_handle ?: $training?->plot_boundary_handle;
        if ($plotHandle) {
            $args[] = '--plot-handle';
            $args[] = (string) $plotHandle;
        }

        $plotLayer = $label?->plot_layer;
        if (! $plotLayer && $training && is_array($training->layer_map)) {
            $plotLayer = $training->layer_map['plot'] ?? null;
        }

        if ($plotLayer) {
            $args[] = '--plot-layer';
            $args[] = (string) $plotLayer;
        }

        $buildingLayer = $label?->building_layer;
        if (! $buildingLayer && $training && is_array($training->layer_map)) {
            $buildingLayer = $training->layer_map['building'] ?? null;
        }

        if ($buildingLayer) {
            $args[] = '--building-layer';
            $args[] = (string) $buildingLayer;
        }

        $floorHandles = $this->normalizeFloorHandles($label, $training);
        if (! empty($floorHandles)) {
            $args[] = '--floor-handles';
            $args[] = json_encode($floorHandles);
        }

        $frontSide = $this->normalizeFrontSide($label?->front_side, $training?->front_side);
        if ($frontSide) {
            $args[] = '--front-side';
            $args[] = $frontSide;
        }

        return $args;
    }

    private function normalizeFloorHandles(?CadExpertLabel $label, ?CadTrainingLabel $training): array
    {
        $handles = [];

        if ($label && $label->building_entity_handle) {
            $handles[] = ['floor' => 0, 'handle' => (string) $label->building_entity_handle];
        }

        if ($training) {
            $floorHandles = $training->floor_handles;

            if (is_array($floorHandles)) {
                $isList = array_keys($floorHandles) === range(0, count($floorHandles) - 1);

                if ($isList) {
                    foreach ($floorHandles as $item) {
                        if (! is_array($item)) {
                            continue;
                        }

                        $handle = $item['handle'] ?? null;
                        if (! $handle) {
                            continue;
                        }

                        $floor     = (int) ($item['floor'] ?? 0);
                        $handles[] = ['floor' => $floor, 'handle' => (string) $handle];
                    }
                } else {
                    foreach ($floorHandles as $key => $handle) {
                        if (! is_string($handle) || $handle === '') {
                            continue;
                        }

                        $floor = $this->floorKeyToIndex((string) $key);
                        if ($floor === null) {
                            continue;
                        }

                        $handles[] = ['floor' => $floor, 'handle' => $handle];
                    }
                }
            }

            if (! $handles && $training->building_footprint_handle) {
                $handles[] = ['floor' => 0, 'handle' => (string) $training->building_footprint_handle];
            }
        }

        $seen    = [];
        $deduped = [];

        foreach ($handles as $item) {
            $key = $item['floor'] . ':' . strtoupper((string) $item['handle']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[]  = $item;
        }

        return $deduped;
    }

    private function floorKeyToIndex(string $key): ?int
    {
        $k = strtolower(trim($key));

        if ($k === 'ground' || $k === 'gf' || $k === 'floor0' || $k === '0') {
            return 0;
        }

        if ($k === 'first' || $k === 'ff' || $k === 'floor1' || $k === '1') {
            return 1;
        }

        if ($k === 'second' || $k === 'sf' || $k === 'floor2' || $k === '2') {
            return 2;
        }

        if ($k === 'third' || $k === 'tf' || $k === 'floor3' || $k === '3') {
            return 3;
        }

        if (is_numeric($k)) {
            return (int) $k;
        }

        return null;
    }

    private function normalizeFrontSide(?string $expertFrontSide, ?string $trainingFrontSide): ?string
    {
        $expert = match ($expertFrontSide) {
            'north', 'south', 'east', 'west' => $expertFrontSide,
            default => null,
        };

        if ($expert) {
            return $expert;
        }

        return match ($trainingFrontSide) {
            'top'    => 'north',
            'bottom' => 'south',
            'right'  => 'east',
            'left'   => 'west',
            default  => null,
        };
    }

    private function getLayerMapJson(CadSubmission $submission): string
    {
        $label = CadExpertLabel::where('cad_submission_id', $submission->id)->first();

        if ($label && $label->layer_map_json) {
            if (is_array($label->layer_map_json)) {
                return json_encode($label->layer_map_json);
            }

            return (string) $label->layer_map_json;
        }

        $training = CadTrainingLabel::where('cad_submission_id', $submission->id)->first();

        if ($training && is_array($training->layer_map) && ! empty($training->layer_map)) {
            return json_encode($training->layer_map);
        }

        return '{}';
    }

    private function convertDwgToDxf(string $dwgAbs, string $dxfAbs, string $workDir): void
    {
        $oda     = env('ODA_CONVERTER');
        $dwg2dxf = env('LIBREDWG_DWG2DXF');

        if (! $dwg2dxf || ! is_file($dwg2dxf) || ! is_executable($dwg2dxf)) {
            foreach (['/usr/bin/dwg2dxf', '/usr/local/bin/dwg2dxf', '/opt/homebrew/bin/dwg2dxf'] as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    $dwg2dxf = $candidate;
                    break;
                }
            }

            if (! $dwg2dxf || ! is_file($dwg2dxf) || ! is_executable($dwg2dxf)) {
                try {
                    $which = new Process(['bash', '-lc', 'command -v dwg2dxf || true']);
                    $which->setTimeout(10);
                    $which->run();

                    $p = trim($which->getOutput());

                    if ($p && is_file($p) && is_executable($p)) {
                        $dwg2dxf = $p;
                    }
                } catch (\Throwable $e) {
                    // ignore lookup failure
                }
            }
        }

        if ($dwg2dxf && is_file($dwg2dxf) && is_executable($dwg2dxf)) {
            $p = new Process([$dwg2dxf, '-o', $dxfAbs, $dwgAbs]);
            $p->setTimeout(300);
            $p->run();

            if (! $p->isSuccessful()) {
                throw new \RuntimeException('dwg2dxf failed: ' . trim($p->getErrorOutput() ?: $p->getOutput()));
            }

            if (! file_exists($dxfAbs)) {
                throw new \RuntimeException('DXF not created by dwg2dxf.');
            }

            return;
        }

        if ($oda) {
            $inDir  = $workDir . '/in';
            $outDir = $workDir . '/out';

            @mkdir($inDir, 0775, true);
            @mkdir($outDir, 0775, true);

            copy($dwgAbs, $inDir . '/source.dwg');

            $p = new Process([
                $oda,
                $inDir,
                $outDir,
                'source.dwg',
                'DXF',
                'ACAD2013',
                '0',
                '1',
            ]);

            $p->setTimeout(300);
            $p->run();

            if (! $p->isSuccessful()) {
                throw new \RuntimeException('ODAFileConverter failed: ' . trim($p->getErrorOutput() ?: $p->getOutput()));
            }

            $candidate = $outDir . '/source.dxf';

            if (! file_exists($candidate)) {
                $found     = glob($outDir . '/**/source.dxf', GLOB_BRACE);
                $candidate = $found[0] ?? null;
            }

            if (! $candidate || ! file_exists($candidate)) {
                throw new \RuntimeException('ODAFileConverter did not produce source.dxf in expected output folder.');
            }

            copy($candidate, $dxfAbs);

            return;
        }

        $hint = "No DWG->DXF converter found.\n\n" .
            "Option A: Install LibreDWG and ensure dwg2dxf is on PATH or set LIBREDWG_DWG2DXF in .env\n" .
            "Option B: Install ODA File Converter and set ODA_CONVERTER in .env.\n";

        throw new \RuntimeException($hint);
    }

    private function pythonBin(): string
    {
        $configured = env('PYTHON_BIN');

        if (is_string($configured) && $configured !== '' && is_file($configured) && is_executable($configured)) {
            return $configured;
        }

        return 'python3';
    }
}
