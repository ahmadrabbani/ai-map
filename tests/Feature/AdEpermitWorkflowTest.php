<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApplicationDocument;
use App\Models\PublicBuildingPlanApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdEpermitWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function adUser(): User
    {
        return User::factory()->create([
            'role' => 'ad_epermit',
            'is_ad_epermit' => true,
        ]);
    }

    private function seededApplication(): PublicBuildingPlanApplication
    {
        Storage::fake('local');

        $applicant = Applicant::create([
            'name' => 'Applicant A',
            'cnic' => '12345-1234567-1',
            'mobile' => '03001234567',
            'email' => 'applicant@example.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
        ]);

        $application = PublicBuildingPlanApplication::create([
            'application_no' => 'BP-20260517-00001',
            'user_id' => $applicant->id,
            'applicant_name' => 'Applicant A',
            'applicant_cnic' => '12345-1234567-1',
            'applicant_email' => 'applicant@example.com',
            'applicant_phone' => '03001234567',
            'scheme' => 'Scheme X',
            'scheme_name' => 'Scheme X',
            'block' => 'A',
            'block_name' => 'A',
            'plot_ref' => '12',
            'plot_no' => '12',
            'plot_area' => 1125,
            'selected_address' => 'Test Address',
            'plot_address' => 'Test Address',
            'status' => 'submitted_to_ad_epermit',
            'current_status' => 'submitted_to_ad_epermit',
            'ai_status' => 'AI Scrutiny Completed',
            'plan_file_path' => 'uploads/test/plan.dwg',
            'cad_file_path' => 'uploads/test/plan.dwg',
            'ai_report_path' => 'uploads/test/report.json',
            'submitted_at' => now(),
        ]);

        Storage::disk('local')->put('uploads/test/plan.dwg', 'cad-content');
        Storage::disk('local')->put('uploads/test/report.json', json_encode(['ok' => true]));
        Storage::disk('local')->put('uploads/test/cnic.pdf', 'cnic');

        ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type' => 'cnic_front',
            'attachment_type' => 'cnic_front',
            'original_name' => 'cnic.pdf',
            'file_path' => 'uploads/test/cnic.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4,
            'validation_status' => 'valid',
        ]);

        $application->statusLogs()->create([
            'action_by_user_id' => null,
            'action_by_role' => 'system',
            'old_status' => 'draft',
            'new_status' => 'submitted_to_ad_epermit',
            'remarks' => 'Submitted',
            'payload_json' => ['seed' => true],
        ]);

        return $application;
    }

    public function test_ad_dashboard_shows_application_and_detail_page(): void
    {
        $user = $this->adUser();
        $application = $this->seededApplication();

        $this->actingAs($user)
            ->get(route('admin.plan.bp.ad.index'))
            ->assertOk()
            ->assertSee($application->application_no);

        $this->actingAs($user)
            ->get(route('admin.plan.bp.ad.show', $application))
            ->assertOk()
            ->assertSee('Decision Panel')
            ->assertSee('View Satellite Site')
            ->assertSee('CAD File Viewer');
    }

    public function test_ad_dashboard_shows_system_submitted_cases_to_every_ad_officer(): void
    {
        $firstOfficer = $this->adUser();
        $secondOfficer = $this->adUser();
        $application = $this->seededApplication();

        $this->actingAs($firstOfficer)
            ->get(route('admin.plan.bp.ad.show', $application))
            ->assertOk();

        $this->actingAs($secondOfficer)
            ->get(route('admin.plan.bp.ad.index'))
            ->assertOk()
            ->assertSee($application->application_no)
            ->assertSee('Total Assigned Cases')
            ->assertSee('1');
    }

    public function test_ad_dashboard_pagination_uses_bootstrap_and_preserves_status_filter(): void
    {
        $user = $this->adUser();
        $application = $this->seededApplication();

        foreach (range(2, 21) as $number) {
            $copy = $application->replicate();
            $copy->application_no = sprintf('BP-20260517-%05d', $number);
            $copy->save();
        }

        $firstPage = $this->actingAs($user)
            ->get(route('admin.plan.bp.ad.index', ['status' => 'assigned']));

        $firstPage
            ->assertOk()
            ->assertSee('pagination')
            ->assertSee(route('admin.plan.bp.ad.index', [
                'status' => 'assigned',
                'page' => 2,
            ]));

        $this->actingAs($user)
            ->get(route('admin.plan.bp.ad.index', [
                'status' => 'assigned',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertSee('BP-20260517-00001')
            ->assertDontSee('BP-20260517-00021');
    }

    public function test_observation_and_rejection_requirements_and_status_logs(): void
    {
        $user = $this->adUser();
        $application = $this->seededApplication();

        $this->actingAs($user)
            ->post(route('admin.plan.bp.ad.update', $application), [
                'action' => 'observation',
                'remarks' => 'Need updated rear boundary docs',
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame('observation_marked', $application->current_status);
        $this->assertDatabaseHas('application_status_logs', [
            'application_id' => $application->id,
            'new_status' => 'observation_marked',
        ]);

        $this->actingAs($user)
            ->post(route('admin.plan.bp.ad.update', $application), [
                'action' => 'reject',
                'remarks' => '',
            ])
            ->assertSessionHasErrors(['remarks']);

        $this->actingAs($user)
            ->post(route('admin.plan.bp.ad.update', $application), [
                'action' => 'reject',
                'remarks' => 'Severe mismatch in ownership docs',
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame('rejected_by_ad_epermit', $application->current_status);
    }

    public function test_site_review_and_dfps_push_flow(): void
    {
        $user = $this->adUser();
        $application = $this->seededApplication();

        $this->actingAs($user)
            ->post(route('admin.plan.bp.ad.site-review', $application), [
                'latitude' => 31.5204,
                'longitude' => 74.3587,
                'site_condition' => 'vacant',
                'front_road_detected' => true,
                'side_road_detected' => false,
                'corner_plot' => false,
                'remarks' => 'Vacant and fenced',
                'site_review_json' => [
                    'map_provider' => 'google_maps',
                    'view_type' => 'satellite',
                    'latitude' => 31.5204,
                    'longitude' => 74.3587,
                    'plot_polygon' => [],
                    'road_sides' => [],
                    'site_condition' => 'vacant',
                    'front_road_detected' => true,
                    'side_road_detected' => false,
                    'corner_plot' => false,
                    'remarks' => 'Vacant and fenced',
                    'marked_by' => '',
                    'marked_at' => '',
                ],
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertNotNull($application->siteReview);

        $this->actingAs($user)
            ->post(route('admin.plan.bp.ad.update', $application), [
                'action' => 'approve',
                'remarks' => 'Approved after review',
            ])
            ->assertSessionHasNoErrors();

        Http::fake([
            '*' => Http::response(['ok' => true], 200),
        ]);

        config()->set('services.dfps.endpoint', 'https://dfps.test/push');
        config()->set('services.dfps.timeout', 30);

        $this->actingAs($user)
            ->post(route('admin.plan.bp.ad.push-dfps', $application), [
                'remarks' => 'Approved application forwarded to DFPS.',
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame('pushed_to_dfps', $application->current_status);
        $this->assertDatabaseHas('dfps_push_logs', [
            'application_id' => $application->id,
            'success' => 1,
        ]);

        $log = $application->dfpsPushLogs()->latest('id')->first();
        $this->assertNotNull($log);
        Storage::disk('local')->assertExists($log->zip_file_path);
    }
}
