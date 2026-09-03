<?php

namespace App\Http\Controllers;

use App\Models\CadPrediction;
use App\Models\CadRule;
use App\Models\CadSubmission;
use App\Models\CadTag;
use App\Models\CadTagAudit;
use App\Services\CadEvaluationService;
use App\Services\GeometryService;
use App\Services\RuleEngineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CadTaggingApiController extends Controller
{
    private const REVIEW_STATES = ['ai_suggested', 'confirmed', 'corrected', 'rejected', 'uncertain', 'verified'];

    public function workspace(Request $request, int $id, RuleEngineService $ruleEngine)
    {
        $submission = CadSubmission::findOrFail($id);
        $query = $submission->predictions()->with('tag')->orderByDesc('confidence')->orderBy('id');
        foreach (['status', 'floor', 'cad_layer'] as $filter) {
            if ($value = $request->query($filter)) $query->where($filter, $value);
        }
        if ($request->filled('confidence_min')) $query->where('confidence', '>=', (float) $request->query('confidence_min'));
        if ($request->filled('confidence_max')) $query->where('confidence', '<=', (float) $request->query('confidence_max'));
        $predictions = $query->limit(2000)->get();
        $total = $submission->predictions()->count();
        $reviewed = $submission->predictions()->whereNotIn('status', ['unreviewed', 'ai_suggested'])->count();

        $rules = CadRule::where('active', true)->orderBy('rule_code')->get();
        $tags = $submission->tags()->with('audits')->orderBy('id')->get();
        $tags->each(fn (CadTag $tag) => $tag->setAttribute('validation_messages', $ruleEngine->validateTag($tag, $rules)));
        $tagsByPrediction = $tags->whereNotNull('cad_prediction_id')->keyBy('cad_prediction_id');
        $predictions->each(fn (CadPrediction $prediction) => $prediction->setRelation('tag', $tagsByPrediction->get($prediction->id)));

        return response()->json([
            'predictions' => $predictions,
            'tags' => $tags,
            'rules' => $rules,
            'progress' => [
                'reviewed' => $reviewed, 'total' => $total,
                'percent' => $total ? round($reviewed / $total * 100, 1) : 0,
                'by_status' => $submission->predictions()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            ],
        ]);
    }

    public function importPredictions(Request $request, int $id)
    {
        $submission = CadSubmission::findOrFail($id);
        $data = $request->validate([
            'predictions' => 'required|array|max:5000',
            'predictions.*.label_key' => 'required|string|max:120',
            'predictions.*.label_name' => 'nullable|string|max:255',
            'predictions.*.confidence' => 'nullable|numeric|min:0|max:1',
            'predictions.*.geometry' => 'required|array',
            'predictions.*.model_version' => 'nullable|string|max:120',
            'predictions.*.cad_handle' => 'nullable|string|max:255',
            'predictions.*.cad_layer' => 'nullable|string|max:255',
            'predictions.*.floor' => 'nullable|string|max:120',
            'predictions.*.metadata' => 'nullable|array',
        ]);
        $result = DB::transaction(function () use ($submission, $data) {
            $evidenceKey = function (?array $metadata, ?string $cadHandle): string {
                $handle = trim((string) (data_get($metadata, 'cad_text_evidence.cad_handle') ?: $cadHandle));
                if ($handle !== '') {
                    return 'handle:'.$handle;
                }
                $text = trim(strtolower((string) data_get($metadata, 'cad_text_evidence.raw_text', '')));
                $x = data_get($metadata, 'cad_text_evidence.x');
                $y = data_get($metadata, 'cad_text_evidence.y');
                return ($text !== '' && is_numeric($x) && is_numeric($y))
                    ? 'point:'.round((float) $x, 4).':'.round((float) $y, 4).':'.$text
                    : '';
            };
            $existingPredictions = $submission->predictions()->get();
            $existingBySourceKey = $existingPredictions
                ->filter(fn (CadPrediction $prediction) => filled(data_get($prediction->metadata, 'source_key')))
                ->keyBy(fn (CadPrediction $prediction) => (string) data_get($prediction->metadata, 'source_key'));
            $existingByEvidenceKey = $existingPredictions
                ->filter(fn (CadPrediction $prediction) => data_get($prediction->metadata, 'source') === 'native_cad_text')
                ->mapWithKeys(function (CadPrediction $prediction) use ($evidenceKey) {
                    $key = $evidenceKey($prediction->metadata, $prediction->cad_handle);
                    return $key !== '' ? [$key => $prediction] : [];
                });
            $created = 0;
            $updated = 0;
            $preserved = 0;

            foreach ($data['predictions'] as $prediction) {
                $values = [
                    'label_key' => $prediction['label_key'], 'label_name' => $prediction['label_name'] ?? null,
                    'geometry_type' => $prediction['geometry']['type'] ?? null, 'geometry_json' => $prediction['geometry'],
                    'confidence' => $prediction['confidence'] ?? null, 'model_version' => $prediction['model_version'] ?? null,
                    'cad_handle' => $prediction['cad_handle'] ?? null, 'cad_layer' => $prediction['cad_layer'] ?? null,
                    'floor' => $prediction['floor'] ?? null, 'metadata' => $prediction['metadata'] ?? null,
                ];
                $sourceKey = trim((string) data_get($prediction, 'metadata.source_key', ''));
                $incomingEvidenceKey = $evidenceKey(
                    $prediction['metadata'] ?? null,
                    $prediction['cad_handle'] ?? null
                );
                $existing = ($sourceKey !== '' ? $existingBySourceKey->get($sourceKey) : null)
                    ?: ($incomingEvidenceKey !== '' ? $existingByEvidenceKey->get($incomingEvidenceKey) : null);

                if ($existing) {
                    // Refresh machine suggestions when geometry extraction improves, but never
                    // overwrite a decision already made by an officer.
                    if (in_array($existing->status, ['unreviewed', 'ai_suggested'], true)) {
                        $existing->fill($values)->save();
                        $updated++;
                    } else {
                        $preserved++;
                    }
                    continue;
                }

                $createdPrediction = $submission->predictions()->create($values + ['status' => 'ai_suggested']);
                if ($sourceKey !== '') {
                    $existingBySourceKey->put($sourceKey, $createdPrediction);
                }
                if ($incomingEvidenceKey !== '') {
                    $existingByEvidenceKey->put($incomingEvidenceKey, $createdPrediction);
                }
                $created++;
            }

            return compact('created', 'updated', 'preserved');
        });

        return response()->json($result, 201);
    }

    public function listTags(int $id)
    {
        $submission = CadSubmission::findOrFail($id);
        return response()->json(['tags' => $submission->tags()->with('audits')->orderBy('id')->get()]);
    }

    public function createTag(Request $request, int $id, GeometryService $geometry)
    {
        $submission = CadSubmission::findOrFail($id);
        $data = $this->validateTag($request);
        $measurements = $geometry->measurements($data['geometry_json'], $data['unit'] ?? null, isset($data['scale']) ? (float) $data['scale'] : null);
        $userId = $request->user()?->id;
        $tag = DB::transaction(function () use ($submission, $data, $measurements, $userId) {
            $tag = CadTag::create(array_merge($data, $measurements, [
                'cad_submission_id' => $submission->id, 'source' => 'manual',
                'status' => $data['status'] ?? 'confirmed', 'verification_level' => 'user_correction',
                'created_by' => $userId, 'updated_by' => $userId,
                'drawing_hash' => $this->drawingHash($submission), 'dataset_split' => $this->datasetSplit($submission),
            ]));
            $this->audit($submission, $tag, null, 'created', null, $tag->toArray(), $userId);
            return $tag;
        });
        return response()->json(['tag' => $tag], 201);
    }

    public function updateTag(Request $request, int $id, int $tagId, GeometryService $geometry)
    {
        $submission = CadSubmission::findOrFail($id);
        $tag = $submission->tags()->findOrFail($tagId);
        abort_if($tag->locked, 409, 'Gold-standard tags are locked. Create a new reviewed version instead.');
        $data = $this->validateTag($request, true);
        $before = $tag->toArray();
        if (isset($data['geometry_json'])) {
            $data = array_merge($data, $geometry->measurements($data['geometry_json'], $data['unit'] ?? $tag->unit, isset($data['scale']) ? (float) $data['scale'] : (float) $tag->scale));
        }
        $tag->fill(array_merge($data, ['updated_by' => $request->user()?->id]))->save();
        $this->audit($submission, $tag, $tag->prediction, 'updated', $before, $tag->fresh()->toArray(), $request->user()?->id);
        return response()->json(['tag' => $tag->fresh()]);
    }

    public function deleteTag(Request $request, int $id, int $tagId)
    {
        $submission = CadSubmission::findOrFail($id);
        $tag = $submission->tags()->findOrFail($tagId);
        abort_unless($tag->source === 'manual' && ! $tag->locked, 409, 'Only unlocked manually created tags can be deleted.');
        $before = $tag->toArray();
        $this->audit($submission, $tag, $tag->prediction, 'deleted', $before, null, $request->user()?->id);
        $tag->delete();
        return response()->json(['deleted' => true]);
    }

    public function reviewPrediction(Request $request, int $id, int $predictionId, GeometryService $geometry)
    {
        $submission = CadSubmission::findOrFail($id);
        $prediction = $submission->predictions()->findOrFail($predictionId);
        $data = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'correct', 'reject', 'uncertain'])],
            'label_key' => 'nullable|string|max:120', 'label_name' => 'nullable|string|max:255',
            'geometry_json' => 'nullable|array', 'unit' => 'nullable|string|max:20',
            'scale' => 'nullable|numeric|min:0.00000001', 'unit_confirmed' => 'nullable|boolean',
            'floor' => 'nullable|string|max:120',
            'observed_count' => 'nullable|integer|min:0|max:100000',
            'area_sq_ft' => 'nullable|numeric|min:0|max:1000000000',
            'measurement_method' => 'nullable|string|max:120',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $result = DB::transaction(function () use ($request, $submission, $prediction, $data, $geometry) {
            $before = $prediction->toArray();
            $status = match ($data['action']) { 'confirm' => 'confirmed', 'correct' => 'corrected', 'reject' => 'rejected', default => 'uncertain' };
            $finalLabel = $data['action'] === 'correct' ? ($data['label_key'] ?? null) : $prediction->label_key;
            abort_if($data['action'] === 'correct' && ! $finalLabel, 422, 'A corrected prediction requires a label.');
            $proposedMeasurements = (array) data_get($prediction->metadata, 'measurement_suggestion', []);
            $observedCount = array_key_exists('observed_count', $data)
                ? $data['observed_count']
                : data_get($proposedMeasurements, 'observed_count');
            $areaSqFt = array_key_exists('area_sq_ft', $data)
                ? $data['area_sq_ft']
                : data_get($proposedMeasurements, 'area_sq_ft');
            $measurementMethod = $data['measurement_method'] ?? data_get($proposedMeasurements, 'method');
            $predictionMetadata = (array) ($prediction->metadata ?? []);
            if (in_array($data['action'], ['confirm', 'correct'], true)) {
                data_set($predictionMetadata, 'reviewed_measurements', array_filter([
                    'observed_count' => is_numeric($observedCount) ? (int) $observedCount : null,
                    'area_sq_ft' => is_numeric($areaSqFt) ? round((float) $areaSqFt, 4) : null,
                    'method' => $measurementMethod,
                    'officer_edited' => array_key_exists('observed_count', $data) || array_key_exists('area_sq_ft', $data),
                    'reviewed_at' => now()->toIso8601String(),
                ], fn ($value) => $value !== null));
            }
            $prediction->update([
                'status' => $status, 'final_label_key' => $finalLabel, 'review_action' => $data['action'],
                'label_name' => $data['label_name'] ?? $prediction->label_name,
                'floor' => $data['floor'] ?? $prediction->floor,
                'metadata' => $predictionMetadata,
                'reviewed_by' => $request->user()?->id, 'reviewed_at' => now(),
            ]);
            $tag = null;
            if (in_array($data['action'], ['confirm', 'correct'], true)) {
                $tagGeometry = $data['geometry_json'] ?? $prediction->geometry_json;
                $unit = $data['unit'] ?? data_get($prediction->metadata, 'unit');
                $scale = $data['scale'] ?? data_get($prediction->metadata, 'scale');
                $measurements = $geometry->measurements($tagGeometry, $unit, $scale ? (float) $scale : null);
                if (is_numeric($areaSqFt)) {
                    $measurements['area_sq_ft'] = round((float) $areaSqFt, 4);
                    $measurements['area_sq_m'] = round((float) $areaSqFt * 0.09290304, 4);
                }
                $measurementAttributes = array_filter([
                    'observed_count' => is_numeric($observedCount) ? (int) $observedCount : null,
                    'measurement_method' => $measurementMethod,
                    'machine_suggestion' => $proposedMeasurements ?: null,
                    'officer_edited' => array_key_exists('observed_count', $data) || array_key_exists('area_sq_ft', $data),
                ], fn ($value) => $value !== null);
                $tag = CadTag::updateOrCreate(
                    ['cad_submission_id' => $submission->id, 'cad_prediction_id' => $prediction->id],
                    array_merge($measurements, [
                        'label_key' => $finalLabel, 'label_name' => $data['label_name'] ?? $prediction->label_name,
                        'geometry_type' => $tagGeometry['type'] ?? $prediction->geometry_type, 'geometry_json' => $tagGeometry,
                        'cad_handles' => array_values(array_filter([$prediction->cad_handle])), 'cad_layer' => $prediction->cad_layer,
                        'floor' => $prediction->floor, 'unit' => $unit, 'scale' => $scale,
                        'attributes' => $measurementAttributes,
                        'unit_confirmed' => (bool) ($data['unit_confirmed'] ?? false), 'status' => $status,
                        'verification_level' => 'user_correction', 'source' => 'ai_prediction',
                        'ai_label_key' => $prediction->label_key, 'ai_confidence' => $prediction->confidence,
                        'model_version' => $prediction->model_version, 'remarks' => $data['remarks'] ?? null,
                        'updated_by' => $request->user()?->id, 'created_by' => $request->user()?->id,
                        'drawing_hash' => $this->drawingHash($submission), 'dataset_split' => $this->datasetSplit($submission),
                    ])
                );
            }
            $this->audit($submission, $tag, $prediction, $data['action'], $before, $prediction->fresh()->toArray(), $request->user()?->id, $data['remarks'] ?? null);
            return ['prediction' => $prediction->fresh(), 'tag' => $tag];
        });
        return response()->json($result);
    }

    public function bulkReview(Request $request, int $id, GeometryService $geometry)
    {
        $submission = CadSubmission::findOrFail($id);
        $data = $request->validate([
            'prediction_ids' => 'nullable|array|max:2000', 'prediction_ids.*' => 'integer',
            'confidence_threshold' => 'nullable|numeric|min:0|max:1',
            'action' => ['required', Rule::in(['confirm', 'correct', 'reject', 'uncertain'])],
            'label_key' => 'nullable|string|max:120', 'unit' => 'nullable|string|max:20',
            'scale' => 'nullable|numeric|min:0.00000001', 'unit_confirmed' => 'nullable|boolean',
        ]);
        abort_if($data['action'] === 'correct' && empty($data['label_key']), 422, 'Bulk correction requires a label.');
        $query = $submission->predictions()->whereIn('status', ['unreviewed', 'ai_suggested', 'uncertain']);
        if (! empty($data['prediction_ids'])) $query->whereIn('id', $data['prediction_ids']);
        if (isset($data['confidence_threshold'])) $query->where('confidence', '>=', $data['confidence_threshold']);
        $predictions = $query->get();
        foreach ($predictions as $prediction) {
            $child = Request::create('', 'POST', array_merge($data, ['action' => $data['action']]));
            $child->setUserResolver(fn () => $request->user());
            $this->reviewPrediction($child, $submission->id, $prediction->id, $geometry);
        }
        return response()->json(['reviewed' => $predictions->count()]);
    }

    public function submitVerified(Request $request, int $id)
    {
        abort_unless($request->user(), 403, 'An authenticated expert is required to verify training data.');
        $submission = CadSubmission::findOrFail($id);
        $data = $request->validate(['tag_ids' => 'nullable|array', 'tag_ids.*' => 'integer']);
        $query = $submission->tags()->whereIn('status', ['confirmed', 'corrected']);
        if (! empty($data['tag_ids'])) $query->whereIn('id', $data['tag_ids']);
        $tags = $query->get();
        foreach ($tags as $tag) {
            $before = $tag->toArray();
            $tag->update(['status' => 'verified', 'verification_level' => 'expert_verified', 'verified_by' => $request->user()?->id, 'verified_at' => now()]);
            $this->audit($submission, $tag, $tag->prediction, 'expert_verified', $before, $tag->fresh()->toArray(), $request->user()?->id);
        }
        return response()->json(['verified' => $tags->count()]);
    }

    public function promoteGold(Request $request, int $id, int $tagId)
    {
        abort_unless($request->user(), 403, 'An authenticated second reviewer is required for gold-standard approval.');
        $submission = CadSubmission::findOrFail($id);
        $tag = $submission->tags()->findOrFail($tagId);
        abort_unless($tag->verification_level === 'expert_verified', 409, 'Only expert-verified tags can become gold standard.');
        abort_if($tag->verified_by && $tag->verified_by === $request->user()?->id, 409, 'Gold-standard approval requires a second reviewer.');
        $before = $tag->toArray();
        $tag->update(['verification_level' => 'gold_standard', 'dataset_split' => 'test', 'locked' => true, 'gold_verified_by' => $request->user()?->id, 'gold_verified_at' => now()]);
        $this->audit($submission, $tag, $tag->prediction, 'gold_standard', $before, $tag->fresh()->toArray(), $request->user()?->id);
        return response()->json(['tag' => $tag->fresh()]);
    }

    public function evaluate(Request $request, int $id, CadEvaluationService $evaluation)
    {
        $submission = CadSubmission::findOrFail($id);
        $data = $request->validate([
            'iou_threshold' => 'nullable|numeric|min:0|max:1', 'model_version' => 'nullable|string|max:120',
            'dataset_split' => 'nullable|string|in:review,validation,gold',
        ]);
        return response()->json(['run' => $evaluation->evaluate($submission, $data)]);
    }

    private function validateTag(Request $request, bool $partial = false): array
    {
        $prefix = $partial ? 'sometimes|' : 'required|';
        return $request->validate([
            'label_key' => $prefix.'string|max:120', 'label_name' => 'nullable|string|max:255',
            'geometry_type' => $prefix.'string|in:polygon,rectangle,polyline,line,point,text',
            'geometry_json' => $prefix.'array', 'attributes' => 'nullable|array',
            'cad_handles' => 'nullable|array', 'cad_handles.*' => 'string|max:255',
            'cad_layer' => 'nullable|string|max:255', 'floor' => 'nullable|string|max:120',
            'unit' => 'nullable|string|max:20', 'scale' => 'nullable|numeric|min:0.00000001',
            'unit_confirmed' => 'nullable|boolean', 'status' => ['nullable', Rule::in(self::REVIEW_STATES)],
            'remarks' => 'nullable|string|max:2000',
        ]);
    }

    private function audit(CadSubmission $submission, ?CadTag $tag, ?CadPrediction $prediction, string $action, ?array $before, ?array $after, ?int $userId, ?string $remarks = null): void
    {
        CadTagAudit::create([
            'cad_submission_id' => $submission->id, 'cad_tag_id' => $tag?->id,
            'cad_prediction_id' => $prediction?->id, 'user_id' => $userId,
            'action' => $action, 'before_json' => $before, 'after_json' => $after, 'remarks' => $remarks,
        ]);
    }

    private function drawingHash(CadSubmission $submission): string
    {
        $path = $submission->stored_dxf_path ?: $submission->stored_dwg_path;
        $absolute = $path ? storage_path('app/'.$path) : null;
        return $absolute && is_file($absolute) ? hash_file('sha256', $absolute) : hash('sha256', 'submission:'.$submission->id);
    }

    private function datasetSplit(CadSubmission $submission): string
    {
        $caseId = optional($submission->approvalPlan)->cad_approval_application_id ?? $submission->id;
        $bucket = hexdec(substr(hash('sha256', 'case:'.$caseId), 0, 8)) % 100;
        return $bucket < 70 ? 'training' : ($bucket < 85 ? 'validation' : 'test');
    }
}
