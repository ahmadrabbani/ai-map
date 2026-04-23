<?php

namespace App\Http\Controllers;

use App\Models\CadEntityFeature;
use App\Models\CadExpertLabel;
use App\Models\CadRuleResult;
use App\Models\CadSubmission;
use App\Models\CadTrainingLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CadExpertLabelController extends Controller
{
    public function edit($id)
    {
        $submission = CadSubmission::findOrFail($id);
        $label = CadExpertLabel::firstOrCreate([
            'cad_submission_id' => $submission->id,
        ]);

        // Layer summary for quick expert mapping
        $layers = CadEntityFeature::where('cad_submission_id', $submission->id)
            ->selectRaw('COALESCE(layer, "(none)") as layer, COUNT(*) as cnt')
            ->groupBy('layer')
            ->orderByDesc('cnt')
            ->get();

        // Candidate closed polylines (useful for picking plot + building)
        $candidates = CadEntityFeature::where('cad_submission_id', $submission->id)
            ->whereIn('entity_type', ['LWPOLYLINE', 'POLYLINE'])
            ->where('is_closed', 1)
            ->orderByDesc('area')
            ->limit(50)
            ->get();

        return view('admin.plans.cad_expert_label', compact('submission','label','layers','candidates'));
    }

    public function store(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $label = CadExpertLabel::firstOrCreate([
            'cad_submission_id' => $submission->id,
        ]);

        $data = $request->validate([
            'plot_layer' => 'nullable|string|max:255',
            'building_layer' => 'nullable|string|max:255',
            'dimension_layer' => 'nullable|string|max:255',
            'text_layer' => 'nullable|string|max:255',
            'plot_entity_handle' => 'nullable|string|max:255',
            'building_entity_handle' => 'nullable|string|max:255',
            'front_side' => 'required|in:auto,north,south,east,west',
            'notes' => 'nullable|string',
        ]);

        $data['labeled_by'] = optional($request->user())->email ?? optional($request->user())->name ?? null;
        $label->fill($data)->save();
        $this->syncTrainingLabel($submission, $label);

        return redirect()->route('admin.plan.cad-expert-label.edit', ['id' => $submission->id])
            ->with('status', 'Labels saved. Thanks!');
    }


    public function viewer($id)
    {
        $submission = CadSubmission::findOrFail($id);
        $label = CadExpertLabel::firstOrCreate([
            'cad_submission_id' => $submission->id,
        ]);

        $rules = $this->loadRulesForSubmission($submission);
        $expertResults = CadRuleResult::where('cad_submission_id', $submission->id)
            ->where('source', 'expert_manual')
            ->orderBy('id')
            ->get();

        return view('admin.plans.cad_layer_viewer', compact('submission', 'label', 'rules', 'expertResults'));
    }

    public function storeExpertResult(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $rules = $this->loadRulesForSubmission($submission);

        $data = $request->validate([
            'rule_id' => 'required|string',
            'measured_value' => 'required|numeric',
            'notes' => 'nullable|string',
        ]);

        $rule = collect($rules)->firstWhere('id', $data['rule_id']);
        if (!$rule) {
            return response()->json(['message' => 'Rule not found.'], 422);
        }

        $measured = (float) $data['measured_value'];
        $required = (float) $rule['required_value'];
        $operator = $rule['operator'] ?? null;
        $isCompliant = $this->evaluateCompliance($operator, $measured, $required);

        $result = CadRuleResult::updateOrCreate(
            [
                'cad_submission_id' => $submission->id,
                'source' => 'expert_manual',
                'rule_id' => $rule['id'],
            ],
            [
                'rule_type' => $rule['type'] ?? null,
                'title' => $rule['title'] ?? null,
                'required_value' => (string) $rule['required_value'],
                'measured_value' => (string) $measured,
                'unit' => $rule['unit'] ?? null,
                'operator' => $operator,
                'is_compliant' => $isCompliant,
                'details' => $data['notes'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Expert measurement saved.',
            'result' => $result,
        ]);
    }

    public function dxf($id)
    {
        $submission = CadSubmission::findOrFail($id);
        if (!$submission->stored_dxf_path || !Storage::disk('local')->exists($submission->stored_dxf_path)) {
            abort(404, 'DXF not found for this submission.');
        }

        $abs = Storage::disk('local')->path($submission->stored_dxf_path);
        return response()->stream(function () use ($abs) {
            $h = fopen($abs, 'rb');
            if ($h) {
                while (!feof($h)) {
                    echo fread($h, 1024 * 1024);
                }
                fclose($h);
            }
        }, 200, [
            'Content-Type' => 'application/dxf',
            'Content-Disposition' => 'inline; filename="source.dxf"',
        ]);
    }

    public function storeLayerMap(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $label = CadExpertLabel::firstOrCreate([
            'cad_submission_id' => $submission->id,
        ]);

        $data = $request->validate([
            'layer_map_json' => 'required|string',
        ]);

        // keep as JSON string (validated by decode)
        $decoded = json_decode($data['layer_map_json'], true);
        if (!is_array($decoded)) {
            return back()->withErrors(['layer_map_json' => 'Invalid JSON mapping'])->withInput();
        }

        $label->layer_map_json = $data['layer_map_json'];
        $label->save();
        $this->syncTrainingLabel($submission, $label);

        return redirect()->route('admin.plan.cad-layer-viewer', $submission->id)
            ->with('status', 'Layer mapping saved.');
    }

    private function loadRulesForSubmission(CadSubmission $submission): array
    {
        $rulesetKey = $submission->ruleset_key ?? '5_marla_residential';
        $rulesetMap = [
            '5_marla_residential' => base_path('rules/5MRulesJSON.json'),
        ];
        $rulesPath = $rulesetMap[$rulesetKey] ?? base_path('rules/5MRulesJSON.json');
        if (!is_file($rulesPath)) {
            return [];
        }

        $raw = json_decode(file_get_contents($rulesPath), true);
        $rules = is_array($raw) ? ($raw['rules'] ?? []) : [];
        $normalized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $required = null;
            $unit = null;
            if (isset($rule['value_ft']) && is_numeric($rule['value_ft'])) {
                $required = $rule['value_ft'];
                $unit = 'ft';
            } elseif (isset($rule['value_percent']) && is_numeric($rule['value_percent'])) {
                $required = $rule['value_percent'];
                $unit = '%';
            } elseif (isset($rule['value_sqft']) && is_numeric($rule['value_sqft'])) {
                $required = $rule['value_sqft'];
                $unit = 'sqft';
            } elseif (isset($rule['value']) && is_numeric($rule['value'])) {
                $required = $rule['value'];
                $unit = $rule['unit'] ?? null;
            }

            if ($required === null) {
                continue;
            }

            $normalized[] = [
                'id' => (string) ($rule['id'] ?? ''),
                'type' => $rule['type'] ?? null,
                'title' => $rule['title'] ?? null,
                'operator' => $rule['operator'] ?? null,
                'required_value' => $required,
                'unit' => $unit,
                'description' => $rule['description'] ?? null,
            ];
        }

        return $normalized;
    }

    private function evaluateCompliance(?string $operator, float $measured, float $required): ?bool
    {
        return match ($operator) {
            '>=' => $measured >= $required,
            '<=' => $measured <= $required,
            '>' => $measured > $required,
            '<' => $measured < $required,
            '==' => $measured == $required,
            default => null,
        };
    }

    private function syncTrainingLabel(CadSubmission $submission, CadExpertLabel $label): void
    {
        $training = CadTrainingLabel::firstOrNew([
            'cad_submission_id' => $submission->id,
        ]);

        $layerMap = $this->buildTrainingLayerMap($label);
        if (!empty($layerMap)) {
            $training->layer_map = $layerMap;
        }

        $plotHandle = $label->plot_entity_handle ?: $training->plot_boundary_handle;
        $buildingHandle = $label->building_entity_handle ?: $training->building_footprint_handle;

        $training->plot_boundary_handle = $plotHandle;
        $training->building_footprint_handle = $buildingHandle;
        $training->front_side = $this->normalizeFrontSideForTraining($label->front_side) ?: $training->front_side;
        $training->notes = $label->notes;
        $training->verified_by = $label->labeled_by;
        $training->verified_at = Carbon::now();

        if ($buildingHandle) {
            $training->floor_handles = [
                ['floor' => 0, 'handle' => (string) $buildingHandle],
            ];
        }

        $training->save();
    }

    private function buildTrainingLayerMap(CadExpertLabel $label): array
    {
        $layerMap = [];

        if ($label->layer_map_json) {
            $decoded = json_decode($label->layer_map_json, true);
            if (is_array($decoded)) {
                $layerMap = $decoded;
            }
        }

        if ($label->plot_layer) {
            $layerMap['plot'] = $label->plot_layer;
        }

        if ($label->building_layer) {
            $layerMap['building'] = $label->building_layer;
            $layerMap['ground_floor'] = $label->building_layer;
        }

        if ($label->dimension_layer) {
            $layerMap['dimensions'] = $label->dimension_layer;
        }

        if ($label->text_layer) {
            $layerMap['text'] = $label->text_layer;
        }

        return $layerMap;
    }

    private function normalizeFrontSideForTraining(?string $frontSide): ?string
    {
        return match ($frontSide) {
            'north' => 'top',
            'south' => 'bottom',
            'east' => 'right',
            'west' => 'left',
            default => null,
        };
    }
}
