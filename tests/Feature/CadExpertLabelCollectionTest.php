<?php

namespace Tests\Feature;

use App\Models\CadSubmission;
use App\Models\CadTrainingLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CadExpertLabelCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_expert_labels_creates_a_training_label_record(): void
    {
        $submission = CadSubmission::create([
            'original_filename' => 'sample.dwg',
            'stored_dwg_path' => 'uploads/cad/sample.dwg',
            'ruleset_key' => '5_marla_residential',
        ]);

        $response = $this->post(route('admin.plan.cad-expert-label.store', ['id' => $submission->id]), [
            'plot_layer' => 'PLOT',
            'building_layer' => 'GF_OUTLINE',
            'dimension_layer' => 'DIM',
            'text_layer' => 'TEXT',
            'plot_entity_handle' => 'AA11',
            'building_entity_handle' => 'BB22',
            'front_side' => 'east',
            'notes' => 'verified by planner',
        ]);

        $response->assertRedirect(route('admin.plan.cad-expert-label.edit', ['id' => $submission->id]));

        $training = CadTrainingLabel::where('cad_submission_id', $submission->id)->first();

        $this->assertNotNull($training);
        $this->assertSame('AA11', $training->plot_boundary_handle);
        $this->assertSame('BB22', $training->building_footprint_handle);
        $this->assertSame('right', $training->front_side);
        $this->assertSame('verified by planner', $training->notes);
        $this->assertNotNull($training->verified_at);
        $this->assertSame([
            ['floor' => 0, 'handle' => 'BB22'],
        ], $training->floor_handles);
        $this->assertSame('PLOT', $training->layer_map['plot']);
        $this->assertSame('GF_OUTLINE', $training->layer_map['building']);
        $this->assertSame('GF_OUTLINE', $training->layer_map['ground_floor']);
        $this->assertSame('DIM', $training->layer_map['dimensions']);
        $this->assertSame('TEXT', $training->layer_map['text']);
    }

    public function test_saving_layer_viewer_map_merges_into_training_label(): void
    {
        $submission = CadSubmission::create([
            'original_filename' => 'sample.dwg',
            'stored_dwg_path' => 'uploads/cad/sample.dwg',
            'ruleset_key' => '5_marla_residential',
        ]);

        $this->post(route('admin.plan.cad-expert-label.store', ['id' => $submission->id]), [
            'plot_layer' => 'PLOT',
            'building_layer' => 'GF_OUTLINE',
            'dimension_layer' => null,
            'text_layer' => null,
            'plot_entity_handle' => null,
            'building_entity_handle' => null,
            'front_side' => 'north',
            'notes' => null,
        ]);

        $viewerMap = [
            'A-PLOT' => ['visible' => true, 'tag' => 'plot_boundary'],
            'A-GF' => ['visible' => true, 'tag' => 'ground_floor'],
        ];

        $response = $this->post(route('admin.plan.cad-layer-map.store', ['id' => $submission->id]), [
            'layer_map_json' => json_encode($viewerMap),
        ]);

        $response->assertRedirect(route('admin.plan.cad-layer-viewer', $submission->id));

        $training = CadTrainingLabel::where('cad_submission_id', $submission->id)->firstOrFail();

        $this->assertSame(['visible' => true, 'tag' => 'plot_boundary'], $training->layer_map['A-PLOT']);
        $this->assertSame(['visible' => true, 'tag' => 'ground_floor'], $training->layer_map['A-GF']);
        $this->assertSame('PLOT', $training->layer_map['plot']);
        $this->assertSame('GF_OUTLINE', $training->layer_map['building']);
    }

    public function test_layer_viewer_can_apply_a_map_without_reloading(): void
    {
        $submission = CadSubmission::create([
            'original_filename' => 'large-plan.dwg',
            'stored_dwg_path' => 'uploads/cad/large-plan.dwg',
            'ruleset_key' => '5_marla_residential',
        ]);

        $viewerMap = [
            'A-PLOT' => ['visible' => true, 'tag' => 'plot_boundary'],
            'A-WALL' => ['visible' => true, 'tag' => 'external_walls'],
        ];

        $response = $this->postJson(route('admin.plan.cad-layer-map.store', ['id' => $submission->id]), [
            'layer_map_json' => json_encode($viewerMap),
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Layer mapping applied successfully.',
                'layer_map' => $viewerMap,
            ]);

        $this->assertDatabaseHas('cad_expert_labels', [
            'cad_submission_id' => $submission->id,
            'layer_map_json' => json_encode($viewerMap),
        ]);
        $this->assertSame(
            $viewerMap,
            CadTrainingLabel::where('cad_submission_id', $submission->id)->firstOrFail()->layer_map
        );
    }

    public function test_officer_can_save_a_region_snapshot_as_structured_learning_data(): void
    {
        Storage::fake('public');
        $submission = CadSubmission::create([
            'original_filename' => 'stairs.dxf',
            'stored_dwg_path' => 'uploads/cad/stairs.dwg',
            'ruleset_key' => '5_marla_residential',
        ]);
        $png = 'data:image/png;base64,'.base64_encode(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );

        $response = $this->postJson(route('admin.plan.cad-expert-markings.store', $submission->id), [
            'label_key' => 'stairs',
            'label_name' => 'Stairs',
            'geometry_type' => 'rectangle',
            'points_json' => [['x' => 0, 'y' => 0], ['x' => 10, 'y' => 0], ['x' => 10, 'y' => 8], ['x' => 0, 'y' => 8]],
            'measurement_json' => ['area' => 80, 'perimeter' => 36, 'point_count' => 4],
            'status' => 'confirmed',
            'snapshot_data_url' => $png,
            'selected_handles_json' => ['A1', 'A2'],
            'facts_json' => [
                'observation_type' => 'stairs',
                'count' => 20,
                'unit' => 'count',
                'expected_value' => '20 stairs',
                'floor' => 'ground_floor',
                'ai_text_evidence' => [
                    'raw_text' => 'UP 20 RISERS',
                    'cad_layer' => 'STAIR-TEXT',
                    'cad_handle' => 'A1',
                    'x' => 10.5,
                    'y' => 8.25,
                    'parsed_value_ft' => 20,
                    'semantic_hints' => ['stairs'],
                    'officer_verified' => true,
                ],
            ],
            'rule_code' => 'STAIR_COUNT',
            'compliance_status' => 'compliant',
            'remarks' => 'Twenty stair risers meet the referenced requirement.',
        ]);

        $response->assertOk()->assertJsonPath('marking.facts_json.count', 20);
        $marking = \App\Models\CadExpertMarking::where('cad_submission_id', $submission->id)->firstOrFail();
        Storage::disk('public')->assertExists($marking->snapshot_path);
        $this->assertSame(['A1', 'A2'], $marking->selected_handles_json);
        $this->assertSame('STAIR_COUNT', $marking->rule_code);
        $this->assertSame('compliant', $marking->compliance_status);
        $training = \App\Models\DxfPatternTrainingExample::where('cad_submission_id', $submission->id)->firstOrFail();
        $example = $training->feature_snapshot_json['learning_examples'][0];
        $this->assertSame($marking->id, $example['marking_id']);
        $this->assertSame('UP 20 RISERS', $example['facts']['ai_text_evidence']['raw_text']);
        $this->assertTrue($example['facts']['ai_text_evidence']['officer_verified']);
    }
}
