<?php

namespace Database\Seeders;

use App\Models\BpAiReport;
use App\Models\BpApplication;
use App\Models\User;
use App\Services\BuildingPlanNumberService;
use App\Services\QrCodeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdEpermitDemoSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'ad.epermit@example.com'],
            [
                'name' => 'AD ePermit Officer',
                'password' => Hash::make('password'),
                'role' => 'ad_epermit',
                'is_ad_epermit' => true,
                'email_verified_at' => now(),
            ]
        );

        $numberService = app(BuildingPlanNumberService::class);
        $qr = app(QrCodeService::class);

        $applications = [
            [
                'status' => 'Submitted to AD ePermit',
                'applicant_name' => 'RANA TAHIR',
                'applicant_email' => 'rana.tahir@example.com',
                'applicant_phone' => '03000000000',
                'uploaded_file_name' => '5 Marla Plan demo.dwg',
                'recommendation' => 'Passed on Textual Data',
                'confidence' => 96,
            ],
            [
                'status' => 'Needs Expert Review',
                'applicant_name' => 'Demo Applicant',
                'applicant_email' => 'applicant@example.com',
                'applicant_phone' => '03111111111',
                'uploaded_file_name' => 'Residential Plan needs review.dwg',
                'recommendation' => 'Needs Expert Review',
                'confidence' => 72,
            ],
        ];

        foreach ($applications as $row) {
            $existing = BpApplication::query()
                ->where('uploaded_file_name', $row['uploaded_file_name'])
                ->first();

            $token = $existing?->qr_token ?: (string) Str::uuid();
            $verificationUrl = $qr->verificationUrl($token);

            $application = BpApplication::updateOrCreate(
                ['uploaded_file_name' => $row['uploaded_file_name']],
                [
                    'application_number' => $existing?->application_number ?: $numberService->generate(),
                    'status' => $row['status'],
                    'applicant_name' => $row['applicant_name'],
                    'applicant_email' => $row['applicant_email'],
                    'applicant_phone' => $row['applicant_phone'],
                    'uploaded_file_path' => 'demo/ad-epermit/' . Str::slug($row['uploaded_file_name']),
                    'uploaded_file_type' => 'dwg',
                    'uploaded_file_size' => 2048000,
                    'qr_token' => $token,
                    'verification_url' => $verificationUrl,
                    'qr_code_url' => $qr->qrImageUrl($verificationUrl),
                    'submitted_to_ad_at' => now(),
                    'layer_table_json' => [
                        'measurement_metrics' => [
                            'plot_area' => 1125,
                            'ground_floor_covered' => 841,
                            'first_floor_covered' => 659,
                            'second_floor_covered' => 364,
                            'total_floor_covered' => 1864,
                            'number_of_floors' => 3,
                            'provided_height_ft' => 37,
                            'front_setback_ft' => 5,
                            'rear_setback_ft' => 5.5,
                            'left_setback_ft' => 0,
                            'right_setback_ft' => 0,
                        ],
                    ],
                    'plot_data_json' => [
                        'plot_no' => '123',
                        'plot_size' => '5 Marla',
                        'scheme' => 'IEP TOWN',
                        'phase' => 'I',
                        'block' => 'A',
                        'sector' => '1',
                        'plot_category' => 'RESIDENTIAL',
                    ],
                    'applicant_data_json' => [
                        'name' => $row['applicant_name'],
                        'email' => $row['applicant_email'],
                        'phone' => $row['applicant_phone'],
                    ],
                ]
            );

            BpAiReport::updateOrCreate(
                ['bp_application_id' => $application->id],
                [
                    'analysis_status' => strtolower(str_replace(' ', '_', $row['status'])),
                    'ai_recommendation' => $row['recommendation'],
                    'ai_confidence_score' => $row['confidence'],
                    'analysis_json' => ['source' => 'ad_epermit_demo_seeder'],
                    'rule_results_json' => [
                        ['rule_code' => 'GROUND_COVERAGE', 'status' => 'pass', 'required' => '<= 75%', 'actual' => '74.76%'],
                        ['rule_code' => 'FAR_LIMIT', 'status' => 'pass', 'required' => '<= 2.3', 'actual' => '1.656'],
                        ['rule_code' => 'MAX_STOREYS', 'status' => 'pass', 'required' => '<= 3', 'actual' => '3'],
                    ],
                    'warnings_json' => $row['recommendation'] === 'Needs Expert Review' ? ['Expert review required before final decision.'] : [],
                    'expert_review_items_json' => $row['recommendation'] === 'Needs Expert Review' ? ['Confirm plot boundary and footprint in CAD viewer.'] : [],
                    'generated_at' => now(),
                ]
            );
        }
    }
}
