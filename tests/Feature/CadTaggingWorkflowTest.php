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
