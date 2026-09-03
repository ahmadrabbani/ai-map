<?php

namespace Tests\Feature;

use App\Models\CadPrediction;
use App\Models\CadSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CadTaggingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_cad_text_predictions_import_idempotently_without_overwriting_officer_review(): void
    {
        $submission = CadSubmission::create([
            'original_filename' => 'native-room-text.dxf',
            'stored_dwg_path' => 'cad/native-room-text.dwg',
            'stored_dxf_path' => 'cad/native-room-text.dxf',
            'ruleset_key' => 'residential_building_approval',
        ]);
        $payload = [
            'predictions' => [[
                'label_key' => 'bedroom',
                'label_name' => 'G1_bed_1',
                'confidence' => .97,
                'geometry' => ['type' => 'point', 'points' => [[120.5, 80.25]]],
                'model_version' => 'native-cad-text-v1',
                'cad_handle' => 'TXT-10',
                'cad_layer' => 'GROUND TEXT',
                'floor' => 'ground_floor',
                'metadata' => [
                    'source' => 'native_cad_text',
                    'source_key' => 'native-cad-text:ground_floor:TXT-10:bedroom',
                    'instance_key' => 'G1_bed_1',
                    'plan_floor' => 'ground_floor',
                    'cad_text_evidence' => ['raw_text' => 'BED ROOM 14 X 11', 'x' => 120.5, 'y' => 80.25],
                ],
            ]],
        ];

        $this->postJson(route('api.cad.predictions.import', $submission->id), $payload)
            ->assertCreated()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 0);
        $this->postJson(route('api.cad.predictions.import', $submission->id), $payload)
            ->assertCreated()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('updated', 1);

        $prediction = CadPrediction::firstOrFail();
        $prediction->update(['status' => 'confirmed', 'review_action' => 'confirm']);
        $changed = $payload;
        $changed['predictions'][0]['label_key'] = 'rear_building_line';
        $changed['predictions'][0]['label_name'] = 'G1_rear_passage_1';
        $changed['predictions'][0]['metadata']['source_key'] = 'native-cad-text:ground_floor:TXT-10:rear_building_line';
        $changed['predictions'][0]['metadata']['instance_key'] = 'G1_rear_passage_1';
        $this->postJson(route('api.cad.predictions.import', $submission->id), $changed)
            ->assertCreated()
            ->assertJsonPath('preserved', 1);

        $this->assertSame(1, CadPrediction::count());
        $this->assertDatabaseHas('cad_predictions', [
            'id' => $prediction->id,
            'label_key' => 'bedroom',
            'label_name' => 'G1_bed_1',
            'status' => 'confirmed',
        ]);

        $this->postJson(route('api.cad.predictions.review', [$submission->id, $prediction->id]), [
            'action' => 'correct',
            'label_key' => 'rear_building_line',
            'label_name' => 'G1_rear_passage_1',
            'floor' => 'ground_floor',
        ])->assertOk()->assertJsonPath('tag.label_key', 'rear_building_line');
        $this->assertDatabaseHas('cad_predictions', [
            'id' => $prediction->id,
            'final_label_key' => 'rear_building_line',
            'label_name' => 'G1_rear_passage_1',
            'floor' => 'ground_floor',
            'status' => 'corrected',
        ]);

        $secondRoom = $payload;
        $secondRoom['predictions'][0]['label_name'] = 'G1_bed_2';
        $secondRoom['predictions'][0]['cad_handle'] = 'TXT-11';
        $secondRoom['predictions'][0]['metadata']['source_key'] = 'native-cad-text:ground_floor:TXT-11:bedroom';
        $secondRoom['predictions'][0]['metadata']['instance_key'] = 'G1_bed_2';
        $secondRoom['predictions'][0]['metadata']['cad_text_evidence']['cad_handle'] = 'TXT-11';
        $this->postJson(route('api.cad.predictions.import', $submission->id), $secondRoom)
            ->assertCreated()
            ->assertJsonPath('created', 1);
        $this->assertSame(2, CadPrediction::count());
    }

    public function test_prediction_correction_becomes_audited_tag_and_can_be_expert_verified(): void
    {
        $user = User::factory()->create();
        $submission = CadSubmission::create([
            'original_filename' => 'rooms.dxf',
            'stored_dwg_path' => 'cad/rooms.dwg',
            'ruleset_key' => 'residential_building_approval',
        ]);
        $prediction = CadPrediction::create([
            'cad_submission_id' => $submission->id,
            'label_key' => 'room',
            'geometry_type' => 'polygon',
            'geometry_json' => ['type' => 'polygon', 'points' => [[0, 0], [12, 0], [12, 10], [0, 10]]],
            'confidence' => .82,
            'model_version' => 'model-1',
            'status' => 'ai_suggested',
        ]);

        $this->actingAs($user)->postJson(route('api.cad.predictions.review', [$submission->id, $prediction->id]), [
            'action' => 'correct',
            'label_key' => 'bedroom',
            'unit' => 'FT',
            'scale' => 1,
            'unit_confirmed' => true,
        ])->assertOk()->assertJsonPath('tag.label_key', 'bedroom');

        $this->assertDatabaseHas('cad_tags', [
            'cad_submission_id' => $submission->id,
            'cad_prediction_id' => $prediction->id,
            'label_key' => 'bedroom',
            'status' => 'corrected',
            'area_sq_ft' => 120,
        ]);
        $this->assertDatabaseHas('cad_tag_audits', ['cad_prediction_id' => $prediction->id, 'action' => 'correct']);

        $this->actingAs($user)->postJson(route('api.cad.tags.submit-verified', $submission->id), [])
            ->assertOk()->assertJsonPath('verified', 1);
        $this->assertDatabaseHas('cad_tags', ['cad_prediction_id' => $prediction->id, 'verification_level' => 'expert_verified']);
    }

    public function test_officer_can_edit_stair_count_and_area_as_learnable_measurements(): void
    {
        $user = User::factory()->create();
        $submission = CadSubmission::create([
            'original_filename' => 'stair-pattern.dxf',
            'stored_dwg_path' => 'cad/stair-pattern.dwg',
            'ruleset_key' => 'residential_building_approval',
        ]);
        $prediction = CadPrediction::create([
            'cad_submission_id' => $submission->id,
            'label_key' => 'staircase',
            'label_name' => 'G1_stair_1',
            'geometry_type' => 'polygon',
            'geometry_json' => ['type' => 'polygon', 'points' => [[0, 0], [8, 0], [8, 12], [0, 12]]],
            'confidence' => .94,
            'model_version' => 'native-cad-text-v1',
            'status' => 'ai_suggested',
            'metadata' => [
                'source' => 'native_cad_text',
                'measurement_suggestion' => [
                    'method' => 'repeated_parallel_lines',
                    'observed_count' => 12,
                    'area_sq_ft' => 96,
                    'source_handles' => ['L1', 'L2', 'L3', 'L4'],
                ],
            ],
        ]);

        $this->actingAs($user)->postJson(route('api.cad.predictions.review', [$submission->id, $prediction->id]), [
            'action' => 'confirm',
            'observed_count' => 14,
            'area_sq_ft' => 98.5,
            'measurement_method' => 'repeated_parallel_lines',
            'unit' => 'FT',
            'scale' => 1,
            'unit_confirmed' => true,
        ])->assertOk()
            ->assertJsonPath('prediction.metadata.reviewed_measurements.observed_count', 14)
            ->assertJsonPath('prediction.metadata.reviewed_measurements.area_sq_ft', 98.5)
            ->assertJsonPath('tag.attributes.observed_count', 14)
            ->assertJsonPath('tag.attributes.officer_edited', true)
            ->assertJsonPath('tag.area_sq_ft', 98.5);

        $this->assertSame(14, $prediction->fresh()->metadata['reviewed_measurements']['observed_count']);
        $this->assertDatabaseHas('cad_tag_audits', [
            'cad_prediction_id' => $prediction->id,
            'action' => 'confirm',
        ]);
    }

    public function test_evaluation_reports_per_label_and_overall_metrics(): void
    {
        $user = User::factory()->create();
        $submission = CadSubmission::create([
            'original_filename' => 'plot.dxf',
            'stored_dwg_path' => 'cad/plot.dwg',
            'ruleset_key' => 'residential_building_approval',
        ]);
        CadPrediction::create([
            'cad_submission_id' => $submission->id,
            'label_key' => 'plot_boundary',
            'geometry_type' => 'polygon',
            'geometry_json' => ['type' => 'polygon', 'points' => [[0, 0], [10, 0], [10, 10], [0, 10]]],
            'confidence' => .95,
            'status' => 'ai_suggested',
        ]);
        $tag = $submission->tags()->create([
            'label_key' => 'plot_boundary', 'geometry_type' => 'polygon',
            'geometry_json' => ['type' => 'polygon', 'points' => [[0, 0], [10, 0], [10, 10], [0, 10]]],
            'verification_level' => 'expert_verified', 'status' => 'verified',
            'area_sq_ft' => 100, 'unit' => 'FT', 'scale' => 1, 'unit_confirmed' => true,
        ]);

        $this->actingAs($user)->postJson(route('api.cad.evaluate', $submission->id), ['iou_threshold' => .75])
            ->assertOk()
            ->assertJsonPath('run.summary.micro_f1', 1)
            ->assertJsonPath('run.metrics.0.metrics.tp', 1);
    }
}
