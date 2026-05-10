<?php

namespace App\Services;

use App\Models\CadApprovalApplication;

class ApplicationWizardService
{
    public function buildVerifiedSnapshot(CadApprovalApplication $application): array
    {
        return [
            'applicant' => [
                'name' => $application->applicant_name,
                'cnic' => $application->identification_number,
                'mobile' => $application->mobile_number ?: $application->contact_number,
                'email' => $application->email,
            ],
            'plot' => [
                'plot_number' => $application->plot_number,
                'block' => $application->block,
                'scheme' => $application->scheme,
                'phase' => $application->phase,
                'plot_size_category' => $application->plot_size_category,
                'plot_area_sqft' => $application->plot_area_sqft,
            ],
            'submission' => [
                'application_type' => $application->application_type,
                'property_type' => $application->property_type ?: $application->building_type,
                'floors' => $application->submitted_floors ?: [],
                'has_basement' => (bool) $application->has_basement,
                'remarks' => $application->remarks,
            ],
        ];
    }

    public function verificationQuestions(CadApprovalApplication $application): array
    {
        return [
            ['key' => 'plot_number_correct', 'question' => 'Is the plot number correct?', 'critical' => true],
            ['key' => 'scheme_block_correct', 'question' => 'Is the scheme and block information correct?', 'critical' => true],
            ['key' => 'plot_size_correct', 'question' => 'Is the plot size correct?', 'critical' => true],
            ['key' => 'floors_correct', 'question' => 'Are the selected floors correct?', 'critical' => true],
            ['key' => 'basement_included_correctly', 'question' => 'Is basement included in this application?', 'critical' => false],
            ['key' => 'latest_plan_submitted', 'question' => 'Are you submitting the latest approved or revised plan?', 'critical' => true],
            ['key' => 'layer_guidelines_confirmed', 'question' => 'Do you confirm that uploaded drawings follow the required layer naming structure?', 'critical' => true],
        ];
    }

    public function saveVerificationAnswers(CadApprovalApplication $application, array $answers): array
    {
        $snapshot = $this->buildVerifiedSnapshot($application);
        $hasCriticalNo = collect($this->verificationQuestions($application))
            ->where('critical', true)
            ->contains(function (array $question) use ($answers) {
                return ($answers[$question['key']]['answer'] ?? null) === 'no';
            });

        $application->verified_data_json = $snapshot;
        $application->verification_answers_json = $answers;
        $application->current_step = $hasCriticalNo ? 'details' : 'upload_plans';
        $application->status = $hasCriticalNo ? 'needs_correction' : 'Data Verified';
        $application->save();

        return [
            'has_critical_no' => $hasCriticalNo,
            'snapshot' => $snapshot,
            'answers' => $answers,
        ];
    }

    public function allowedStatuses(): array
    {
        return [
            'draft',
            'Basic Info Completed',
            'Data Verified',
            'Plan Uploaded',
            'Layer Validation Completed',
            'Needs Expert Review',
            'Expert Reviewed',
            'Report Generated',
            'Submitted',
        ];
    }
}
