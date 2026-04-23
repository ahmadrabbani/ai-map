<?php

namespace App\Services;

use App\Models\CadExpertLabel;
use App\Models\CadSubmission;
use App\Models\CadTrainingLabel;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * End-to-end CAD compliance runner:
 * - DWG -> DXF (via ODA converter or LibreDWG)
 * - DXF -> overlay PDF (via Python)
 * - Rule evaluation using rules JSON
 */
class CadComplianceService
{
    /**
     * Configure paths using .env:
     *  - ODA_CONVERTER (optional): full path to ODAFileConverter executable
     *  - LIBREDWG_DWG2DXF (optional): full path to dwg2dxf
     */

    public function processSubmission(CadSubmission $submission, string $dwgAbsPath, array $options = []): array
    {
        $workDirRel = 'uploads/cad/work/' . $submission->id;
        Storage::disk('local')->makeDirectory($workDirRel);
        $workDir = Storage::disk('local')->path($workDirRel);

        // 1) Convert DWG -> DXF
        $useStoredDxf = (bool) ($options['use_stored_dxf'] ?? false);
        $storedDxfRel = $submission->stored_dxf_path;
        if ($useStoredDxf && $storedDxfRel && Storage::disk('local')->exists($storedDxfRel)) {
            $dxfAbsPath = Storage::disk('local')->path($storedDxfRel);
        } else {
            $dxfAbsPath = $workDir . '/source.dxf';
            $this->convertDwgToDxf($dwgAbsPath, $dxfAbsPath, $workDir);

            // Store DXF path in storage for later download if needed
            $storedDxfRel = $workDirRel . '/source.dxf';
            $submission->stored_dxf_path = $storedDxfRel;
            $submission->save();
        }

        // 2) Run python analysis (also generates overlay PDF)
        $rulesPath = base_path('rules/5MRulesJSON.json');
        $pythonScript = base_path('scripts/process_cad_rules.py');
        $overlayRel = $workDirRel . '/overlay.pdf';
        $overlayAbs = Storage::disk('local')->path($overlayRel);
        $drawingRel = $workDirRel . '/drawing.pdf';
        $drawingAbs = Storage::disk('local')->path($drawingRel);

        $args = [
            $this->pythonBin(),
            $pythonScript,
            '--dxf', $dxfAbsPath,
            '--rules', $rulesPath,
            '--out', $overlayAbs,
            '--drawing-out', $drawingAbs,
        ];
        $args = array_merge($args, $this->buildLabelArgs($submission, $options));

        $proc = new Process($args);
        $proc->setTimeout(300);
        $proc->run();

        if (!$proc->isSuccessful()) {
            Log::error('process_cad_rules.py failed', [
                'stderr' => $proc->getErrorOutput(),
                'stdout' => $proc->getOutput(),
            ]);
            throw new \RuntimeException('Python CAD analysis failed: ' . trim($proc->getErrorOutput() ?: $proc->getOutput()));
        }

        $json = trim($proc->getOutput());
        $result = json_decode($json, true);
        if (!is_array($result)) {
            throw new \RuntimeException('Python did not return valid JSON. Output: ' . substr($json, 0, 500));
        }
        if (($result['status'] ?? null) !== 'ok') {
            $message = $result['message'] ?? 'Python CAD analysis returned an error.';
            throw new \RuntimeException($message);
        }

        // 3) Move overlay PDF to public disk for easy viewing
        $publicDir = 'cad_reports/' . $submission->id;
        Storage::disk('public')->makeDirectory($publicDir);
        $publicPdfPath = $publicDir . '/overlay.pdf';
        if (!file_exists($overlayAbs)) {
            throw new \RuntimeException('Overlay PDF was not generated at: ' . $overlayAbs);
        }
        Storage::disk('public')->put($publicPdfPath, file_get_contents($overlayAbs));

        $submission->overlay_pdf_path = $publicPdfPath;
        if (file_exists($drawingAbs)) {
            $publicDrawingPath = $publicDir . '/drawing.pdf';
            Storage::disk('public')->put($publicDrawingPath, file_get_contents($drawingAbs));
            $submission->drawing_pdf_path = $publicDrawingPath;
        }
        $submission->analysis_result = $result;
        $submission->save();

        return $result;
    }

    private function buildLabelArgs(CadSubmission $submission, array $options): array
    {
        $args = ['--layer-map-json', $this->getLayerMapJson($submission)];
        if (!($options['use_labels'] ?? false)) {
            return $args;
        }

        $label = CadExpertLabel::where('cad_submission_id', $submission->id)->first();
        $training = CadTrainingLabel::where('cad_submission_id', $submission->id)->first();

        $plotHandle = $label?->plot_entity_handle ?: $training?->plot_boundary_handle;
        if ($plotHandle) {
            $args[] = '--plot-handle';
            $args[] = (string) $plotHandle;
        }

        $plotLayer = $label?->plot_layer;
        if (!$plotLayer && $training && is_array($training->layer_map)) {
            $plotLayer = $training->layer_map['plot'] ?? null;
        }
        if ($plotLayer) {
            $args[] = '--plot-layer';
            $args[] = (string) $plotLayer;
        }

        $buildingLayer = $label?->building_layer;
        if (!$buildingLayer && $training && is_array($training->layer_map)) {
            $buildingLayer = $training->layer_map['building'] ?? null;
        }
        if ($buildingLayer) {
            $args[] = '--building-layer';
            $args[] = (string) $buildingLayer;
        }

        $floorHandles = $this->normalizeFloorHandles($label, $training);
        if (!empty($floorHandles)) {
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
                        if (!is_array($item)) {
                            continue;
                        }
                        $handle = $item['handle'] ?? null;
                        if (!$handle) {
                            continue;
                        }
                        $floor = (int) ($item['floor'] ?? 0);
                        $handles[] = ['floor' => $floor, 'handle' => (string) $handle];
                    }
                } else {
                    foreach ($floorHandles as $key => $handle) {
                        if (!is_string($handle) || $handle === '') {
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

            if (!$handles && $training->building_footprint_handle) {
                $handles[] = ['floor' => 0, 'handle' => (string) $training->building_footprint_handle];
            }
        }

        $seen = [];
        $deduped = [];
        foreach ($handles as $item) {
            $key = $item['floor'] . ':' . strtoupper((string) $item['handle']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $item;
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
            'top' => 'north',
            'bottom' => 'south',
            'right' => 'east',
            'left' => 'west',
            default => null,
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
        if ($training && is_array($training->layer_map) && !empty($training->layer_map)) {
            return json_encode($training->layer_map);
        }

        return '{}';
    }

    private function convertDwgToDxf(string $dwgAbs, string $dxfAbs, string $workDir): void
    {
        $oda = env('ODA_CONVERTER');
        $dwg2dxf = env('LIBREDWG_DWG2DXF');

        // Auto-detect dwg2dxf if not set (or if the configured path is invalid)
        if (!$dwg2dxf || !is_file($dwg2dxf) || !is_executable($dwg2dxf)) {
            // Try common locations first
            foreach (['/usr/bin/dwg2dxf', '/usr/local/bin/dwg2dxf', '/opt/homebrew/bin/dwg2dxf'] as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) {
                    $dwg2dxf = $candidate;
                    break;
                }
            }

            // Try PATH lookup (works on most Linux servers)
            if (!$dwg2dxf || !is_file($dwg2dxf) || !is_executable($dwg2dxf)) {
                try {
                    $which = new Process(['bash', '-lc', 'command -v dwg2dxf || true']);
                    $which->setTimeout(10);
                    $which->run();
                    $p = trim($which->getOutput());
                    if ($p && is_file($p) && is_executable($p)) {
                        $dwg2dxf = $p;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        // Option A: LibreDWG dwg2dxf
        if ($dwg2dxf && is_file($dwg2dxf) && is_executable($dwg2dxf)) {
            $p = new Process([$dwg2dxf, '-o', $dxfAbs, $dwgAbs]);
            $p->setTimeout(300);
            $p->run();
            if (!$p->isSuccessful()) {
                throw new \RuntimeException('dwg2dxf failed: ' . trim($p->getErrorOutput() ?: $p->getOutput()));
            }
            if (!file_exists($dxfAbs)) {
                throw new \RuntimeException('DXF not created by dwg2dxf.');
            }
            return;
        }

        // Option B: ODAFileConverter
        if ($oda) {
            // ODAFileConverter works with folders. We'll copy DWG into workDir/in and output to workDir/out
            $inDir = $workDir . '/in';
            $outDir = $workDir . '/out';
            @mkdir($inDir, 0775, true);
            @mkdir($outDir, 0775, true);
            copy($dwgAbs, $inDir . '/source.dwg');

            // Arguments: <InputFolder> <OutputFolder> <InputFilter> <OutputType> <OutputVersion> <Recurse> <Audit>
            // Example output type/version can differ per ODA build; keep common values.
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
            if (!$p->isSuccessful()) {
                throw new \RuntimeException('ODAFileConverter failed: ' . trim($p->getErrorOutput() ?: $p->getOutput()));
            }

            $candidate = $outDir . '/source.dxf';
            if (!file_exists($candidate)) {
                // some builds output ACAD2013/source.dxf or similar; search
                $found = glob($outDir . '/**/source.dxf', GLOB_BRACE);
                $candidate = $found[0] ?? null;
            }
            if (!$candidate || !file_exists($candidate)) {
                throw new \RuntimeException('ODAFileConverter did not produce source.dxf in expected output folder.');
            }
            copy($candidate, $dxfAbs);
            return;
        }

        $hint = "No DWG→DXF converter found.\n\n" .
            "Option A (recommended): Install LibreDWG and ensure 'dwg2dxf' is on PATH or set LIBREDWG_DWG2DXF in .env\n" .
            "  Ubuntu/Debian: sudo apt-get update && sudo apt-get install -y libredwg-tools\n" .
            "  macOS (Homebrew): brew install libredwg (then: which dwg2dxf)\n" .
            "  Or build from source if package is not available.\n\n" .
            "Option B: Install ODA File Converter and set ODA_CONVERTER in .env (full path to executable).\n";
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
