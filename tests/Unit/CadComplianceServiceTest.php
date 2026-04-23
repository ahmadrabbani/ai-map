<?php

namespace Tests\Unit;

use App\Models\CadSubmission;
use App\Models\CadTrainingLabel;
use App\Services\CadComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class CadComplianceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_label_args_prefers_expert_front_side(): void
    {
        $submission = CadSubmission::create([
            'original_filename' => 'sample.dwg',
            'stored_dwg_path' => 'uploads/cad/sample.dwg',
            'ruleset_key' => '5_marla_residential',
        ]);

        $submission->trainingLabel()->create([
            'front_side' => 'left',
        ]);

        $submission->expertLabel()->create([
            'front_side' => 'east',
            'layer_map_json' => json_encode(['A-GF' => ['visible' => true, 'tag' => 'ground_floor']]),
        ]);

        $args = $this->invokeBuildLabelArgs(new CadComplianceService(), $submission, ['use_labels' => true]);

        $this->assertContains('--front-side', $args);
        $this->assertSame('east', $args[array_search('--front-side', $args, true) + 1]);
    }

    public function test_build_label_args_falls_back_to_training_front_side(): void
    {
        $submission = CadSubmission::create([
            'original_filename' => 'sample.dwg',
            'stored_dwg_path' => 'uploads/cad/sample.dwg',
            'ruleset_key' => '5_marla_residential',
        ]);

        CadTrainingLabel::create([
            'cad_submission_id' => $submission->id,
            'front_side' => 'right',
            'layer_map' => ['plot' => 'PLOT'],
        ]);

        $args = $this->invokeBuildLabelArgs(new CadComplianceService(), $submission, ['use_labels' => true]);

        $this->assertContains('--front-side', $args);
        $this->assertSame('east', $args[array_search('--front-side', $args, true) + 1]);
    }

    private function invokeBuildLabelArgs(CadComplianceService $service, CadSubmission $submission, array $options): array
    {
        $method = new ReflectionMethod($service, 'buildLabelArgs');
        $method->setAccessible(true);

        return $method->invoke($service, $submission, $options);
    }
}
