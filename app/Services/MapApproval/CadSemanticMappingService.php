<?php

namespace App\Services\MapApproval;

use App\Models\MapDrawing;
use App\Models\MapEntity;
use App\Models\MapEntityMapping;

class CadSemanticMappingService
{
    public function __construct(
        private readonly RuleToLayerSchemaService $schemaService
    ) {
    }

    public function mapDrawing(MapDrawing $drawing): array
    {
        $schema = $this->schemaService->load();
        $semantic = $schema['semantic_entities'] ?? [];
        $layerDefs = $schema['layer_definitions'] ?? [];
        $scoring = $schema['entity_selection_scoring'] ?? [];
        $autoAccept = (float) ($scoring['auto_accept_score'] ?? 85);
        $needsReview = (float) ($scoring['needs_review_score'] ?? 60);
        $ambiguityDelta = (float) ($scoring['ambiguity_delta_for_review'] ?? 7);

        $requiredMissing = [];
        $autoMappedCount = 0;
        $needsReviewCount = 0;
        $ignoredCount = 0;

        foreach ($drawing->entities as $entity) {
            if (in_array($entity->mapping_status, ['manual_mapped', 'expert_verified'], true)) {
                continue;
            }
            $layer = $entity->layer_name;
            $def = $this->definitionForLayer($layer, $layerDefs);
            $processingRole = $def['processing_role'] ?? null;
            $entity->processing_role = $processingRole;

            if ($this->isIgnoredLayer($processingRole)) {
                $entity->semantic_entity = null;
                $entity->confidence_score = 100;
                $entity->mapping_status = 'ignored';
                $entity->mapping_source = 'auto';
                $entity->is_ignored = true;
                $entity->save();
                $ignoredCount++;
                continue;
            }

            $best = $this->bestSemanticMatch($entity, $semantic, $scoring['weights'] ?? []);

            if ($best === null) {
                $entity->semantic_entity = null;
                $entity->confidence_score = 0;
                $entity->mapping_status = 'unmapped';
                $entity->mapping_source = null;
                $entity->is_ignored = false;
                $entity->save();
                continue;
            }

            $entity->semantic_entity = $best['semantic_entity'];
            $entity->confidence_score = $best['score'];
            $entity->is_ignored = false;

            if ($best['score'] >= $autoAccept) {
                $entity->mapping_status = 'auto_mapped';
                $entity->mapping_source = 'auto';
                $autoMappedCount++;
            } elseif ($best['score'] >= $needsReview) {
                $entity->mapping_status = 'needs_expert_review';
                $entity->mapping_source = 'ai_suggested';
                $needsReviewCount++;
            } else {
                $entity->mapping_status = 'unmapped';
                $entity->mapping_source = null;
            }
            $entity->save();

            if (in_array($entity->mapping_status, ['auto_mapped', 'needs_expert_review'], true)) {
                MapEntityMapping::updateOrCreate(
                    [
                        'map_drawing_id' => $drawing->id,
                        'semantic_entity' => $best['semantic_entity'],
                        'entity_handle' => $entity->handle,
                    ],
                    [
                        'mapping_source' => $entity->mapping_status === 'auto_mapped' ? 'auto' : 'ai_suggested',
                        'confidence_score' => $best['score'],
                    ]
                );
            }
        }

        $plotCandidate = $this->pickWinningCandidate($drawing, 'plot_boundary', $autoAccept, $needsReview, $ambiguityDelta);
        $plotBBox = $plotCandidate?->bbox_json;
        foreach ([
            'ground_floor_covered_polygon',
            'basement_covered_polygon',
            'first_floor_covered_polygon',
            'second_floor_covered_polygon',
            'ground_porch_polygon',
        ] as $semanticEntity) {
            $this->pickWinningCandidate($drawing, $semanticEntity, $autoAccept, $needsReview, $ambiguityDelta, $plotBBox);
        }

        foreach ($semantic as $semanticKey => $cfg) {
            if (($cfg['required'] ?? false) !== true) {
                continue;
            }
            $found = $drawing->entities()
                ->where('semantic_entity', $semanticKey)
                ->whereIn('mapping_status', ['auto_mapped', 'manual_mapped', 'expert_verified'])
                ->exists();
            if (! $found) {
                $requiredMissing[] = $semanticKey;
            }
        }

        $mappingStatus = empty($requiredMissing) && $needsReviewCount === 0
            ? 'auto_mapped'
            : 'needs_expert_review';

        $drawing->mapping_status = $mappingStatus;
        $drawing->metadata_json = array_merge($drawing->metadata_json ?? [], [
            'mapping_summary' => [
                'auto_mapped_count' => $autoMappedCount,
                'needs_review_count' => $needsReviewCount,
                'ignored_count' => $ignoredCount,
                'missing_required_entities' => $requiredMissing,
            ],
        ]);
        $drawing->save();

        return $drawing->metadata_json['mapping_summary'];
    }

    public function manualMap(
        MapDrawing $drawing,
        string $semanticEntity,
        string $handle,
        ?string $verifiedBy = null,
        ?string $source = null,
        ?float $confidence = null
    ): array
    {
        $entity = $drawing->entities()->where('handle', $handle)->firstOrFail();
        $entity->semantic_entity = $semanticEntity;
        $entity->mapping_status = 'expert_verified';
        $entity->mapping_source = $source ?: 'user_selected';
        $entity->confidence_score = $confidence ?? 100;
        $entity->is_ignored = $semanticEntity === 'ignore_entity';
        if ($semanticEntity === 'ignore_entity') {
            $entity->processing_role = 'ignore_for_approval_geometry';
        }
        $entity->save();

        MapEntityMapping::updateOrCreate(
            [
                'map_drawing_id' => $drawing->id,
                'semantic_entity' => $semanticEntity,
                'entity_handle' => $entity->handle,
            ],
            [
                'mapping_source' => $source ?: 'user_selected',
                'confidence_score' => $confidence ?? 100,
                'verified_by' => $verifiedBy,
                'verified_at' => now(),
            ]
        );

        return $this->mapDrawing($drawing->fresh('entities'));
    }

    public function summary(MapDrawing $drawing): array
    {
        $schema = $this->schemaService->load();
        $required = collect($schema['semantic_entities'] ?? [])
            ->filter(fn ($cfg) => ($cfg['required'] ?? false) === true)
            ->keys()
            ->values()
            ->all();

        $autoMapped = $drawing->entities()->where('mapping_status', 'auto_mapped')->count();
        $needsReview = $drawing->entities()->where('mapping_status', 'needs_expert_review')->count();
        $ignored = $drawing->entities()->where('mapping_status', 'ignored')->count();

        $missing = [];
        foreach ($required as $key) {
            $found = $drawing->entities()
                ->where('semantic_entity', $key)
                ->whereIn('mapping_status', ['auto_mapped', 'manual_mapped', 'expert_verified'])
                ->exists();
            if (! $found) {
                $missing[] = $key;
            }
        }

        $blockingIssues = [];
        if (in_array('plot_boundary', $missing, true)) {
            $blockingIssues[] = 'plot_boundary_missing';
        }
        if (in_array('ground_floor_covered_polygon', $missing, true)) {
            $blockingIssues[] = 'ground_floor_covered_polygon_missing';
        }

        return [
            'auto_mapped_entities' => $autoMapped,
            'needs_review_entities' => $needsReview,
            'missing_required_entities' => $missing,
            'ignored_layers' => $ignored,
            'blocking_issues' => $blockingIssues,
        ];
    }

    private function isIgnoredLayer(?string $processingRole): bool
    {
        return in_array($processingRole, [
            'ignore_for_approval_geometry',
            'annotation_only',
            'display_only',
        ], true);
    }

    private function bestSemanticMatch(MapEntity $entity, array $semanticDefs, array $weights): ?array
    {
        $best = null;
        foreach ($semanticDefs as $semanticKey => $cfg) {
            $sourceLayers = $cfg['source_layers'] ?? [];
            if (! $this->layerMatchesAny($entity->layer_name, $sourceLayers)) {
                continue;
            }

            $allowedTypes = $cfg['allowed_entity_types'] ?? [];
            if (! empty($allowedTypes) && ! in_array($entity->entity_type, $allowedTypes, true)) {
                continue;
            }

            if (($cfg['must_be_closed'] ?? false) && ! $entity->is_closed) {
                continue;
            }

            $score = (float) ($weights['layer_match'] ?? 45);
            if ($this->layerMatchesAny($entity->layer_name, $sourceLayers)) {
                $score += 15;
            }
            if ($this->layerMatchesAny($entity->layer_name, [($cfg['preferred_layer'] ?? null)])) {
                $score += (float) ($weights['preferred_layer_bonus'] ?? 15);
            }
            if ($entity->is_closed) {
                $score += (float) ($weights['closed_bonus'] ?? 20);
            }
            $score += (float) ($weights['entity_type_bonus'] ?? 10);
            if (str_contains($semanticKey, 'polygon')) {
                $score += (float) ($weights['inside_plot_bonus'] ?? 10);
            }
            $score = min(100.0, $score);

            if ($best === null || $score > $best['score']) {
                $best = [
                    'semantic_entity' => $semanticKey,
                    'score' => round($score, 2),
                ];
            }
        }

        return $best;
    }

    private function definitionForLayer(?string $layerName, array $definitions): ?array
    {
        foreach ($definitions as $schemaLayer => $definition) {
            if ($this->normalizeLayerName($schemaLayer) === $this->normalizeLayerName($layerName)) {
                return is_array($definition) ? $definition : null;
            }
        }

        return null;
    }

    private function layerMatchesAny(?string $layerName, array $schemaLayers): bool
    {
        $normalized = $this->normalizeLayerName($layerName);
        if ($normalized === '') {
            return false;
        }

        foreach ($schemaLayers as $schemaLayer) {
            if ($schemaLayer === null || $schemaLayer === '') {
                continue;
            }
            if ($normalized === $this->normalizeLayerName((string) $schemaLayer)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLayerName(?string $layerName): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', (string) ($layerName ?? ''));
        $value = trim((string) $value);
        $value = preg_replace('/^\d+\s*[\.\-_\):\s]+\s*/', '', $value);
        $value = preg_replace('/[-_]+/', ' ', (string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return strtolower(trim((string) $value));
    }

    private function pickWinningCandidate(
        MapDrawing $drawing,
        string $semanticEntity,
        float $autoAccept,
        float $needsReview,
        float $ambiguityDelta,
        ?array $plotBBox = null
    ): ?MapEntity {
        $candidates = $drawing->entities()
            ->where('semantic_entity', $semanticEntity)
            ->whereIn('mapping_status', ['auto_mapped', 'needs_expert_review'])
            ->orderByDesc('confidence_score')
            ->orderByDesc('area')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($plotBBox && ! $this->isInsidePlot($candidate->bbox_json, $plotBBox)) {
                $candidate->mapping_status = 'needs_expert_review';
                $candidate->confidence_score = min((float) ($candidate->confidence_score ?? 0), $needsReview);
                $candidate->mapping_source = 'ai_suggested';
                $candidate->save();
            }
        }

        $candidates = $drawing->entities()
            ->where('semantic_entity', $semanticEntity)
            ->whereIn('mapping_status', ['auto_mapped', 'needs_expert_review'])
            ->orderByDesc('confidence_score')
            ->orderByDesc('area')
            ->get();
        if ($candidates->isEmpty()) {
            return null;
        }

        $winner = $candidates->first();
        $runner = $candidates->skip(1)->first();
        $scoreClose = $runner && abs((float) $winner->confidence_score - (float) $runner->confidence_score) < $ambiguityDelta;
        $winnerArea = (float) ($winner->area ?? 0);
        $runnerArea = (float) ($runner?->area ?? 0);
        $areaClose = $winnerArea > 0 && $runnerArea > 0 && ($runnerArea / $winnerArea) >= 0.9;
        $ambiguous = $scoreClose && ($areaClose || $winnerArea <= 0);

        foreach ($candidates as $idx => $candidate) {
            if ($idx === 0 && ! $ambiguous && (float) $winner->confidence_score >= $autoAccept) {
                $candidate->mapping_status = 'auto_mapped';
                $candidate->mapping_source = 'auto';
            } else {
                $candidate->mapping_status = 'needs_expert_review';
                $candidate->mapping_source = 'ai_suggested';
            }
            $candidate->save();
        }

        return $winner;
    }

    private function isInsidePlot(?array $bbox, ?array $plotBBox): bool
    {
        if (! is_array($bbox) || ! is_array($plotBBox)) {
            return false;
        }
        $eps = 0.001;
        return
            (($bbox['min_x'] ?? INF) >= (($plotBBox['min_x'] ?? -INF) - $eps)) &&
            (($bbox['min_y'] ?? INF) >= (($plotBBox['min_y'] ?? -INF) - $eps)) &&
            (($bbox['max_x'] ?? -INF) <= (($plotBBox['max_x'] ?? INF) + $eps)) &&
            (($bbox['max_y'] ?? -INF) <= (($plotBBox['max_y'] ?? INF) + $eps));
    }
}
