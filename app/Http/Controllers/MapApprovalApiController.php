<?php

namespace App\Http\Controllers;

use App\Models\MapDrawing;
use App\Services\MapApproval\CadSemanticMappingService;
use App\Services\MapApproval\GeometryCalculationService;
use App\Services\MapApproval\LayerReviewSuggestionService;
use App\Services\MapApproval\MapApprovalPipelineService;
use App\Services\MapApproval\MapApprovalReportService;
use App\Services\MapApproval\RuleToLayerSchemaService;
use App\Services\MapApproval\RuleValidationService;
use Illuminate\Http\Request;

class MapApprovalApiController extends Controller
{
    public function __construct(
        private readonly MapApprovalPipelineService $pipelineService,
        private readonly CadSemanticMappingService $mappingService,
        private readonly LayerReviewSuggestionService $suggestionService,
        private readonly GeometryCalculationService $geometryService,
        private readonly RuleValidationService $ruleValidationService,
        private readonly MapApprovalReportService $reportService,
        private readonly RuleToLayerSchemaService $schemaService
    ) {
    }

    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:dwg,dxf'],
            'application_id' => ['nullable', 'integer'],
        ]);

        $run = $this->pipelineService->uploadAndMap($data['file'], $data['application_id'] ?? null);

        return response()->json([
            'drawing_id' => $run['drawing']->id,
            'mapping_status' => $run['drawing']->mapping_status,
            'summary' => $run['mapping_summary'],
        ]);
    }

    public function entities(MapDrawing $drawing)
    {
        return response()->json([
            'drawing_id' => $drawing->id,
            'entities' => $drawing->entities()->get([
                'handle',
                'layer_name',
                'entity_type',
                'semantic_entity',
                'processing_role',
                'geometry_json',
                'area',
                'perimeter',
                'bbox_json',
                'confidence_score',
                'mapping_status',
                'is_ignored',
            ]),
        ]);
    }

    public function mappingSummary(MapDrawing $drawing)
    {
        return response()->json([
            'drawing_id' => $drawing->id,
            'summary' => $this->mappingService->summary($drawing),
        ]);
    }

    public function manualMap(Request $request, MapDrawing $drawing)
    {
        $data = $request->validate([
            'semantic_entity' => ['nullable', 'string'],
            'semantic_layer_name' => ['nullable', 'string'],
            'entity_handle' => ['required', 'string'],
            'source' => ['nullable', 'string'],
            'confidence_score' => ['nullable', 'numeric'],
        ]);

        $semantic = $data['semantic_entity'] ?? $this->publicSemanticToInternal($data['semantic_layer_name'] ?? null);
        if (! $semantic) {
            return response()->json(['message' => 'semantic_entity or semantic_layer_name is required'], 422);
        }

        $summary = $this->mappingService->manualMap(
            $drawing->fresh('entities'),
            $semantic,
            $data['entity_handle'],
            optional($request->user())->email ?? optional($request->user())->name,
            $data['source'] ?? 'user_selected',
            isset($data['confidence_score']) ? (float) $data['confidence_score'] : null
        );

        $entity = $drawing->fresh('entities')->entities->firstWhere('handle', $data['entity_handle']);
        return response()->json([
            'drawing_id' => $drawing->id,
            'summary' => $summary,
            'mapping' => [
                'semantic_layer_name' => $data['semantic_layer_name'] ?? null,
                'semantic_entity' => $semantic,
                'entity_handle' => $data['entity_handle'],
                'geometry_json' => $entity?->geometry_json,
                'confidence_score' => isset($data['confidence_score']) ? (float) $data['confidence_score'] : ($entity?->confidence_score),
                'source' => $data['source'] ?? 'user_selected',
            ],
        ]);
    }

    public function layerSuggestions(MapDrawing $drawing)
    {
        return response()->json([
            'drawing_id' => $drawing->id,
            'layers' => $this->suggestionService->buildSuggestions($drawing),
        ]);
    }

    public function runValidation(MapDrawing $drawing)
    {
        $mandatory = $this->mandatorySemanticEntities();
        $missingMandatory = [];
        foreach ($mandatory as $semantic) {
            $mapped = $drawing->entities()
                ->where('semantic_entity', $semantic)
                ->whereIn('mapping_status', ['auto_mapped', 'manual_mapped', 'expert_verified'])
                ->exists();
            if (! $mapped) {
                $missingMandatory[] = $semantic;
            }
        }

        $summary = $this->mappingService->summary($drawing);
        if (! empty($summary['blocking_issues']) || ! empty($missingMandatory)) {
            return response()->json([
                'drawing_id' => $drawing->id,
                'status' => 'blocked',
                'message' => 'Required semantic entities are missing. Confirm mandatory semantic layers before validation.',
                'summary' => $summary,
                'missing_mandatory_semantic_entities' => $missingMandatory,
            ], 422);
        }

        $geometry = $this->geometryService->calculate($drawing->fresh('entities'));
        $rules = $this->ruleValidationService->validate($drawing->fresh('entities'), $geometry);
        $drawing->validation_status = 'validated';
        $drawing->status = 'validated';
        $drawing->save();
        $report = $this->reportService->generate($drawing->fresh(['entities', 'geometryResults', 'ruleResults']));

        return response()->json($report);
    }

    public function report(MapDrawing $drawing)
    {
        return response()->json(
            $this->reportService->generate($drawing->fresh(['entities', 'geometryResults', 'ruleResults']))
        );
    }

    private function publicSemanticToInternal(?string $name): ?string
    {
        $map = [
            'PLOT_BOUNDARY' => 'plot_boundary',
            'GROUND_FLOOR_FOOTPRINT' => 'ground_floor_covered_polygon',
            'BASEMENT_FOOTPRINT' => 'basement_covered_polygon',
            'FIRST_FLOOR_FOOTPRINT' => 'first_floor_covered_polygon',
            'ROAD' => 'road',
            'FRONT_SETBACK' => 'front_setback',
            'REAR_SETBACK' => 'rear_setback',
            'LEFT_SIDE_SETBACK' => 'left_side_setback',
            'RIGHT_SIDE_SETBACK' => 'right_side_setback',
        ];

        return $name && isset($map[$name]) ? $map[$name] : null;
    }

    private function mandatorySemanticEntities(): array
    {
        $required = ['plot_boundary', 'ground_floor_covered_polygon'];
        $schema = $this->schemaService->load();
        $policy = $schema['validation_blocking_policy']['required_semantic_entities'] ?? [];
        if (is_array($policy)) {
            foreach ($policy as $item) {
                if ($item === 'road' || $item === 'ROAD') {
                    $required[] = 'road';
                }
            }
        }

        return array_values(array_unique($required));
    }
}
