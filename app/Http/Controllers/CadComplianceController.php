<?php

namespace App\Http\Controllers;

use App\Models\CadRuleResult;
use App\Models\CadSubmission;
use App\Models\CadEntityFeature;
use App\Models\MapDrawing;
use App\Services\CadComplianceService;
use App\Services\MapApproval\GeometryCalculationService;
use App\Services\MapApproval\MapApprovalPipelineService;
use App\Services\MapApproval\MapApprovalReportService;
use App\Services\MapApproval\RuleValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CadComplianceController extends Controller
{
    public function index()
    {
        // Keep legacy URL as public entrypoint, but route users to the
        // simplified applicant workflow (text-first upload + portal + chat).
        return redirect()->route('admin.plan.bp.index');
    }

    public function submit(Request $request, CadComplianceService $service)
    {
        $request->validate([
            'dwg_file'   => 'required|file|mimes:dwg|max:51200',
            'ruleset_key'=> 'nullable|string',
        ]);

        $file = $request->file('dwg_file');
        $rulesetKey = $request->input('ruleset_key', '5_marla_residential');

        $uploadDir = 'uploads/cad';
        Storage::disk('local')->makeDirectory($uploadDir);

        $storedPath = $file->storeAs(
            $uploadDir,
            Str::uuid() . '.dwg',
            'local'
        );

        $dwgAbs = Storage::disk('local')->path($storedPath);

        $submission = CadSubmission::create([
            'original_filename' => $file->getClientOriginalName(),
            'stored_dwg_path'   => $storedPath,
            'ruleset_key'       => $rulesetKey,
        ]);

        try {
            $run = $service->processSubmission($submission, $dwgAbs);
        } catch (\Throwable $e) {
            Log::error('CAD compliance processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return view('admin.plans.cad_compliance', [
                'submission' => $submission,
                'results_system'    => null,
                'results_expert'    => null,
                'errorMessage' => $e->getMessage(),
            ]);
        }

        // persist results rows (system)
        $this->persistRuleResults($submission, $run, 'system');

        // Persist entity features for expert labeling / ML dataset
        CadEntityFeature::where('cad_submission_id', $submission->id)->delete();
        foreach (($run['entity_features'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            CadEntityFeature::create([
                'cad_submission_id' => $submission->id,
                'entity_handle' => (string)($f['handle'] ?? ''),
                'entity_type'   => (string)($f['type'] ?? ''),
                'layer'         => $f['layer'] ?? $f['raw_layer'] ?? $f['original_layer_name'] ?? $f['standard_layer'] ?? null,
                'is_closed'     => (bool)($f['is_closed'] ?? false),
                'num_vertices'  => (int)($f['num_vertices'] ?? 0),
                'area'          => $f['area'] ?? null,
                'bbox_x0'       => $f['bbox']['x0'] ?? null,
                'bbox_y0'       => $f['bbox']['y0'] ?? null,
                'bbox_x1'       => $f['bbox']['x1'] ?? null,
                'bbox_y1'       => $f['bbox']['y1'] ?? null,
                'bbox_w'        => $f['bbox']['w'] ?? null,
                'bbox_h'        => $f['bbox']['h'] ?? null,
                'rectangularity'=> $f['rectangularity'] ?? null,
                'centroid_x'    => $f['centroid']['x'] ?? null,
                'centroid_y'    => $f['centroid']['y'] ?? null,
                'points_xy'     => $f['points_xy'] ?? null,
            ]);
        }

        // refresh
        $submission->refresh();
        $resultsSystem = $this->loadResults($submission, 'system');
        $resultsExpert = $this->loadResults($submission, 'expert');

        return view('admin.plans.cad_compliance', [
            'submission' => $submission,
            'results_system'    => $resultsSystem,
            'results_expert'    => $resultsExpert,
            'semanticReport' => null,
            'semanticRuleRows' => [],
            'semanticDrawing' => null,
            'errorMessage' => null,
        ]);
    }

    public function show($id)
    {
        $submission = CadSubmission::findOrFail($id);
        $resultsSystem = $this->loadResults($submission, 'system');
        $resultsExpert = $this->loadResults($submission, 'expert');
        [$semanticDrawing, $semanticReport, $semanticRuleRows] = $this->loadSemanticReportData($submission->id);

        return view('admin.plans.cad_compliance', [
            'submission' => $submission,
            'results_system'    => $resultsSystem,
            'results_expert'    => $resultsExpert,
            'semanticReport' => $semanticReport,
            'semanticRuleRows' => $semanticRuleRows,
            'semanticDrawing' => $semanticDrawing,
            'errorMessage' => null,
        ]);
    }

    public function rerunWithLabels($id, CadComplianceService $service)
    {
        $submission = CadSubmission::findOrFail($id);
        $dwgAbs = Storage::disk('local')->path($submission->stored_dwg_path);

        try {
            $run = $service->processSubmission($submission, $dwgAbs, [
                'use_labels' => true,
                'use_stored_dxf' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('CAD compliance rerun failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $resultsSystem = $this->loadResults($submission, 'system');
            $resultsExpert = $this->loadResults($submission, 'expert');

            return view('admin.plans.cad_compliance', [
                'submission' => $submission,
                'results_system'    => $resultsSystem,
                'results_expert'    => $resultsExpert,
                'semanticReport' => null,
                'semanticRuleRows' => [],
                'semanticDrawing' => null,
                'errorMessage' => $e->getMessage(),
            ]);
        }

        $this->persistRuleResults($submission, $run, 'expert');

        return redirect()
            ->route('admin.plan.cad-compliance.show', ['id' => $submission->id])
            ->with('status', 'Expert results saved.');
    }

    public function runSemanticPipeline(
        $id,
        MapApprovalPipelineService $pipelineService,
        GeometryCalculationService $geometryService,
        RuleValidationService $ruleValidationService,
        MapApprovalReportService $reportService
    ) {
        $submission = CadSubmission::findOrFail($id);
        $run = $pipelineService->mapExistingCadSubmission($submission);
        $drawing = $run['drawing']->fresh('entities');
        $summary = $run['mapping_summary'];

        $statusMessage = 'Semantic mapping completed.';
        if (empty($summary['blocking_issues'])) {
            $geometry = $geometryService->calculate($drawing);
            $ruleValidationService->validate($drawing->fresh('entities'), $geometry);
            $report = $reportService->generate($drawing->fresh(['entities', 'geometryResults', 'ruleResults']));
            $statusMessage = 'Semantic mapping and validation completed. Final status: ' . ($report['status'] ?? 'needs_expert_review');
        } else {
            $statusMessage = 'Semantic mapping completed, but expert review is required before validation.';
        }

        return redirect()
            ->route('admin.plan.cad-compliance.show', ['id' => $submission->id])
            ->with('status', $statusMessage);
    }

    private function persistRuleResults(CadSubmission $submission, array $run, string $source): void
    {
        $query = CadRuleResult::where('cad_submission_id', $submission->id);
        if ($source === 'system') {
            $query->where(function ($q) {
                $q->whereNull('source')->orWhere('source', 'system');
            });
        } else {
            $query->where('source', $source);
        }
        $query->delete();

        $normalizeValue = function ($value): ?string {
            if ($value === null) {
                return null;
            }
            if (is_scalar($value)) {
                return (string) $value;
            }
            return json_encode($value);
        };

        foreach (($run['rules'] ?? []) as $r) {
            CadRuleResult::create([
                'cad_submission_id' => $submission->id,
                'source'            => $source,
                'rule_id'           => (string)($r['id'] ?? ''),
                'rule_type'         => $r['type'] ?? null,
                'title'             => $r['title'] ?? null,
                'required_value'    => array_key_exists('required', $r) ? $normalizeValue($r['required']) : null,
                'measured_value'    => array_key_exists('measured', $r) ? $normalizeValue($r['measured']) : null,
                'unit'              => $r['unit'] ?? null,
                'operator'          => $r['operator'] ?? null,
                'is_compliant'      => array_key_exists('pass', $r) ? ($r['pass'] === null ? null : (bool)$r['pass']) : null,
                'details'           => $r['details'] ?? null,
            ]);
        }
    }

    private function loadResults(CadSubmission $submission, string $source)
    {
        $query = $submission->ruleResults()->orderBy('id');
        if ($source === 'system') {
            $query->where(function ($q) {
                $q->whereNull('source')->orWhere('source', 'system');
            });
        } else {
            $query->where('source', $source);
        }

        return $query->get();
    }

    private function loadSemanticReportData(int $submissionId): array
    {
        $drawing = MapDrawing::query()
            ->orderByDesc('id')
            ->get()
            ->first(function (MapDrawing $row) use ($submissionId) {
                $mappedId = data_get($row->metadata_json, 'cad_submission_id');
                return (string) $mappedId === (string) $submissionId;
            });

        if (! $drawing) {
            return [null, null, []];
        }

        $reportService = app(MapApprovalReportService::class);
        $report = $reportService->generate($drawing->fresh(['entities', 'geometryResults', 'ruleResults']));
        $rules = $report['rules'] ?? [];

        return [$drawing, $report, $rules];
    }

    /**
     * Stream the overlay PDF from storage (prevents 403 issues with direct /storage access).
     */
    public function overlay($id)
    {
        $submission = CadSubmission::findOrFail($id);
        if (!$submission->overlay_pdf_path) {
            abort(404, 'Overlay PDF not found.');
        }

        $path = $submission->overlay_pdf_path;
        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'Overlay PDF not found on disk.');
        }

        return Storage::disk('public')->response($path, 'overlay.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="overlay.pdf"',
        ]);
    }

    public function drawing($id)
    {
        $submission = CadSubmission::findOrFail($id);
        if (!$submission->drawing_pdf_path) {
            abort(404, 'Drawing PDF not found.');
        }

        $path = $submission->drawing_pdf_path;
        if (!Storage::disk('public')->exists($path)) {
            abort(404, 'Drawing PDF not found on disk.');
        }

        return Storage::disk('public')->response($path, 'drawing.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="drawing.pdf"',
        ]);
    }
}
