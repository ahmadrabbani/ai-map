<?php

namespace App\Http\Controllers;

use App\Models\CadEntityFeature;
use App\Models\CadEntity;
use App\Models\CadExpertLabel;
use App\Models\CadExpertMarking;
use App\Models\CadExpertMarkingRevision;
use App\Models\CadLabelMapping;
use App\Models\CadRuleResult;
use App\Models\CadSubmission;
use App\Models\CadTrainingLabel;
use App\Models\MapDrawing;
use App\Models\MapEntity;
use Illuminate\Support\Collection;
use App\Services\CadApprovalRuleService;
use App\Services\MapApproval\GeometryCalculationService;
use App\Services\MapApproval\MapApprovalReportService;
use App\Services\MapApproval\RuleValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CadExpertLabelController extends Controller
{
    public function edit(Request $request, $id, CadApprovalRuleService $ruleService)
    {
        if (! $request->boolean('legacy')) {
            return $this->viewer($request, $id, $ruleService);
        }

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

        if ($layers->isEmpty()) {
            $layers = $this->fallbackLayersFromSourceFile($submission);
        }
        $layers = $this->filterAllowedDetectedLayers($layers);

        // Candidate closed polylines (useful for picking plot + building)
        $candidates = CadEntityFeature::where('cad_submission_id', $submission->id)
            ->whereIn('entity_type', ['LWPOLYLINE', 'POLYLINE'])
            ->where('is_closed', 1)
            ->orderByDesc('area')
            ->limit(50)
            ->get();
        $candidates = $candidates->filter(fn ($candidate) => $this->matchAllowedLayerName((string) $candidate->layer) !== null)->values();

        $layerDefinitions = $this->loadLayerDefinitions();
        $floorContext = $this->resolveFloorContext(
            (string) $request->query('floor_context', ''),
            (string) $submission->original_filename,
            $layers->pluck('layer')->all(),
            $layerDefinitions
        );
        $layerGroups = $this->groupLayerDefinitions($layerDefinitions, $floorContext);
        $currentLayerMap = $this->currentLayerMap($label);

        return view('admin.plans.cad_expert_label', compact(
            'submission',
            'label',
            'layers',
            'candidates',
            'layerDefinitions',
            'layerGroups',
            'currentLayerMap',
            'floorContext'
        ));
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
            'floor_handles_json' => 'nullable|string',
            'front_side' => 'required|in:auto,north,south,east,west',
            'notes' => 'nullable|string',
            'official_layer_map' => 'nullable|array',
            'official_layer_map.*' => 'nullable|string|max:255',
            'floor_context' => 'nullable|string|in:basement,ground_floor,first_floor,second_floor,roof',
        ]);

        $officialLayerMap = collect($request->input('official_layer_map', []))
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim($value))
            ->all();

        $floorHandles = null;
        if (!empty($data['floor_handles_json'])) {
            $floorHandles = json_decode($data['floor_handles_json'], true);
            if (!is_array($floorHandles)) {
                return back()->withErrors(['floor_handles_json' => 'Floor handles JSON must be a valid JSON object or array.'])->withInput();
            }
        }

        if (! empty($officialLayerMap)) {
            $data['layer_map_json'] = json_encode($officialLayerMap, JSON_UNESCAPED_SLASHES);
            $data['plot_layer'] = $this->firstMappedLayer($officialLayerMap, ['plot_boundary', 'boundary_wall', 'plot_line']) ?? ($data['plot_layer'] ?? null);
            $data['building_layer'] = $this->firstMappedLayer($officialLayerMap, [
                'ground_external_walls',
                'ground_internal_walls',
                'external_walls',
                'internal_walls',
                'building_footprint',
            ]) ?? ($data['building_layer'] ?? null);
            $data['dimension_layer'] = $this->firstMappedLayer($officialLayerMap, ['dimension', 'dimensions', 'measurement_text']) ?? ($data['dimension_layer'] ?? null);
            $data['text_layer'] = $this->firstMappedLayer($officialLayerMap, ['text', 'text_general', 'ground_text', 'first_text', 'second_text']) ?? ($data['text_layer'] ?? null);
        }

        $data['labeled_by'] = optional($request->user())->email ?? optional($request->user())->name ?? null;
        $label->fill($data)->save();
        $this->syncTrainingLabel($submission, $label);

        if ($floorHandles !== null) {
            $training = CadTrainingLabel::firstOrNew([
                'cad_submission_id' => $submission->id,
            ]);
            $training->floor_handles = $floorHandles;
            $training->save();
        }

        return redirect()->route('admin.plan.cad-expert-label.edit', [
            'id' => $submission->id,
            'floor_context' => $data['floor_context'] ?? null,
        ])
            ->with('status', 'Labels saved. Thanks!');
    }


    public function viewer(Request $request, $id, CadApprovalRuleService $ruleService)
    {
        $submission = CadSubmission::with(['trainingLabel', 'entityFeatures'])->findOrFail($id);
        $label = CadExpertLabel::firstOrCreate([
            'cad_submission_id' => $submission->id,
        ]);

        $rules = $this->loadRulesForSubmission($submission);
        $rulesMetadata = $this->loadRulesMetadataForSubmission($submission);
        $expertResults = CadRuleResult::where('cad_submission_id', $submission->id)
            ->where('source', 'expert_manual')
            ->orderBy('id')
            ->get();

        $entitySummary = [
            'count' => $submission->entityFeatures->count(),
            'closed_polylines' => $submission->entityFeatures
                ->whereIn('entity_type', ['LWPOLYLINE', 'POLYLINE'])
                ->where('is_closed', true)
                ->count(),
            'layers' => $submission->entityFeatures
                ->groupBy(fn ($feature) => $feature->layer ?: '(none)')
                ->map(fn ($items, $layer) => [
                    'layer' => $layer,
                    'count' => $items->count(),
                ])
                ->values()
                ->take(25)
                ->all(),
        ];

        $rulesetOverview = $ruleService->getRulesetOverview();
        $layerDefinitions = $this->loadLayerDefinitions();
        $tagOptions = $this->buildTagOptionsFromLayerDefinitions($layerDefinitions);
        $mapDrawingId = $request->query('map_drawing_id');
        $mapDrawing = null;
        if ($mapDrawingId) {
            $mapDrawing = MapDrawing::find($mapDrawingId);
        }
        if (! $mapDrawing) {
            $mapDrawing = MapDrawing::whereJsonContains('metadata_json->cad_submission_id', $submission->id)
                ->latest('id')
                ->first();
        }
        $this->syncCadEntitiesForSubmission($submission, $mapDrawing);

        return view('admin.plans.cad_layer_viewer', compact(
            'submission',
            'label',
            'rules',
            'expertResults',
            'entitySummary',
            'rulesetOverview',
            'rulesMetadata',
            'mapDrawing',
            'tagOptions'
        ));
    }

    public function entities(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $mapDrawing = $this->ensureCadTextMetadata($this->resolveMapDrawing($submission, $request->query('map_drawing_id')));
        $this->syncCadEntitiesForSubmission($submission, $mapDrawing);

        $query = CadEntity::where('cad_submission_id', $submission->id);
        if ($layer = trim((string) $request->query('layer', ''))) {
            $query->where('layer_name', 'like', '%' . $layer . '%');
        }
        if ($handle = trim((string) $request->query('handle', ''))) {
            $query->where('handle', 'like', '%' . $handle . '%');
        }
        if ($type = trim((string) $request->query('entity_type', ''))) {
            $query->where('entity_type', 'like', '%' . $type . '%');
        }
        if ($text = trim((string) $request->query('text', ''))) {
            $query->where('text_content', 'like', '%' . $text . '%');
        }

        return response()->json(
            $query->orderBy('id')->paginate((int) $request->query('per_page', 200))
        );
    }

    public function labels(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $layerDefinitions = $this->loadLayerDefinitions();
        $required = $this->requiredLabelKeys($submission);
        $counts = [];
        CadLabelMapping::where('cad_submission_id', $submission->id)
            ->select('label_key', DB::raw('COUNT(*) as cnt'))
            ->groupBy('label_key')
            ->get()
            ->each(function ($row) use (&$counts, $layerDefinitions) {
                $canonical = $this->canonicalLabelKey((string) $row->label_key, $layerDefinitions);
                if (! $canonical) {
                    return;
                }
                $counts[$canonical] = ($counts[$canonical] ?? 0) + (int) $row->cnt;
            });

        $itemsByKey = [];
        foreach ($layerDefinitions as $officialName => $def) {
            $key = $this->definitionTag((string) $officialName, $def) ?? (string) $officialName;
            if (! isset($itemsByKey[$key])) {
                $itemsByKey[$key] = [
                    'label_key' => $key,
                    'label_name' => (string) $officialName,
                    'required' => in_array((string) $key, $required, true),
                    'mapped_count' => (int) ($counts[$key] ?? 0),
                    'status' => ((int) ($counts[$key] ?? 0)) > 0 ? 'mapped' : (in_array((string) $key, $required, true) ? 'missing' : 'optional'),
                    'category' => (string) ($def['category'] ?? 'other'),
                ];
                continue;
            }
            if (! $itemsByKey[$key]['required'] && in_array((string) $key, $required, true)) {
                $itemsByKey[$key]['required'] = true;
                $itemsByKey[$key]['status'] = ((int) ($counts[$key] ?? 0)) > 0 ? 'mapped' : 'missing';
            }
        }
        $items = array_values($itemsByKey);

        usort($items, function (array $a, array $b) {
            if ($a['required'] !== $b['required']) {
                return $a['required'] ? -1 : 1;
            }
            return strcmp($a['label_key'], $b['label_key']);
        });

        return response()->json(['labels' => $items]);
    }

    public function createLabelMappings(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $layerDefinitions = $this->loadLayerDefinitions();
        $data = $request->validate([
            'label_key' => 'required|string|max:255',
            'label_name' => 'nullable|string|max:255',
            'cad_entity_ids' => 'required|array|min:1',
            'cad_entity_ids.*' => 'required|integer',
            'source' => 'nullable|in:auto,manual,search',
            'confidence' => 'nullable|numeric|min:0|max:100',
            'allow_conflicts' => 'nullable|boolean',
        ]);
        $canonicalLabelKey = $this->canonicalLabelKey((string) $data['label_key'], $layerDefinitions);
        if (! $canonicalLabelKey) {
            return response()->json(['message' => 'Invalid label key.'], 422);
        }
        $resolvedLabelName = $this->labelDisplayNameForKey($canonicalLabelKey, $layerDefinitions) ?? ($data['label_name'] ?? $canonicalLabelKey);

        $allowConflicts = (bool) ($data['allow_conflicts'] ?? false);
        $entities = CadEntity::where('cad_submission_id', $submission->id)
            ->whereIn('id', $data['cad_entity_ids'])
            ->get()
            ->keyBy('id');

        if ($entities->count() !== count($data['cad_entity_ids'])) {
            return response()->json(['message' => 'One or more CAD entities are invalid for this submission.'], 422);
        }

        $created = [];
        foreach ($data['cad_entity_ids'] as $entityId) {
            $entity = $entities[(int) $entityId];
            if (! $allowConflicts) {
                $conflict = CadLabelMapping::where('cad_submission_id', $submission->id)
                    ->where('cad_entity_id', $entity->id)
                    ->get()
                    ->first(function (CadLabelMapping $candidate) use ($canonicalLabelKey, $layerDefinitions) {
                        return $this->canonicalLabelKey((string) $candidate->label_key, $layerDefinitions) !== $canonicalLabelKey;
                    });
                if ($conflict) {
                    $conflictLabel = $this->canonicalLabelKey((string) $conflict->label_key, $layerDefinitions) ?? $conflict->label_key;
                    return response()->json([
                        'message' => "Entity handle {$entity->handle} is already mapped to {$conflictLabel}.",
                    ], 422);
                }
            }

            $created[] = CadLabelMapping::updateOrCreate(
                [
                    'cad_submission_id' => $submission->id,
                    'label_key' => $canonicalLabelKey,
                    'cad_entity_id' => $entity->id,
                ],
                [
                    'label_name' => $resolvedLabelName,
                    'cad_handle' => $entity->handle,
                    'source' => $data['source'] ?? 'manual',
                    'confidence' => $data['confidence'] ?? null,
                    'user_confirmed' => true,
                ]
            );
        }

        return response()->json([
            'message' => 'Entities mapped successfully.',
            'mappings' => $created,
        ]);
    }

    public function deleteLabelMapping(Request $request, $id, $mappingId)
    {
        $submission = CadSubmission::findOrFail($id);
        $mapping = CadLabelMapping::where('cad_submission_id', $submission->id)->findOrFail($mappingId);
        $mapping->delete();

        return response()->json(['message' => 'Mapping removed.']);
    }

    public function autoSuggestMappings(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $mapDrawing = $this->ensureCadTextMetadata($this->resolveMapDrawing($submission, $request->query('map_drawing_id')));
        $this->syncCadEntitiesForSubmission($submission, $mapDrawing);

        $layerDefinitions = $this->loadLayerDefinitions();
        $labelToLayerTokens = [];
        foreach ($layerDefinitions as $officialName => $def) {
            $labelKey = $this->definitionTag((string) $officialName, $def) ?? (string) $officialName;
            $labelToLayerTokens[(string) $labelKey] = $this->normalizeLayerName((string) $officialName) . ' ' .
                $this->normalizeLayerName((string) ($def['description'] ?? '')) . ' ' .
                $this->normalizeLayerName((string) $labelKey);
        }

        $suggested = 0;
        $entities = CadEntity::where('cad_submission_id', $submission->id)->get();
        foreach ($entities as $entity) {
            $normalizedLayer = $this->normalizeLayerName((string) $entity->layer_name);
            if ($normalizedLayer === '') {
                continue;
            }
            $heuristicLabel = $this->heuristicTagFromLayerName($normalizedLayer, $layerDefinitions);
            if ($heuristicLabel) {
                $bestLabel = $heuristicLabel;
                $bestScore = 0.98;
            } else {
                $bestLabel = null;
                $bestScore = 0;
            }
            foreach ($labelToLayerTokens as $labelKey => $tokens) {
                $score = $this->tokenOverlapScore($normalizedLayer, $tokens);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestLabel = $labelKey;
                }
            }
            if (! $bestLabel || $bestScore < 0.35) {
                continue;
            }

            $existsConflict = CadLabelMapping::where('cad_submission_id', $submission->id)
                ->where('cad_entity_id', $entity->id)
                ->get()
                ->contains(function (CadLabelMapping $candidate) use ($bestLabel, $layerDefinitions) {
                    return $this->canonicalLabelKey((string) $candidate->label_key, $layerDefinitions) !== $bestLabel;
                });
            if ($existsConflict) {
                continue;
            }

            CadLabelMapping::updateOrCreate(
                [
                    'cad_submission_id' => $submission->id,
                    'label_key' => $bestLabel,
                    'cad_entity_id' => $entity->id,
                ],
                [
                    'label_name' => $this->labelDisplayNameForKey((string) $bestLabel, $layerDefinitions) ?? (string) $bestLabel,
                    'cad_handle' => $entity->handle,
                    'source' => 'auto',
                    'confidence' => round($bestScore * 100, 2),
                    'user_confirmed' => false,
                ]
            );
            $suggested++;
        }

        return response()->json([
            'message' => "Auto-suggest completed. {$suggested} mapping(s) suggested.",
            'suggested' => $suggested,
        ]);
    }

    public function mappingReport(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $required = $this->requiredLabelKeys($submission);
        $layerDefinitions = $this->loadLayerDefinitions();
        $mappings = CadLabelMapping::with('entity')
            ->where('cad_submission_id', $submission->id)
            ->orderBy('label_key')
            ->orderBy('id')
            ->get();
        $mapDrawing = $this->ensureCadTextMetadata($this->resolveMapDrawing($submission, $request->query('map_drawing_id')));
        $textReferences = is_array(data_get($mapDrawing?->metadata_json, 'cad_text_references'))
            ? data_get($mapDrawing?->metadata_json, 'cad_text_references')
            : [];
        $textMetrics = $this->cadTextMeasurementMetrics($mapDrawing);

        $byLabel = [];
        foreach ($mappings as $mapping) {
            $entity = $mapping->entity;
            if (! $entity) {
                continue;
            }
            $key = $this->canonicalLabelKey((string) $mapping->label_key, $layerDefinitions);
            if (! $key) {
                continue;
            }
            $byLabel[$key] = $byLabel[$key] ?? [
                'label_key' => $key,
                'label_name' => $this->labelDisplayNameForKey($key, $layerDefinitions) ?? ($mapping->label_name ?: $key),
                'required' => in_array($key, $required, true),
                'entity_count' => 0,
                'entities' => [],
                'totals' => ['length' => 0.0, 'area' => 0.0, 'perimeter' => 0.0],
                'source_state' => 'entity_mapped',
            ];

            $measurement = $entity->measurement_json ?: [];
            $byLabel[$key]['entity_count']++;
            $byLabel[$key]['totals']['length'] += (float) ($measurement['measured_length'] ?? 0);
            $byLabel[$key]['totals']['area'] += (float) ($measurement['measured_area'] ?? 0);
            $byLabel[$key]['totals']['perimeter'] += (float) ($measurement['measured_perimeter'] ?? 0);
            $byLabel[$key]['entities'][] = [
                'mapping_id' => $mapping->id,
                'cad_entity_id' => $entity->id,
                'cad_handle' => $entity->handle,
                'cad_layer' => $entity->layer_name,
                'entity_type' => $entity->entity_type,
                'geometry_type' => $entity->geometry_type,
                'bbox' => $entity->bbox_json,
                'closed' => (bool) data_get($measurement, 'closed', false),
                'measurement' => $measurement,
                'confidence' => $mapping->confidence,
                'source' => $mapping->source,
                'user_confirmed' => $mapping->user_confirmed,
            ];
        }

        $missingRequired = [];
        $mappedLabelKeys = $this->mappedCanonicalLabelKeys($submission->id, $layerDefinitions);
        foreach ($required as $key) {
            if (empty($byLabel[$key])) {
                $byLabel[$key] = [
                    'label_key' => $key,
                    'label_name' => $this->labelDisplayNameForKey($key, $layerDefinitions) ?? $key,
                    'required' => true,
                    'entity_count' => 0,
                    'entities' => [],
                    'totals' => ['length' => 0.0, 'area' => 0.0, 'perimeter' => 0.0],
                    'source_state' => 'missing',
                ];
            }
            if (($byLabel[$key]['source_state'] ?? null) === 'missing' && isset($mappedLabelKeys[$key])) {
                $byLabel[$key]['source_state'] = 'entity_mapped';
            }
            if (($byLabel[$key]['source_state'] ?? null) === 'missing' && $this->hasTextEvidenceForLabel($key, $textReferences, $textMetrics)) {
                $byLabel[$key]['source_state'] = 'text_evidence';
            }
            if (($byLabel[$key]['source_state'] ?? null) === 'missing') {
                $missingRequired[] = $key;
            }
        }

        return response()->json([
            'submission_id' => $submission->id,
            'labels' => array_values($byLabel),
            'missing_required_labels' => $missingRequired,
            'warnings' => $this->buildMappingWarnings($byLabel),
            'messages' => $this->buildMappingWarnings($byLabel),
            'generated_at' => now()->toISOString(),
        ]);
    }

    public function expertMarkings(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $rows = CadExpertMarking::where('cad_submission_id', $submission->id)
            ->where('source', 'expert_drawn')
            ->orderBy('label_key')
            ->orderBy('id')
            ->get();

        return response()->json(['markings' => $rows]);
    }

    public function storeExpertMarking(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $layerDefinitions = $this->loadLayerDefinitions();
        $data = $request->validate([
            'label_key' => 'required|string|max:255',
            'label_name' => 'nullable|string|max:255',
            'geometry_type' => 'required|in:polygon,polyline,rectangle,point',
            'points_json' => 'required|array|min:1',
            'measurement_json' => 'required|array',
            'status' => 'nullable|in:draft,confirmed',
        ]);
        $canonicalLabelKey = $this->canonicalLabelKey((string) $data['label_key'], $layerDefinitions);
        if (! $canonicalLabelKey) {
            return response()->json(['message' => 'Invalid label key for expert marking.'], 422);
        }

        $marking = CadExpertMarking::create([
            'cad_submission_id' => $submission->id,
            'label_key' => $canonicalLabelKey,
            'label_name' => $this->labelDisplayNameForKey($canonicalLabelKey, $layerDefinitions) ?? ($data['label_name'] ?? $canonicalLabelKey),
            'geometry_type' => $data['geometry_type'],
            'points_json' => $data['points_json'],
            'measurement_json' => $data['measurement_json'],
            'status' => $data['status'] ?? 'draft',
            'source' => 'expert_drawn',
            'created_by' => optional($request->user())->email ?? optional($request->user())->name,
            'updated_by' => optional($request->user())->email ?? optional($request->user())->name,
        ]);

        return response()->json(['message' => 'Expert marking saved.', 'marking' => $marking]);
    }

    public function updateExpertMarking(Request $request, $id, $markingId)
    {
        $submission = CadSubmission::findOrFail($id);
        $marking = CadExpertMarking::where('cad_submission_id', $submission->id)->findOrFail($markingId);

        $data = $request->validate([
            'points_json' => 'nullable|array|min:1',
            'measurement_json' => 'nullable|array',
            'status' => 'nullable|in:draft,confirmed',
            'change_reason' => 'nullable|string|max:255',
        ]);

        CadExpertMarkingRevision::create([
            'cad_expert_marking_id' => $marking->id,
            'old_points_json' => $marking->points_json,
            'old_measurement_json' => $marking->measurement_json,
            'changed_by' => optional($request->user())->email ?? optional($request->user())->name,
            'change_reason' => $data['change_reason'] ?? 'update',
        ]);

        if (array_key_exists('points_json', $data)) {
            $marking->points_json = $data['points_json'];
        }
        if (array_key_exists('measurement_json', $data)) {
            $marking->measurement_json = $data['measurement_json'];
        }
        if (array_key_exists('status', $data)) {
            $marking->status = $data['status'];
        }
        $marking->updated_by = optional($request->user())->email ?? optional($request->user())->name;
        $marking->save();

        return response()->json(['message' => 'Expert marking updated.', 'marking' => $marking]);
    }

    public function deleteExpertMarking(Request $request, $id, $markingId)
    {
        $submission = CadSubmission::findOrFail($id);
        $marking = CadExpertMarking::where('cad_submission_id', $submission->id)->findOrFail($markingId);
        $marking->delete();

        return response()->json(['message' => 'Expert marking deleted.']);
    }

    public function confirmExpertMarking(Request $request, $id, $markingId)
    {
        $submission = CadSubmission::findOrFail($id);
        $marking = CadExpertMarking::where('cad_submission_id', $submission->id)->findOrFail($markingId);
        $marking->status = 'confirmed';
        $marking->updated_by = optional($request->user())->email ?? optional($request->user())->name;
        $marking->save();

        return response()->json(['message' => 'Expert marking confirmed.', 'marking' => $marking]);
    }

    public function expertMarkingReport(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $layerDefinitions = $this->loadLayerDefinitions();
        $required = $this->requiredLabelKeys($submission);
        $mapDrawing = $this->ensureCadTextMetadata($this->resolveMapDrawing($submission, $request->query('map_drawing_id')));
        $textReferences = is_array(data_get($mapDrawing?->metadata_json, 'cad_text_references'))
            ? data_get($mapDrawing?->metadata_json, 'cad_text_references')
            : [];
        $textMetrics = $this->cadTextMeasurementMetrics($mapDrawing);
        $rows = CadExpertMarking::where('cad_submission_id', $submission->id)
            ->where('source', 'expert_drawn')
            ->orderBy('label_key')
            ->get();

        $byLabel = [];
        foreach ($rows as $row) {
            $k = $this->canonicalLabelKey((string) $row->label_key, $layerDefinitions);
            if (! $k) {
                continue;
            }
            $byLabel[$k] = $byLabel[$k] ?? [
                'label_key' => $k,
                'label_name' => $this->labelDisplayNameForKey($k, $layerDefinitions) ?? ($row->label_name ?: $k),
                'required' => in_array($k, $required, true),
                'status' => 'not_marked',
                'markings' => [],
                'totals' => ['area' => 0.0, 'perimeter' => 0.0, 'length' => 0.0],
                'source_state' => 'missing',
            ];
            $m = is_array($row->measurement_json) ? $row->measurement_json : [];
            $byLabel[$k]['markings'][] = [
                'id' => $row->id,
                'geometry_type' => $row->geometry_type,
                'status' => $row->status,
                'label_key' => $k,
                'label_name' => $row->label_name ?: $k,
                'measurement' => [
                    'area' => (float) ($m['area'] ?? 0),
                    'perimeter' => (float) ($m['perimeter'] ?? 0),
                    'length' => (float) ($m['length'] ?? 0),
                    'point_count' => (int) ($m['point_count'] ?? 0),
                ],
            ];
            $byLabel[$k]['totals']['area'] += (float) ($m['area'] ?? 0);
            $byLabel[$k]['totals']['perimeter'] += (float) ($m['perimeter'] ?? 0);
            $byLabel[$k]['totals']['length'] += (float) ($m['length'] ?? 0);
            if ($row->status === 'confirmed') {
                $byLabel[$k]['status'] = 'confirmed';
                $byLabel[$k]['source_state'] = 'expert_confirmed';
            } elseif ($byLabel[$k]['status'] !== 'confirmed') {
                $byLabel[$k]['status'] = 'draft';
            }
        }

        $mappedLabelKeys = $this->mappedCanonicalLabelKeys($submission->id, $layerDefinitions);
        $missing = [];
        foreach ($required as $key) {
            if (! isset($byLabel[$key])) {
                $byLabel[$key] = [
                    'label_key' => $key,
                    'label_name' => $this->labelDisplayNameForKey($key, $layerDefinitions) ?? $key,
                    'required' => true,
                    'status' => 'not_marked',
                    'markings' => [],
                    'totals' => ['area' => 0.0, 'perimeter' => 0.0, 'length' => 0.0],
                    'source_state' => 'missing',
                ];
            }
            if (($byLabel[$key]['source_state'] ?? null) !== 'expert_confirmed' && isset($mappedLabelKeys[$key])) {
                $byLabel[$key]['source_state'] = 'entity_mapped';
            }
            // For text-oriented required labels (dimensions/text), allow CAD text
            // and official textual measurement evidence to satisfy preliminary
            // presence. Final approval remains with the officer, but the UI
            // should not tell users that matched layer/text data is missing.
            if (
                ($byLabel[$key]['source_state'] ?? null) === 'missing'
                && $this->hasTextEvidenceForLabel($key, $textReferences, $textMetrics)
            ) {
                $byLabel[$key]['source_state'] = 'text_evidence';
                if (($byLabel[$key]['status'] ?? 'not_marked') === 'not_marked') {
                    $byLabel[$key]['status'] = 'draft';
                }
            }
            if (($byLabel[$key]['source_state'] ?? null) === 'missing') {
                $missing[] = $key;
            }
        }

        return response()->json([
            'submission_id' => $submission->id,
            'labels' => array_values($byLabel),
            'missing_required_labels' => $missing,
            'missing_required_label_details' => array_values(array_map(function (string $key) use ($byLabel): array {
                return [
                    'label_key' => $key,
                    'label_name' => (string) ($byLabel[$key]['label_name'] ?? $key),
                ];
            }, $missing)),
            'text_reference_hints' => $this->buildTextReferenceHints($textReferences, $required, $byLabel),
            ...$this->buildTextComparisonPayload($mapDrawing, $textReferences),
            'approval_readiness' => $this->buildApprovalReadiness($byLabel, $missing, $textMetrics),
            'messages' => $this->buildExpertMarkingMessages($byLabel, $missing),
        ]);
    }

    public function storeCadTextReferences(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $data = $request->validate([
            'map_drawing_id' => 'nullable|integer',
            'references' => 'required|array|max:500',
            'references.*.text' => 'required|string|max:300',
            'references.*.layer' => 'nullable|string|max:255',
            'references.*.value_ft' => 'nullable|numeric',
            'references.*.semantic_hints' => 'nullable|array',
            'references.*.semantic_hints.*' => 'string|max:120',
        ]);

        $mapDrawing = ! empty($data['map_drawing_id'])
            ? MapDrawing::find($data['map_drawing_id'])
            : $this->resolveMapDrawing($submission, null);
        if (! $mapDrawing) {
            return response()->json(['message' => 'No drawing found for this submission.'], 422);
        }

        $normalized = [];
        foreach ($data['references'] as $row) {
            $hints = array_values(array_unique(array_filter(array_map(
                fn ($hint) => $this->normalizeSemanticAlias((string) $hint),
                (array) ($row['semantic_hints'] ?? [])
            ))));
            $normalized[] = [
                'text' => trim((string) $row['text']),
                'layer' => trim((string) ($row['layer'] ?? '')),
                'value_ft' => isset($row['value_ft']) && $row['value_ft'] !== null ? round((float) $row['value_ft'], 4) : null,
                'semantic_hints' => $hints,
            ];
        }

        $metadata = is_array($mapDrawing->metadata_json) ? $mapDrawing->metadata_json : [];
        $metadata['cad_text_references'] = $normalized;
        $metadata['cad_text_references_updated_at'] = now()->toISOString();
        $mapDrawing->metadata_json = $metadata;
        $mapDrawing->save();

        return response()->json(['message' => 'CAD text references saved.', 'count' => count($normalized)]);
    }

    public function assistantChat(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $data = $request->validate([
            'question' => 'required|string|max:4000',
            'map_drawing_id' => 'nullable|integer',
            'context' => 'nullable|array',
        ]);

        $mapDrawing = $this->resolveMapDrawing($submission, $data['map_drawing_id'] ?? null);
        $report = $this->mappingReport($request, $submission->id)->getData(true);
        $expert = $this->expertMarkingReport($request, $submission->id)->getData(true);
        $question = trim((string) $data['question']);
        $context = (array) ($data['context'] ?? []);

        $systemPrompt = <<<'PROMPT'
You are an AI Map Approval Assistant for a Building Plan / Map Scrutiny System.

Your role is to help applicants, officers, and reviewers understand the uploaded building map, the detected CAD/map layers, and the applicable planning rules from the official rule book.

You must always follow these principles:

1. Read and use the provided rule book, planning rules JSON, CAD layer schema, detected map layers, AI analysis output, and previous chat history before replying.
2. Do not guess. If the map, layer, measurement, or rule data is missing, unclear, or not confidently detected, clearly say that the item requires expert/manual review.
3. Explain findings in simple and professional language.
4. When answering, base your response only on:
   - The uploaded map analysis result
   - Detected CAD/map layers
   - Rule book / planning rules JSON
   - Layer mapping schema
   - Generated AI report
   - Previous chat history of the same application
5. Never claim that the AI system has approved or rejected the map finally.
6. Always mention that the final approval, rejection, or correction decision rests with the concerned Directorate / competent authority.
7. Explain that this AI system is only a decision-support and fast-tracking tool created to help validate maps based on actual digital data, measurements, layers, and rules instead of assumptions or manual guesswork.
8. If a user asks “Is my map approved?”, reply carefully:
   - State the AI recommendation only.
   - Mention passed/failed rules if available.
   - Clearly say final approval is subject to review by the concerned Directorate.
9. If a user asks about an error or failed rule:
   - Identify the relevant rule.
   - Show the detected value from the map.
   - Show the required value from the rule book.
   - Explain the difference.
   - Suggest that the user should correct the map or wait for officer review.
10. If the system cannot detect a required layer, say exactly:
   “The required layer or geometry could not be confidently detected from the uploaded map. This item has been marked for expert review.”
11. Do not provide legal guarantees, personal opinions, or unofficial relaxations of rules.
12. Keep the tone professional, helpful, neutral, and transparent.

Every response should follow this format when possible:
- Summary:
- Based on Available Data:
- Rule Book Reference:
- AI Finding:
- Important Notice:

Standard disclaimer to include where relevant:
“This response is generated by an AI-assisted map scrutiny system using the uploaded map data, detected layers, measurements, and configured rule book. It is intended only to support faster and more reliable scrutiny. Final approval, rejection, or correction of the map is reserved by the concerned Directorate / competent authority.”
PROMPT;

        $payload = [
            'question' => $question,
            'selected_label' => $context['selected_label'] ?? null,
            'selected_rule' => $context['selected_rule'] ?? null,
            'scaled_distance' => $context['scaled_distance'] ?? null,
            'selected_measurement_summary' => $context['selected_measurement_summary'] ?? null,
            'mapping_report' => $report,
            'expert_report' => $expert,
            'map_drawing_id' => $mapDrawing?->id,
            'chat_history' => array_slice((array) ($context['chat_history'] ?? []), -12),
        ];

        $apiKey = (string) env('OPENAI_API_KEY', '');
        if ($apiKey !== '') {
            try {
                $response = Http::withToken($apiKey)
                    ->timeout(25)
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => env('OPENAI_CHAT_MODEL', 'gpt-4.1-mini'),
                        'input' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_SLASHES)],
                        ],
                        'max_output_tokens' => 350,
                    ]);
                if ($response->ok()) {
                    $json = $response->json();
                    $text = (string) data_get($json, 'output.0.content.0.text', '');
                    if ($text !== '') {
                        return response()->json(['reply' => $text, 'source' => 'openai']);
                    }
                }
            } catch (\Throwable $e) {
                // fall back to deterministic response below
            }
        }

        $reply = $this->deterministicAssistantReply($question, $payload);
        return response()->json(['reply' => $reply, 'source' => 'local_fallback']);
    }

    public function plannerReview(
        Request $request,
        $id,
        GeometryCalculationService $geometryService,
        RuleValidationService $ruleValidationService,
        MapApprovalReportService $reportService
    ) {
        $submission = CadSubmission::findOrFail($id);
        $mapDrawingId = $request->query('map_drawing_id');
        $mapDrawing = $mapDrawingId ? MapDrawing::find($mapDrawingId) : null;
        if (! $mapDrawing) {
            $mapDrawing = MapDrawing::whereJsonContains('metadata_json->cad_submission_id', $submission->id)
                ->latest('id')
                ->first();
        }

        $report = null;
        if ($mapDrawing) {
            if ($mapDrawing->geometryResults()->count() === 0 || $mapDrawing->ruleResults()->count() === 0) {
                $geometry = $geometryService->calculate($mapDrawing->fresh('entities'));
                $ruleValidationService->validate($mapDrawing->fresh('entities'), $geometry);
            }
            $report = $reportService->generate($mapDrawing->fresh(['entities', 'geometryResults', 'ruleResults']));
        }

        $mappedEntities = $mapDrawing
            ? $mapDrawing->entities()
                ->whereNotNull('semantic_entity')
                ->whereIn('mapping_status', ['auto_mapped', 'manual_mapped', 'expert_verified', 'needs_expert_review'])
                ->orderBy('semantic_entity')
                ->get()
            : collect();

        $trainingStats = [
            'mapped_entities' => $mappedEntities->count(),
            'expert_verified' => $mappedEntities->where('mapping_status', 'expert_verified')->count(),
            'needs_review' => $mappedEntities->where('mapping_status', 'needs_expert_review')->count(),
            'saved_expert_results' => CadRuleResult::where('cad_submission_id', $submission->id)
                ->where('source', 'expert_manual')
                ->count(),
        ];
        $plannerDecision = $mapDrawing ? (data_get($mapDrawing->metadata_json, 'planner_decision') ?? null) : null;
        $plannerDecisionNote = $mapDrawing ? (data_get($mapDrawing->metadata_json, 'planner_decision_note') ?? null) : null;
        $plannerDecisionAt = $mapDrawing ? (data_get($mapDrawing->metadata_json, 'planner_decision_at') ?? null) : null;

        return view('admin.plans.planner_review', compact(
            'submission',
            'mapDrawing',
            'report',
            'mappedEntities',
            'trainingStats',
            'plannerDecision',
            'plannerDecisionNote',
            'plannerDecisionAt'
        ));
    }

    public function confirmPlannerMeasurements(
        Request $request,
        $id,
        GeometryCalculationService $geometryService,
        RuleValidationService $ruleValidationService,
        MapApprovalReportService $reportService
    ) {
        $submission = CadSubmission::findOrFail($id);
        $data = $request->validate([
            'map_drawing_id' => 'nullable|integer',
            'confirmation_note' => 'nullable|string|max:1000',
            'measurement_overrides' => 'nullable|array',
            'measurement_overrides.*' => 'nullable|numeric',
        ]);

        $mapDrawing = ! empty($data['map_drawing_id']) ? MapDrawing::find($data['map_drawing_id']) : null;
        if (! $mapDrawing) {
            $mapDrawing = MapDrawing::whereJsonContains('metadata_json->cad_submission_id', $submission->id)
                ->latest('id')
                ->first();
        }

        if (! $mapDrawing) {
            return back()->withErrors(['map_drawing_id' => 'No semantic drawing record found. Run semantic mapping first.']);
        }

        $metadata = is_array($mapDrawing->metadata_json) ? $mapDrawing->metadata_json : [];
        $metadata['expert_measurements_confirmed'] = true;
        $metadata['expert_measurements_confirmed_at'] = now()->toISOString();
        $metadata['expert_measurements_confirmed_by'] = optional($request->user())->email ?? optional($request->user())->name ?? 'planner';
        $metadata['expert_measurements_confirmation_note'] = $data['confirmation_note'] ?? null;
        $allowedOverrideKeys = [
            'plot_area_sqft',
            'ground_floor_area_sqft',
            'first_floor_area_sqft',
            'second_floor_area_sqft',
            'ground_coverage_percent',
            'far',
            'front_setback_ft',
            'rear_setback_ft',
            'left_setback_ft',
            'right_setback_ft',
            'porch_length_ft',
            'porch_area_sqft',
            'storey_count',
        ];
        $overrides = [];
        foreach (($data['measurement_overrides'] ?? []) as $key => $value) {
            if (in_array($key, $allowedOverrideKeys, true) && $value !== null && $value !== '') {
                $overrides[$key] = (float) $value;
            }
        }
        if (! empty($overrides)) {
            $metadata['measurement_overrides'] = $overrides;
            $metadata['measurement_override_source'] = 'planner_confirmed_dimensions';
        }
        $mapDrawing->metadata_json = $metadata;
        $mapDrawing->save();

        $geometry = $geometryService->calculate($mapDrawing->fresh('entities'));
        $ruleValidationService->validate($mapDrawing->fresh('entities'), $geometry);
        $reportService->generate($mapDrawing->fresh(['entities', 'geometryResults', 'ruleResults']));

        return redirect()->route('admin.plan.cad-planner-review', [
            'id' => $submission->id,
            'map_drawing_id' => $mapDrawing->id,
        ])->with('status', 'Planner measurements confirmed. Final rule decisions have been recalculated.');
    }

    public function savePlannerDecision(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $data = $request->validate([
            'map_drawing_id' => 'nullable|integer',
            'decision' => 'required|in:approved,revision_required',
            'decision_note' => 'nullable|string|max:2000',
        ]);

        $mapDrawing = ! empty($data['map_drawing_id']) ? MapDrawing::find($data['map_drawing_id']) : null;
        if (! $mapDrawing) {
            $mapDrawing = MapDrawing::whereJsonContains('metadata_json->cad_submission_id', $submission->id)
                ->latest('id')
                ->first();
        }
        if (! $mapDrawing) {
            return back()->withErrors(['map_drawing_id' => 'No semantic drawing record found.']);
        }

        $metadata = is_array($mapDrawing->metadata_json) ? $mapDrawing->metadata_json : [];
        $metadata['planner_decision'] = $data['decision'];
        $metadata['planner_decision_note'] = $data['decision_note'] ?? null;
        $metadata['planner_decision_at'] = now()->toISOString();
        $metadata['planner_decision_by'] = optional($request->user())->email ?? optional($request->user())->name ?? 'planner';
        $mapDrawing->metadata_json = $metadata;
        $mapDrawing->status = $data['decision'] === 'approved' ? 'approved' : 'revision_required';
        $mapDrawing->validation_status = $data['decision'] === 'approved' ? 'ready_for_submission' : 'needs_expert_review';
        $mapDrawing->save();

        return redirect()->route('admin.plan.cad-planner-review', [
            'id' => $submission->id,
            'map_drawing_id' => $mapDrawing->id,
        ])->with('status', $data['decision'] === 'approved'
            ? 'Planner decision saved: Approved.'
            : 'Planner decision saved: Revision required.');
    }

    public function storeExpertResult(Request $request, $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $rules = $this->loadRulesForSubmission($submission);

        $data = $request->validate([
            'rule_id' => 'required|string',
            'measured_value' => 'required|numeric',
            'system_measured_value' => 'nullable|numeric',
            'measurement_points' => 'required|array|size:2',
            'measurement_points.*.x' => 'required|numeric',
            'measurement_points.*.y' => 'required|numeric',
            'raw_distance' => 'required|numeric',
            'scale_multiplier' => 'required|numeric',
            'scale_label' => 'nullable|string|max:100',
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
                'system_measured_value' => isset($data['system_measured_value']) ? (string) $data['system_measured_value'] : null,
                'unit' => $rule['unit'] ?? null,
                'operator' => $operator,
                'is_compliant' => $isCompliant,
                'measurement_source' => 'cad_viewer_two_point_measurement',
                'details' => $data['notes'] ?? null,
                'measurement_points_json' => [
                    'points' => $data['measurement_points'],
                    'raw_distance' => (float) $data['raw_distance'],
                    'scale_multiplier' => (float) $data['scale_multiplier'],
                    'scale_label' => $data['scale_label'] ?? null,
                    'scaled_distance' => $measured,
                ],
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
        $rulesPath = base_path('rules/approval_rules_meta.json');
        if (!is_file($rulesPath)) {
            return [];
        }

        $raw = json_decode(file_get_contents($rulesPath), true);
        $rules = [];

        if (is_array($raw)) {
            if (isset($raw['rules']) && is_array($raw['rules'])) {
                $rules = $raw['rules'];
            } else {
                $plotCategory = data_get($submission->analysis_result, 'resolved_ruleset.plot_size_category', '5_marla');
                $categoryRules = data_get($raw, 'plot_size_categories.' . $plotCategory . '.ground_floor_rules', []);
                if (is_array($categoryRules)) {
                    $rules = $categoryRules;
                }
            }
        }
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

    private function loadRulesMetadataForSubmission(CadSubmission $submission): array
    {
        $rulesPath = base_path('rules/approval_rules_meta.json');
        if (! is_file($rulesPath)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($rulesPath), true);
        if (! is_array($raw)) {
            return [];
        }

        return is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [];
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

    private function loadLayerDefinitions(): array
    {
        $definitions = [];

        $schemaPath = base_path('rules/rule_to_layer_schema.json');
        if (is_file($schemaPath)) {
            $decoded = json_decode((string) file_get_contents($schemaPath), true);
            if (is_array($decoded['layer_definitions'] ?? null)) {
                foreach ($decoded['layer_definitions'] as $name => $def) {
                    if (! is_array($def)) {
                        continue;
                    }
                    $definitions[(string) $name] = [
                        'description' => $def['description'] ?? (string) $name,
                        'category' => $def['category'] ?? 'other',
                        'tag' => $def['tag'] ?? null,
                    ];
                }
            }
        }

        $legacyPath = is_file(base_path('rules/layer_35.json'))
            ? base_path('rules/layer_35.json')
            : base_path('rules/layers.json');
        if (is_file($legacyPath)) {
            $decoded = json_decode((string) file_get_contents($legacyPath), true);
            if (is_array($decoded['layers'] ?? null)) {
                foreach ($decoded['layers'] as $name => $def) {
                    if (! is_array($def)) {
                        continue;
                    }
                    $definitions[(string) $name] = array_merge($definitions[(string) $name] ?? [], [
                        'description' => $def['description'] ?? (string) $name,
                        'category' => $def['category'] ?? 'other',
                        'tag' => $def['tag'] ?? ($definitions[(string) $name]['tag'] ?? null),
                    ]);
                }
            }
        }

        return $definitions;
    }

    private function groupLayerDefinitions(array $definitions, string $floorContext): array
    {
        $allowedCategories = $this->allowedCategoriesForFloor($floorContext);
        $grouped = [];

        foreach ($definitions as $code => $definition) {
            $category = (string) ($definition['category'] ?? 'other');
            if (! in_array($category, $allowedCategories, true)) {
                continue;
            }
            $grouped[$category][] = [
                'code' => $code,
                'description' => $definition['description'] ?? null,
                'tag' => $definition['tag'] ?? null,
            ];
        }

        $ordered = [];
        foreach ($allowedCategories as $category) {
            if (! empty($grouped[$category])) {
                $ordered[$category] = $grouped[$category];
            }
        }

        return $ordered;
    }

    private function currentLayerMap(CadExpertLabel $label): array
    {
        $map = [];

        if (! empty($label->layer_map_json)) {
            $decoded = is_array($label->layer_map_json)
                ? $label->layer_map_json
                : json_decode((string) $label->layer_map_json, true);

            if (is_array($decoded)) {
                $map = $decoded;
            }
        }

        if (! empty($label->plot_layer) && empty($map['plot_boundary'])) {
            $map['plot_boundary'] = $label->plot_layer;
        }

        if (! empty($label->building_layer)) {
            $map['ground_external_walls'] = $map['ground_external_walls'] ?? $label->building_layer;
        }

        if (! empty($label->dimension_layer)) {
            $map['dimension'] = $map['dimension'] ?? $label->dimension_layer;
        }

        if (! empty($label->text_layer)) {
            $map['text'] = $map['text'] ?? $label->text_layer;
        }

        return $map;
    }

    private function firstMappedLayer(array $layerMap, array $preferredTags): ?string
    {
        foreach ($preferredTags as $tag) {
            if (! empty($layerMap[$tag])) {
                return $layerMap[$tag];
            }
        }

        return null;
    }

    private function buildTagOptionsFromLayerDefinitions(array $definitions): array
    {
        $options = [];
        foreach ($definitions as $layerCode => $definition) {
            $tag = trim((string) ($definition['tag'] ?? ''));
            if ($tag === '') {
                continue;
            }

            $label = (string) ($definition['description'] ?? $tag);
            if (! isset($options[$tag])) {
                $options[$tag] = [
                    'value' => $tag,
                    'label' => $label,
                    'aliases' => [],
                ];
            }

            $options[$tag]['aliases'][] = strtolower((string) $layerCode);
            if (! empty($definition['category'])) {
                $options[$tag]['aliases'][] = strtolower((string) $definition['category']);
            }
        }

        ksort($options);
        $result = [[
            'value' => '',
            'label' => '(unassigned)',
            'aliases' => [],
        ]];
        foreach ($options as $option) {
            $option['aliases'] = array_values(array_unique(array_filter($option['aliases'])));
            $result[] = $option;
        }

        return $result;
    }

    private function resolveFloorContext(string $requested, string $filename, array $detectedLayers, array $definitions): string
    {
        $valid = ['basement', 'ground_floor', 'first_floor', 'second_floor', 'roof'];

        if (in_array($requested, $valid, true)) {
            return $requested;
        }

        $normalizedFilename = strtolower($filename);
        $filenameHints = [
            'basement' => ['basement', 'bsm'],
            'ground_floor' => ['ground', 'gf'],
            'first_floor' => ['first', 'ff'],
            'second_floor' => ['second', 'sf'],
            'roof' => ['roof', 'rf'],
        ];

        foreach ($filenameHints as $floor => $hints) {
            foreach ($hints as $hint) {
                if ($hint !== '' && str_contains($normalizedFilename, $hint)) {
                    return $floor;
                }
            }
        }

        $categoryScores = array_fill_keys($valid, 0);
        foreach ($detectedLayers as $layer) {
            $definition = $definitions[$layer] ?? null;
            if (! is_array($definition)) {
                continue;
            }

            $category = $definition['category'] ?? null;
            if (isset($categoryScores[$category])) {
                $categoryScores[$category]++;
            }
        }

        arsort($categoryScores);
        $best = array_key_first(array_filter($categoryScores, fn ($score) => $score > 0));

        return $best ?: 'ground_floor';
    }

    private function allowedCategoriesForFloor(string $floorContext): array
    {
        $common = ['site', 'reference'];
        $supporting = ['components', 'structure', 'materials', 'utilities'];

        return match ($floorContext) {
            'basement' => array_merge($common, ['basement'], $supporting),
            'first_floor' => array_merge($common, ['first_floor'], $supporting),
            'second_floor' => array_merge($common, ['second_floor'], $supporting),
            'roof' => array_merge($common, ['roof'], $supporting),
            default => array_merge($common, ['ground_floor'], $supporting),
        };
    }

    private function fallbackLayersFromSourceFile(CadSubmission $submission): Collection
    {
        $path = $submission->stored_dxf_path ?: $submission->stored_dwg_path;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return collect();
        }

        $absolutePath = Storage::disk('local')->path($path);
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if ($extension !== 'dxf') {
            return collect();
        }

        $lines = @file($absolutePath, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            return collect();
        }

        $layers = [];
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount - 1; $i++) {
            $code = trim((string) $lines[$i]);
            $value = trim((string) $lines[$i + 1]);

            if ($code === '8' && $value !== '') {
                $matched = $this->matchAllowedLayerName($value);
                if ($matched === null) {
                    continue;
                }
                $layers[$value] = ($layers[$value] ?? 0) + 1;
            }
        }

        return collect($layers)
            ->map(fn ($cnt, $layer) => (object) ['layer' => $layer, 'cnt' => $cnt])
            ->sortBy('layer', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function filterAllowedDetectedLayers(Collection $layers): Collection
    {
        return $layers
            ->filter(fn ($row) => $this->matchAllowedLayerName((string) ($row->layer ?? '')) !== null)
            ->sortBy('layer', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function matchAllowedLayerName(string $layerName): ?string
    {
        $lookup = $this->allowedLayerLookup();
        if (empty($lookup)) {
            return $layerName;
        }

        $normalized = $this->normalizeLayerName($layerName);
        if (isset($lookup[$normalized])) {
            return $lookup[$normalized];
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
        $path = file_exists(base_path('rules/layer_35.json'))
            ? base_path('rules/layer_35.json')
            : base_path('rules/layers.json');
        if (! file_exists($path)) {
            return [];
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

        return $lookup;
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

    private function resolveMapDrawing(CadSubmission $submission, mixed $mapDrawingId): ?MapDrawing
    {
        if ($mapDrawingId) {
            $found = MapDrawing::find($mapDrawingId);
            if ($found) {
                return $found;
            }
        }

        return MapDrawing::whereJsonContains('metadata_json->cad_submission_id', $submission->id)
            ->latest('id')
            ->first();
    }

    private function syncCadEntitiesForSubmission(CadSubmission $submission, ?MapDrawing $mapDrawing): void
    {
        if ($mapDrawing) {
            $mapEntities = MapEntity::where('map_drawing_id', $mapDrawing->id)->get();
            foreach ($mapEntities as $entity) {
                $points = data_get($entity->geometry_json, 'points', []);
                $measurement = $this->measurementForPoints($points, (bool) $entity->is_closed);
                $bbox = is_array($entity->bbox_json) ? $entity->bbox_json : $this->calculateBBox($points);
                CadEntity::updateOrCreate(
                    [
                        'cad_submission_id' => $submission->id,
                        'handle' => (string) $entity->handle,
                    ],
                    [
                        'layer_name' => $entity->layer_name,
                        'normalized_layer_name' => $this->normalizeLayerName((string) $entity->layer_name),
                        'entity_type' => $entity->entity_type,
                        'geometry_type' => $this->geometryTypeForEntity($entity->entity_type, (bool) $entity->is_closed),
                        'geometry_json' => $entity->geometry_json,
                        'bbox_json' => $bbox,
                        'measurement_json' => $measurement,
                        'text_content' => data_get($entity->geometry_json, 'text'),
                    ]
                );
            }
            return;
        }

        $features = CadEntityFeature::where('cad_submission_id', $submission->id)->get();
        foreach ($features as $feature) {
            $points = is_array($feature->points_xy) ? $feature->points_xy : [];
            $measurement = $this->measurementForPoints($points, (bool) $feature->is_closed);
            CadEntity::updateOrCreate(
                [
                    'cad_submission_id' => $submission->id,
                    'handle' => (string) $feature->entity_handle,
                ],
                [
                    'layer_name' => $feature->layer,
                    'normalized_layer_name' => $this->normalizeLayerName((string) $feature->layer),
                    'entity_type' => $feature->entity_type,
                    'geometry_type' => $this->geometryTypeForEntity($feature->entity_type, (bool) $feature->is_closed),
                    'geometry_json' => ['points' => $points],
                    'bbox_json' => [
                        'minX' => (float) $feature->bbox_x0,
                        'minY' => (float) $feature->bbox_y0,
                        'maxX' => (float) $feature->bbox_x1,
                        'maxY' => (float) $feature->bbox_y1,
                        'width' => (float) $feature->bbox_w,
                        'height' => (float) $feature->bbox_h,
                    ],
                    'measurement_json' => $measurement,
                    'text_content' => null,
                ]
            );
        }
    }

    private function geometryTypeForEntity(?string $entityType, bool $isClosed): string
    {
        $type = strtoupper((string) $entityType);
        if ($type === 'LINE') {
            return 'line';
        }
        if (in_array($type, ['LWPOLYLINE', 'POLYLINE', 'SPLINE'], true)) {
            return $isClosed ? 'polygon' : 'polyline';
        }
        if (in_array($type, ['TEXT', 'MTEXT'], true)) {
            return 'text';
        }
        return 'unknown';
    }

    private function measurementForPoints(array $points, bool $closed): array
    {
        $length = $this->calculatePolylineLength($points, $closed);
        $area = $closed ? $this->calculatePolygonArea($points) : 0.0;
        $bbox = $this->calculateBBox($points);

        return [
            'closed' => $closed,
            'measured_length' => round($length, 4),
            'measured_perimeter' => round($length, 4),
            'measured_area' => round($area, 4),
            'measured_width' => round((float) ($bbox['width'] ?? 0), 4),
            'measured_height' => round((float) ($bbox['height'] ?? 0), 4),
        ];
    }

    private function calculatePolylineLength(array $points, bool $closed = false): float
    {
        if (count($points) < 2) {
            return 0.0;
        }

        $sum = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $p0 = $points[$i - 1];
            $p1 = $points[$i];
            $sum += hypot(((float) ($p1[0] ?? 0)) - ((float) ($p0[0] ?? 0)), ((float) ($p1[1] ?? 0)) - ((float) ($p0[1] ?? 0)));
        }
        if ($closed) {
            $first = $points[0];
            $last = $points[count($points) - 1];
            $sum += hypot(((float) ($first[0] ?? 0)) - ((float) ($last[0] ?? 0)), ((float) ($first[1] ?? 0)) - ((float) ($last[1] ?? 0)));
        }
        return $sum;
    }

    private function calculatePolygonArea(array $points): float
    {
        if (count($points) < 3) {
            return 0.0;
        }

        $sum = 0.0;
        $count = count($points);
        for ($i = 0; $i < $count; $i++) {
            $j = ($i + 1) % $count;
            $x1 = (float) ($points[$i][0] ?? 0);
            $y1 = (float) ($points[$i][1] ?? 0);
            $x2 = (float) ($points[$j][0] ?? 0);
            $y2 = (float) ($points[$j][1] ?? 0);
            $sum += (($x1 * $y2) - ($x2 * $y1));
        }

        return abs($sum) / 2.0;
    }

    private function calculateBBox(array $points): array
    {
        if (empty($points)) {
            return ['minX' => 0, 'minY' => 0, 'maxX' => 0, 'maxY' => 0, 'width' => 0, 'height' => 0];
        }

        $xs = array_map(fn ($p) => (float) ($p[0] ?? 0), $points);
        $ys = array_map(fn ($p) => (float) ($p[1] ?? 0), $points);
        $minX = min($xs);
        $maxX = max($xs);
        $minY = min($ys);
        $maxY = max($ys);

        return [
            'minX' => $minX,
            'minY' => $minY,
            'maxX' => $maxX,
            'maxY' => $maxY,
            'width' => $maxX - $minX,
            'height' => $maxY - $minY,
        ];
    }

    private function requiredLabelKeys(CadSubmission $submission): array
    {
        $keys = ['plot_boundary', 'front_building_line', 'side_building_line', 'rear_building_line', 'external_walls', 'dimensions', 'text'];
        $rules = $this->loadRulesForSubmission($submission);
        foreach ($rules as $rule) {
            $title = strtolower((string) ($rule['title'] ?? ''));
            if (str_contains($title, 'plot')) {
                $keys[] = 'plot_boundary';
            }
            if (str_contains($title, 'setback')) {
                $keys[] = 'front_building_line';
                $keys[] = 'side_building_line';
                $keys[] = 'rear_building_line';
            }
        }
        return array_values(array_unique($keys));
    }

    private function definitionTag(string $officialName, mixed $definition): ?string
    {
        $tag = trim((string) data_get($definition, 'tag', ''));
        if ($tag !== '') {
            return $tag;
        }
        $normalized = $this->normalizeLayerName($officialName);
        return $normalized !== '' ? str_replace(' ', '_', $normalized) : null;
    }

    private function canonicalLabelKey(string $raw, array $layerDefinitions): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $lookup = $this->labelAliasLookup($layerDefinitions);
        $normalized = $this->normalizeLayerName($value);
        $resolved = $lookup[$normalized] ?? null;
        return $resolved ? $this->normalizeSemanticAlias($resolved) : null;
    }

    private function labelAliasLookup(array $layerDefinitions): array
    {
        $lookup = [];
        foreach ($layerDefinitions as $officialName => $definition) {
            $tag = $this->definitionTag((string) $officialName, $definition);
            if (! $tag) {
                continue;
            }

            $aliases = [
                $officialName,
                (string) data_get($definition, 'description', ''),
                $tag,
                str_replace('_', ' ', $tag),
            ];
            foreach ($aliases as $alias) {
                $normalized = $this->normalizeLayerName((string) $alias);
                if ($normalized === '') {
                    continue;
                }
                $lookup[$normalized] = $tag;
            }
        }
        return $lookup;
    }

    private function labelDisplayNameForKey(string $labelKey, array $layerDefinitions): ?string
    {
        foreach ($layerDefinitions as $officialName => $definition) {
            if ($this->definitionTag((string) $officialName, $definition) === $labelKey) {
                return (string) $officialName;
            }
        }
        return null;
    }

    private function mappedCanonicalLabelKeys(int $submissionId, array $layerDefinitions): array
    {
        $mapped = [];
        CadLabelMapping::where('cad_submission_id', $submissionId)
            ->select('label_key')
            ->distinct()
            ->get()
            ->each(function (CadLabelMapping $mapping) use (&$mapped, $layerDefinitions): void {
                $canonical = $this->canonicalLabelKey((string) $mapping->label_key, $layerDefinitions);
                if ($canonical) {
                    $mapped[$canonical] = true;
                }
            });

        $label = CadExpertLabel::where('cad_submission_id', $submissionId)->first();
        if ($label) {
            foreach ($this->currentLayerMap($label) as $layerNameOrTag => $row) {
                $candidate = null;
                if (is_array($row)) {
                    $candidate = trim((string) ($row['tag'] ?? ''));
                } elseif (is_string($row) && trim($row) !== '') {
                    // Legacy map shape was semantic_tag => CAD layer name.
                    $candidate = (string) $layerNameOrTag;
                }

                if ($candidate !== null && $candidate !== '') {
                    $canonical = $this->canonicalLabelKey($candidate, $layerDefinitions);
                    if ($canonical) {
                        $mapped[$canonical] = true;
                    }
                }

                // Trust official CAD layer row names as a fallback. This protects
                // review status from stale/corrupt dropdown tags such as every
                // row being saved as "plot_boundary".
                $layerName = is_array($row) ? trim((string) ($row['layer'] ?? $layerNameOrTag)) : (string) $row;
                $layerCanonical = $this->canonicalLabelKey($layerName, $layerDefinitions);
                if ($layerCanonical) {
                    $mapped[$layerCanonical] = true;
                }
            }
        }

        return $mapped;
    }

    private function normalizeSemanticAlias(string $tag): string
    {
        return match ($tag) {
            'plot_line', 'boundary_wall' => 'plot_boundary',
            'dimension', 'measurement_text' => 'dimensions',
            'text_general' => 'text',
            'front_setback' => 'front_building_line',
            'rear_setback' => 'rear_building_line',
            'setback' => 'side_building_line',
            'ground_external_walls', 'first_external_walls', 'second_external_walls' => 'external_walls',
            default => $tag,
        };
    }

    private function heuristicTagFromLayerName(string $normalizedLayer, array $layerDefinitions): ?string
    {
        $available = [];
        foreach ($layerDefinitions as $officialName => $definition) {
            $tag = $this->definitionTag((string) $officialName, $definition);
            if ($tag) {
                $available[$tag] = true;
            }
        }

        $keywordMap = [
            'plot_boundary' => ['plot boundary', 'plot line', 'boundary wall', 'site pl', 'site-fbl', 'a-wall'],
            'front_building_line' => ['front building line', 'front setback', 'fbl', 'front line'],
            'rear_building_line' => ['rear building line', 'rear setback', 'rear line'],
            'side_building_line' => ['side building line', 'side setback', 'setback side', 'site sb'],
            'external_walls' => ['external wall', 'ext wall', 'outer wall', 'gf-we', 'ff-we', 'sf-we'],
            'dimensions' => ['dimension', 'dim', 'dims', 'measurement'],
            'text' => ['text', 'annotation', 'note', 'ref-txt'],
            'ramp' => ['ramp', 'site-rmp'],
            'landscape' => ['landscape', 'lawn', 'open space', 'site-ls'],
            'door' => ['door', 'dr'],
            'windows' => ['window', 'wnd', 'wn', 'ventilator'],
            'stairs' => ['stair', 'st'],
            'water_tank' => ['water tank', 'wt'],
            'porch' => ['porch', 'gf-pr'],
        ];

        foreach ($keywordMap as $tag => $keywords) {
            if (! isset($available[$tag])) {
                continue;
            }
            foreach ($keywords as $keyword) {
                if (str_contains($normalizedLayer, $this->normalizeLayerName($keyword))) {
                    return $tag;
                }
            }
        }

        return null;
    }

    private function tokenOverlapScore(string $a, string $b): float
    {
        $normalizedA = $this->normalizeLayerName($a);
        $normalizedB = $this->normalizeLayerName($b);
        if ($normalizedA === '' || $normalizedB === '') {
            return 0.0;
        }

        // Strong boost for direct semantic containment, e.g.:
        // "1 Plot Boundary" -> "plot boundary"
        // "02-Boundary wall" -> "boundary wall"
        if ($normalizedA === $normalizedB) {
            return 1.0;
        }
        if (str_contains($normalizedA, $normalizedB) || str_contains($normalizedB, $normalizedA)) {
            return 0.92;
        }

        $tokensA = array_values(array_filter(explode(' ', $normalizedA)));
        $tokensB = array_values(array_filter(explode(' ', $normalizedB)));
        if (empty($tokensA) || empty($tokensB)) {
            return 0.0;
        }

        $intersection = array_intersect($tokensA, $tokensB);
        if (empty($intersection)) {
            return 0.0;
        }

        // Prefer coverage of the shorter phrase instead of penalizing long label descriptions.
        $coverageShorter = count($intersection) / min(count($tokensA), count($tokensB));
        $jaccard = count($intersection) / count(array_unique(array_merge($tokensA, $tokensB)));

        return max($coverageShorter, $jaccard);
    }

    private function buildMappingWarnings(array $byLabel): array
    {
        $warnings = [];
        foreach ($byLabel as $labelKey => $row) {
            if (($row['entity_count'] ?? 0) === 0) {
                continue;
            }
            if (($row['entity_count'] ?? 0) > 200) {
                $warnings[] = "{$labelKey} has unusually high entity count ({$row['entity_count']}).";
            }
            if (($row['totals']['area'] ?? 0) === 0.0 && str_contains($labelKey, 'plot')) {
                $warnings[] = "{$labelKey} is mapped but computed area is zero; check closed polygon selection.";
            }
        }

        return $warnings;
    }

    private function buildExpertMarkingMessages(array $byLabel, array $missing): array
    {
        $messages = [];
        foreach ($byLabel as $label => $row) {
            if (($row['required'] ?? false) !== true) {
                continue;
            }
            $area = (float) ($row['totals']['area'] ?? 0);
            $perimeter = (float) ($row['totals']['perimeter'] ?? 0);
            $length = (float) ($row['totals']['length'] ?? 0);
            if (($row['source_state'] ?? null) === 'expert_confirmed') {
                $messages[] = "{$row['label_name']} confirmed. Area {$area}, Perimeter {$perimeter}, Length {$length}.";
            } elseif (($row['source_state'] ?? null) === 'entity_mapped') {
                $messages[] = "{$row['label_name']} is available from the matched CAD layer/entity mapping. Officer confirmation is optional unless the drawing looks incorrect.";
            } elseif (($row['source_state'] ?? null) === 'text_evidence') {
                $messages[] = "{$row['label_name']} is supported by textual data from the CAD measurement/table layers.";
            }
        }
        foreach ($missing as $required) {
            $labelName = (string) data_get($byLabel, $required . '.label_name', $required);
            $messages[] = "{$labelName} still needs layer mapping, textual evidence, or officer marking.";
        }
        return $messages;
    }

    private function hasTextEvidenceForLabel(string $labelKey, array $textReferences, array $textMetrics = []): bool
    {
        if ($this->hasMetricEvidenceForLabel($labelKey, $textMetrics)) {
            return true;
        }

        foreach ($textReferences as $row) {
            $hints = array_values(array_unique(array_filter(array_map(
                fn ($hint) => $this->normalizeSemanticAlias((string) $hint),
                (array) data_get($row, 'semantic_hints', [])
            ))));
            if (in_array($labelKey, $hints, true)) {
                return true;
            }
        }

        return false;
    }

    private function cadTextMeasurementMetrics(?MapDrawing $mapDrawing): array
    {
        if (! $mapDrawing) {
            return [];
        }

        $metadata = is_array($mapDrawing->metadata_json) ? $mapDrawing->metadata_json : [];
        $metrics = data_get($metadata, 'cad_text_measurement_metrics');
        return is_array($metrics) ? $metrics : [];
    }

    private function ensureCadTextMetadata(?MapDrawing $mapDrawing): ?MapDrawing
    {
        if (! $mapDrawing) {
            return null;
        }

        $metadata = is_array($mapDrawing->metadata_json) ? $mapDrawing->metadata_json : [];
        $hasReferences = is_array(data_get($metadata, 'cad_text_references')) && count((array) data_get($metadata, 'cad_text_references')) > 0;
        $hasMetrics = is_array(data_get($metadata, 'cad_text_measurement_metrics'));
        if ($hasReferences && $hasMetrics) {
            return $mapDrawing;
        }

        try {
            app(\App\Services\AiMapAnalysisService::class)->hydrateCadTextReferencesFromLayers($mapDrawing);
            return $mapDrawing->fresh() ?: $mapDrawing;
        } catch (\Throwable $e) {
            report($e);
            return $mapDrawing;
        }
    }

    private function hasMetricEvidenceForLabel(string $labelKey, array $metrics): bool
    {
        $hasAnyMetric = count(array_filter($metrics, fn ($value) => $value !== null && $value !== '')) > 0;

        return match ($labelKey) {
            'plot_boundary' => $this->hasNumericMetric($metrics, ['plot_area', 'plot_area_sqft', 'plot_area_kanal', 'plot_area_marla']),
            'front_building_line' => $this->hasNumericMetric($metrics, ['front_setback_ft', 'front_setback']),
            'rear_building_line' => $this->hasNumericMetric($metrics, ['rear_setback_ft', 'rear_setback']),
            'side_building_line' => $this->hasNumericMetric($metrics, ['left_setback_ft', 'right_setback_ft', 'side_setback_ft', 'left_setback', 'right_setback']),
            'external_walls' => $this->hasNumericMetric($metrics, ['ground_floor_covered', 'ground_floor_area_sqft', 'total_floor_covered', 'total_floor_area_sqft']),
            'dimensions', 'text' => $hasAnyMetric,
            default => false,
        };
    }

    private function hasNumericMetric(array $metrics, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = data_get($metrics, $key);
            if (is_numeric($value) && (float) $value > 0) {
                return true;
            }
        }

        return false;
    }

    private function buildApprovalReadiness(array $byLabel, array $missing, array $textMetrics): array
    {
        $plotArea = $this->firstNumericMetric($textMetrics, ['plot_area', 'plot_area_sqft']);
        $groundCovered = $this->firstNumericMetric($textMetrics, ['ground_floor_covered', 'ground_floor_area_sqft']);
        $totalCovered = $this->firstNumericMetric($textMetrics, ['total_floor_covered', 'total_floor_area_sqft']);
        $coverageText = $this->firstNumericMetric($textMetrics, ['coverage_percent', 'ground_coverage_percent']);
        $farText = $this->firstNumericMetric($textMetrics, ['far']);

        $coverageFormula = ($plotArea && $groundCovered) ? round(($groundCovered / $plotArea) * 100, 2) : null;
        $farFormula = ($plotArea && $totalCovered) ? round($totalCovered / $plotArea, 4) : null;

        $messages = [];
        if ($plotArea && $groundCovered) {
            $messages[] = "Textual measurements found: plot area {$plotArea}, ground floor covered {$groundCovered}, coverage {$coverageFormula}%.";
        }
        if ($plotArea && $totalCovered) {
            $messages[] = "FAR from textual data: total floor covered {$totalCovered} / plot area {$plotArea} = {$farFormula}.";
        }

        $coverageClear = $coverageFormula !== null && $coverageFormula <= 75.0;
        $farClear = $farFormula !== null && $farFormula <= 2.3;
        if ($coverageClear && $farClear) {
            $messages[] = 'Textual data supports preliminary clearance for coverage and FAR. Final approval remains with the competent authority.';
        } elseif ($coverageFormula !== null || $farFormula !== null) {
            $messages[] = 'Textual data was read, but one or more formula checks need officer review.';
        }

        $mappedOrTextCount = count(array_filter($byLabel, fn ($row) => in_array(($row['source_state'] ?? 'missing'), ['expert_confirmed', 'entity_mapped', 'text_evidence'], true)));
        $blockingCount = count($missing);

        return [
            'status' => $blockingCount === 0 && ($coverageClear || $farClear) ? 'preliminary_clear' : ($blockingCount === 0 ? 'layer_text_available' : 'needs_review'),
            'blocking_count' => $blockingCount,
            'mapped_or_text_count' => $mappedOrTextCount,
            'coverage_percent_formula' => $coverageFormula,
            'coverage_percent_text' => $coverageText,
            'far_formula' => $farFormula,
            'far_text' => $farText,
            'messages' => $messages,
        ];
    }

    private function firstNumericMetric(array $metrics, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = data_get($metrics, $key);
            if (is_numeric($value)) {
                return round((float) $value, 4);
            }
        }

        return null;
    }

    private function deterministicAssistantReply(string $question, array $payload): string
    {
        $lower = strtolower($question);
        $missing = (array) data_get($payload, 'expert_report.missing_required_label_details', []);
        $selectedLabel = (string) data_get($payload, 'selected_label', 'None');
        $selectedRule = (string) data_get($payload, 'selected_rule.id', 'None');
        $distance = data_get($payload, 'scaled_distance');
        $summary = (array) data_get($payload, 'selected_measurement_summary', []);

        if (str_contains($lower, 'missing') || str_contains($lower, 'required')) {
            if (empty($missing)) {
                return 'No required labels are missing in the current report.';
            }
            $names = array_map(fn ($row) => (string) ($row['label_name'] ?? $row['label_key'] ?? ''), $missing);
            return 'Missing required labels: ' . implode(', ', array_filter($names)) . '. Map/confirm these first to reduce review flags.';
        }

        if (str_contains($lower, 'distance') || str_contains($lower, 'measure')) {
            if (is_numeric($distance)) {
                return 'Current measured distance is ' . round((float) $distance, 3) . '. Use this value for the selected rule or map entities for area/length totals.';
            }
            return 'No two-point measurement is active. Turn on Measure, click two points, then use the value for the selected rule.';
        }

        if (str_contains($lower, 'label') || str_contains($lower, 'map')) {
            return 'Active label is ' . $selectedLabel . '. Selected measurement summary: length ' .
                round((float) ($summary['length'] ?? 0), 2) . ', area ' . round((float) ($summary['area'] ?? 0), 2) .
                '. Map selected entities, then Save/Confirm label.';
        }

        if (str_contains($lower, 'rule') || str_contains($lower, 'pass') || str_contains($lower, 'fail')) {
            return 'Selected rule is ' . $selectedRule . '. Measure required CAD distance, compare with required threshold, then save expert result and regenerate report.';
        }

        return 'Start with required labels: Plot Boundary (closed polygon), External Walls (multi-entity), building lines, then Dimensions/Text. Use Measure for rule values and regenerate mapping report.';
    }

    private function buildTextReferenceHints(array $textReferences, array $requiredKeys, array $byLabel): array
    {
        $hints = [];
        foreach ($requiredKeys as $requiredKey) {
            $matches = array_values(array_filter($textReferences, function ($row) use ($requiredKey) {
                $semanticHints = (array) data_get($row, 'semantic_hints', []);
                return in_array($requiredKey, $semanticHints, true);
            }));
            if (empty($matches)) {
                continue;
            }
            $labelName = (string) data_get($byLabel, $requiredKey . '.label_name', $requiredKey);
            $sample = array_slice(array_map(function ($row) {
                $value = data_get($row, 'value_ft');
                $txt = (string) data_get($row, 'text', '');
                return $value !== null ? "{$value} ft · {$txt}" : $txt;
            }, $matches), 0, 3);
            $hints[] = [
                'label_key' => $requiredKey,
                'label_name' => $labelName,
                'count' => count($matches),
                'sample' => $sample,
            ];
        }
        return $hints;
    }

    private function buildTextComparisonPayload(?MapDrawing $mapDrawing, array $textReferences): array
    {
        if (! $mapDrawing) {
            return [
                'text_vs_geometry_comparisons' => [],
                'fast_track' => ['eligible' => false, 'eligible_count' => 0, 'total_compared' => 0, 'threshold_percent' => 10.0],
            ];
        }
        $geometryRows = $mapDrawing->geometryResults()->get(['key', 'value'])->keyBy('key');
        if ($geometryRows->isEmpty()) {
            return [
                'text_vs_geometry_comparisons' => [],
                'fast_track' => ['eligible' => false, 'eligible_count' => 0, 'total_compared' => 0, 'threshold_percent' => 10.0],
            ];
        }

        $semanticValues = [];
        foreach ($textReferences as $row) {
            $value = data_get($row, 'value_ft');
            if (! is_numeric($value)) {
                continue;
            }
            foreach ((array) data_get($row, 'semantic_hints', []) as $hint) {
                $semanticValues[$hint] = $semanticValues[$hint] ?? [];
                $semanticValues[$hint][] = (float) $value;
            }
        }

        $targets = [
            'front_building_line' => 'front_setback_ft',
            'rear_building_line' => 'rear_setback_ft',
            'porch' => 'porch_length_ft',
        ];

        $comparisons = [];
        $thresholdPercent = 10.0;
        foreach ($targets as $semantic => $geometryKey) {
            $textVals = $semanticValues[$semantic] ?? [];
            if (empty($textVals)) {
                continue;
            }
            $geom = $geometryRows->get($geometryKey);
            $geomVal = $geom && is_numeric($geom->value) ? (float) $geom->value : null;
            $textVal = round($this->median($textVals), 3);
            $delta = $geomVal === null ? null : round(abs($textVal - $geomVal), 3);
            $pct = ($geomVal === null || abs($geomVal) < 0.0001) ? null : round(($delta / max(abs($geomVal), 1.0)) * 100, 2);
            $confidence = $pct === null ? 'unknown' : ($pct <= 5 ? 'high' : ($pct <= 15 ? 'medium' : 'low'));
            $comparisons[] = [
                'semantic_hint' => $semantic,
                'geometry_key' => $geometryKey,
                'text_value_ft' => $textVal,
                'geometry_value_ft' => $geomVal,
                'delta_ft' => $delta,
                'delta_percent' => $pct,
                'confidence' => $confidence,
                'fast_track_eligible' => $pct !== null && $pct <= $thresholdPercent,
            ];
        }

        // Side setback compares text against min(left,right) geometry.
        $sideVals = $semanticValues['side_building_line'] ?? [];
        if (! empty($sideVals)) {
            $left = $geometryRows->get('left_setback_ft');
            $right = $geometryRows->get('right_setback_ft');
            $leftVal = $left && is_numeric($left->value) ? (float) $left->value : null;
            $rightVal = $right && is_numeric($right->value) ? (float) $right->value : null;
            $geomVal = null;
            if ($leftVal !== null && $rightVal !== null) {
                $geomVal = min($leftVal, $rightVal);
            } elseif ($leftVal !== null) {
                $geomVal = $leftVal;
            } elseif ($rightVal !== null) {
                $geomVal = $rightVal;
            }
            $textVal = round($this->median($sideVals), 3);
            $delta = $geomVal === null ? null : round(abs($textVal - $geomVal), 3);
            $pct = ($geomVal === null || abs($geomVal) < 0.0001) ? null : round(($delta / max(abs($geomVal), 1.0)) * 100, 2);
            $confidence = $pct === null ? 'unknown' : ($pct <= 5 ? 'high' : ($pct <= 15 ? 'medium' : 'low'));
            $comparisons[] = [
                'semantic_hint' => 'side_building_line',
                'geometry_key' => 'left_or_right_setback_ft',
                'text_value_ft' => $textVal,
                'geometry_value_ft' => $geomVal,
                'delta_ft' => $delta,
                'delta_percent' => $pct,
                'confidence' => $confidence,
                'fast_track_eligible' => $pct !== null && $pct <= $thresholdPercent,
            ];
        }

        $eligibleCount = count(array_filter($comparisons, fn ($row) => (bool) ($row['fast_track_eligible'] ?? false)));
        return [
            'text_vs_geometry_comparisons' => $comparisons,
            'fast_track' => [
                'eligible' => count($comparisons) > 0 && $eligibleCount === count($comparisons),
                'eligible_count' => $eligibleCount,
                'total_compared' => count($comparisons),
                'threshold_percent' => $thresholdPercent,
            ],
        ];
    }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if ($count % 2 === 0) {
            return ((float) $values[$mid - 1] + (float) $values[$mid]) / 2.0;
        }
        return (float) $values[$mid];
    }
}
