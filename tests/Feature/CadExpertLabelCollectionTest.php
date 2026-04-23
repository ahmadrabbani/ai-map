<?php

namespace Tests\Feature;

use App\Models\CadSubmission;
use App\Models\CadTrainingLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
