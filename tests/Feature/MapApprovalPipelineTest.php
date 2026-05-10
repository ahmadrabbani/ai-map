<?php

namespace Tests\Feature;

use App\Models\MapDrawing;
use App\Models\MapEntity;
use App\Services\MapApproval\CadSemanticMappingService;
use App\Services\MapApproval\GeometryCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapApprovalPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_semantic_mapping_and_geometry_use_external_walls_for_coverage(): void
    {
        $drawing = MapDrawing::create([
            'original_file_path' => 'dummy.dxf',
            'dxf_file_path' => 'dummy.dxf',
            'status' => 'uploaded',
            'mapping_status' => 'pending',
            'validation_status' => 'pending',
        ]);

        $fixturePath = base_path('tests/Fixtures/map_entities_ground.json');
        $entities = json_decode(file_get_contents($fixturePath), true);
        foreach ($entities as $entity) {
            MapEntity::create([
                'map_drawing_id' => $drawing->id,
                'handle' => $entity['handle'],
                'layer_name' => $entity['layer_name'],
                'entity_type' => $entity['entity_type'],
                'geometry_json' => ['points' => $entity['points']],
                'bbox_json' => $entity['bbox'],
                'area' => $entity['area'],
                'perimeter' => $entity['perimeter'],
                'is_closed' => $entity['is_closed'],
                'mapping_status' => 'unmapped',
            ]);
        }

        $mappingSummary = app(CadSemanticMappingService::class)->mapDrawing($drawing->fresh('entities'));
        $this->assertIsArray($mappingSummary);

        $plot = $drawing->fresh()->entities()->where('layer_name', 'SITE-PL')->first();
        $groundExt = $drawing->fresh()->entities()->where('layer_name', 'GF-WE')->first();
        $groundInt = $drawing->fresh()->entities()->where('layer_name', 'GF-WI')->first();

        $this->assertSame('plot_boundary', $plot->semantic_entity);
        $this->assertSame('ground_floor_covered_polygon', $groundExt->semantic_entity);
        $this->assertNotSame('ground_floor_covered_polygon', $groundInt->semantic_entity);

        $geometry = app(GeometryCalculationService::class)->calculate($drawing->fresh('entities'));
        $this->assertSame(2250.0, $geometry['plot_area_sqft']['value']);
        $this->assertSame(1050.0, $geometry['ground_floor_area_sqft']['value']);
        $this->assertSame(46.67, $geometry['ground_coverage_percent']['value']);
    }
}

