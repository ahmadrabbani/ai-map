<?php

namespace App\Http\Controllers;

use App\Models\CadApprovalApplication;
use App\Models\CadApprovalEvent;
use App\Models\CadExpertMarking;
use App\Models\CadApprovalPlan;
use App\Models\CadEntityFeature;
use App\Models\CadRuleResult;
use App\Models\CadSubmission;
use App\Services\ApplicationWizardService;
use App\Services\CadApprovalReportService;
use App\Services\CadApprovalRuleService;
use App\Services\CadComplianceService;
use App\Services\DrawingLayerDetectionService;
use App\Services\ExpertMarkingService;
use App\Services\GeometryMeasurementService;
use App\Services\LayerGuidelineService;
use App\Services\ReportGenerationService;
use App\Services\RuleValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CadApprovalWizardController extends Controller
{
    public function __construct(
        private readonly ApplicationWizardService $wizardService,
        private readonly CadApprovalRuleService $ruleService,
        private readonly CadApprovalReportService $reportService,
        private readonly CadComplianceService $cadComplianceService,
        private readonly LayerGuidelineService $layerGuidelineService,
        private readonly DrawingLayerDetectionService $layerDetectionService,
        private readonly GeometryMeasurementService $measurementService,
        private readonly ExpertMarkingService $expertMarkingService,
        private readonly RuleValidationService $ruleValidationService,
        private readonly ReportGenerationService $structuredReportService,
    ) {
    }

    public function index()
    {
        return view('admin.cad-approval.index', [
            'applications' => CadApprovalApplication::latest()->paginate(15),
        ]);
    }

    public function create()
    {
        return view('admin.cad-approval.create', [
            'plotSizeOptions' => $this->plotSizeOptions(),
            'floorOptions' => $this->floorOptions(),
        ]);
    }

    public function storeDetails(Request $request)
    {
        $data = $this->validateDetails($request);

        $application = CadApprovalApplication::create(array_merge($data, [
            'verified_data_json' => $this->wizardService->buildVerifiedSnapshot(new CadApprovalApplication($data)),
            'current_step' => 'verification',
            'status' => 'draft',
        ]));

        $this->ruleService->syncRequiredPlans($application);
        $application->refresh()->load('plans');

        $this->recordEvent($application, 'application_created', 'CAD approval application created.', null, [
            'plot_size_category' => $application->plot_size_category,
        ]);

        return redirect()
            ->route('admin.plan.approval-wizard.verification', $application)
            ->with('status', 'Application created. Confirm the verified details before uploading plans.');
    }

    public function show(CadApprovalApplication $application)
    {
        $this->ruleService->syncRequiredPlans($application);
        $application->load(['plans.submission', 'events']);

        return view('admin.cad-approval.show', [
            'application' => $application,
            'summary' => $this->ruleService->summarizeApplication($application),
            'plotSizeOptions' => $this->plotSizeOptions(),
            'floorOptions' => $this->floorOptions(),
            'guidelineSummary' => $this->layerGuidelineService->summaryTable(),
            'verificationQuestions' => $this->wizardService->verificationQuestions($application),
            'ruleValidation' => $this->ruleValidationService->validateApplication($application),
        ]);
    }

    public function updateDetails(Request $request, CadApprovalApplication $application)
    {
        $data = $this->validateDetails($request);
        $application->fill($data);
        $application->verified_data_json = $this->wizardService->buildVerifiedSnapshot($application);
        $application->current_step = 'verification';
        $application->draft_saved_at = now();
        $application->save();

        $this->ruleService->syncRequiredPlans($application);
        $this->recordEvent($application, 'details_updated', 'Application details updated.');

        return redirect()
            ->route('admin.plan.approval-wizard.show', $application)
            ->with('status', 'Application details updated.');
    }

    public function verification(CadApprovalApplication $application)
    {
        return view('admin.cad-approval.verification', [
            'application' => $application->load('plans'),
            'snapshot' => $application->verified_data_json ?: $this->wizardService->buildVerifiedSnapshot($application),
            'questions' => $this->wizardService->verificationQuestions($application),
            'answers' => $application->verification_answers_json ?? [],
        ]);
    }

    public function saveVerification(Request $request, CadApprovalApplication $application)
    {
        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*.answer' => ['required', Rule::in(['yes', 'no'])],
            'answers.*.remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->wizardService->saveVerificationAnswers($application, $validated['answers']);

        $this->recordEvent($application, 'verification_saved', 'Verified data confirmation saved.', null, [
            'has_critical_no' => $result['has_critical_no'],
        ]);

        return redirect()
            ->route('admin.plan.approval-wizard.show', $application)
            ->with(
                $result['has_critical_no'] ? 'warning' : 'status',
                $result['has_critical_no']
                    ? 'Some critical answers were marked No. Please correct the details before continuing.'
                    : 'Verified data saved. You can continue to plan upload and layer validation.'
            );
    }

    public function uploadPlans(Request $request, CadApprovalApplication $application)
    {
        $this->ruleService->syncRequiredPlans($application);

        $request->validate([
            'plans' => ['required', 'array'],
            'plans.*' => [
                'nullable',
                'file',
                'mimes:dwg,dxf,pdf',
                'max:' . config('cad_approval.max_upload_kb', 51200),
            ],
        ]);

        $files = array_filter($request->file('plans', []));

        if (empty($files)) {
            return back()->withErrors([
                'plans' => 'Select at least one DWG or DXF file to upload.',
            ]);
        }

        foreach ($files as $floorType => $file) {
            if (! in_array($floorType, CadApprovalPlan::FLOOR_TYPES, true)) {
                continue;
            }

            $plan = $application->plans()->firstOrCreate(
                ['floor_type' => $floorType],
                ['label' => $this->ruleService->planLabel($floorType)]
            );

            $storedPath = $file->storeAs(
                'uploads/cad-approval/' . $application->id . '/' . $floorType,
                Str::uuid() . '.' . strtolower($file->getClientOriginalExtension()),
                'local'
            );

            $submission = $plan->submission ?: new CadSubmission();
            $submission->original_filename = $file->getClientOriginalName();
            $submission->stored_dwg_path = $storedPath;
            $submission->ruleset_key = $application->ruleset;

            if (strtolower($file->getClientOriginalExtension()) === 'dxf') {
                $submission->stored_dxf_path = $storedPath;
            }

            $submission->save();

            $plan->fill([
                'cad_submission_id' => $submission->id,
                'label' => $plan->label ?: $this->ruleService->planLabel($floorType),
                'is_required' => in_array($floorType, $this->ruleService->getRequiredPlanTypes($application), true),
                'is_uploaded' => true,
                'status' => 'uploaded',
                'original_file_path' => $storedPath,
                'uploaded_extension' => strtolower($file->getClientOriginalExtension()),
            ]);
            $plan->save();

            $this->recordEvent($application, 'plan_uploaded', $plan->label . ' uploaded.', $plan, [
                'stored_path' => $storedPath,
                'extension' => strtolower($file->getClientOriginalExtension()),
            ]);
        }

        $application->status = 'Plan Uploaded';
        $application->current_step = 'upload_plans';
        $application->save();

        return redirect()
            ->route('admin.plan.approval-wizard.show', $application)
            ->with('status', 'Selected plan files uploaded. Run CAD processing on each uploaded plan.');
    }

    public function processPlan(CadApprovalApplication $application, CadApprovalPlan $plan)
    {
        $this->ensurePlanBelongsToApplication($application, $plan);

        return $this->runPlanProcessing($application, $plan, false);
    }

    public function rerunPlan(CadApprovalApplication $application, CadApprovalPlan $plan)
    {
        $this->ensurePlanBelongsToApplication($application, $plan);

        return $this->runPlanProcessing($application, $plan, true);
    }

    public function summary(CadApprovalApplication $application)
    {
        $application->load(['plans.submission', 'events', 'expertMarkings']);

        return view('admin.cad-approval.summary', [
            'application' => $application,
            'summary' => $this->ruleService->summarizeApplication($application),
            'guidelineSummary' => $this->layerGuidelineService->summaryTable(),
            'ruleValidation' => $this->ruleValidationService->validateApplication($application),
        ]);
    }

    public function expertReview(CadApprovalApplication $application)
    {
        $application->load(['plans.submission', 'expertMarkings']);

        return view('admin.cad-approval.expert-review', [
            'application' => $application,
            'guidelineSummary' => $this->layerGuidelineService->summaryTable(),
            'ruleValidation' => $this->ruleValidationService->validateApplication($application),
        ]);
    }

    public function saveDraft(CadApprovalApplication $application)
    {
        $application->draft_saved_at = now();
        $application->status = $application->status ?: 'draft';
        $application->save();

        $this->recordEvent($application, 'draft_saved', 'Application draft saved.');

        return back()->with('status', 'Draft saved.');
    }

    public function saveExpertMarking(Request $request, CadApprovalApplication $application)
    {
        $data = $request->validate([
            'cad_approval_plan_id' => ['nullable', 'integer'],
            'floor_type' => ['nullable', 'string', 'max:255'],
            'marking_type' => ['required', 'string', 'max:255'],
            'geometry_json' => ['nullable', 'string'],
            'measured_area' => ['nullable', 'numeric'],
            'measured_length' => ['nullable', 'numeric'],
            'remarks' => ['nullable', 'string'],
        ]);

        $plan = null;
        if (! empty($data['cad_approval_plan_id'])) {
            $plan = $application->plans()->findOrFail($data['cad_approval_plan_id']);
        }

        $marking = $this->expertMarkingService->saveMarking($application, [
            'floor_type' => $data['floor_type'] ?? $plan?->floor_type,
            'marking_type' => $data['marking_type'],
            'geometry_json' => ! empty($data['geometry_json']) ? json_decode($data['geometry_json'], true) : null,
            'measured_area' => $data['measured_area'] ?? null,
            'measured_length' => $data['measured_length'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'created_by' => optional($request->user())->email ?? optional($request->user())->name,
        ], $plan);

        $application->status = 'Expert Reviewed';
        $application->current_step = 'expert_review';
        $application->save();

        $this->recordEvent($application, 'expert_marking_saved', 'Expert marking saved: ' . $marking->marking_type, $plan, [
            'marking_id' => $marking->id,
        ]);

        return back()->with('status', 'Expert marking saved.');
    }

    public function faq(Request $request)
    {
        $question = strtolower(trim((string) $request->input('question', '')));
        $faqPath = storage_path('app/rules/faq.json');
        $faq = is_file($faqPath) ? json_decode(file_get_contents($faqPath), true) : [];
        $best = null;
        $score = 0;

        foreach ($faq as $item) {
            $candidate = strtolower((string) ($item['question'] ?? ''));
            $candidateScore = 0;

            foreach (preg_split('/\s+/', $question) as $word) {
                if ($word !== '' && str_contains($candidate, $word)) {
                    $candidateScore++;
                }
            }

            if ($candidateScore > $score) {
                $score = $candidateScore;
                $best = $item;
            }
        }

        return response()->json([
            'status' => 'ok',
            'question' => $request->input('question'),
            'answer' => $best['answer'] ?? 'No FAQ match was found. Please ask the reviewing expert for clarification.',
            'matched_question' => $best['question'] ?? null,
        ]);
    }

    public function generateReport(CadApprovalApplication $application)
    {
        $this->ruleService->syncRequiredPlans($application);
        $application->load('plans');

        if (! $this->ruleService->canGenerateFinalReport($application)) {
            return back()->withErrors([
                'report' => 'Upload all mandatory plans before generating the final report.',
            ]);
        }

        $report = $this->structuredReportService->generate($application);
        $application->final_report_json = $report;
        $application->status = 'Report Generated';
        $application->current_step = 'final_report';
        $application->save();

        $pdfPath = $this->reportService->generatePdf($application);
        if ($pdfPath !== null) {
            $application->final_report_pdf_path = $pdfPath;
            $application->save();
        }

        $application->refresh();

        $this->recordEvent($application, 'report_generated', 'Final report generated.', null, [
            'final_status' => $report['final_status'] ?? null,
            'pdf_generated' => $application->final_report_pdf_path !== null,
        ]);

        return redirect()
            ->route('admin.plan.approval-wizard.report', $application)
            ->with('status', $application->final_report_pdf_path
                ? 'Final report generated.'
                : 'Final report JSON saved. TODO: configure a PDF library to enable PDF output.');
    }

    public function submitForProcessing(CadApprovalApplication $application)
    {
        $allowed = $this->ruleService->loadRulesMeta()['final_report']['allow_submit_when_status'] ?? ['ready_for_submission'];
        $finalStatus = $this->ruleService->determineFinalStatus($application->load('plans'));

        if (! in_array($finalStatus, $allowed, true)) {
            return back()->withErrors([
                'submit' => 'This application cannot be submitted until it reaches ready_for_submission.',
            ]);
        }

        if (empty($application->final_report_json)) {
            return back()->withErrors([
                'submit' => 'Generate the final report before submitting the application.',
            ]);
        }

        $application->status = 'Submitted';
        $application->current_step = 'submitted';
        $application->submitted_at = now();
        $application->save();

        $this->recordEvent($application, 'submitted', 'Application submitted for internal processing.');

        return redirect()
            ->route('admin.plan.approval-wizard.show', $application)
            ->with('status', 'Application submitted for internal processing.');
    }

    public function report(CadApprovalApplication $application)
    {
        $application->load('plans');

        return view('admin.cad-approval.report', [
            'application' => $application,
            'report' => $application->final_report_json ?? [],
        ]);
    }

    private function runPlanProcessing(CadApprovalApplication $application, CadApprovalPlan $plan, bool $useLabels)
    {
        if (! $plan->is_uploaded || ! $plan->original_file_path || ! Storage::disk('local')->exists($plan->original_file_path)) {
            return back()->withErrors([
                'process' => 'Upload the plan file before running CAD processing.',
            ]);
        }

        $submission = $plan->submission;

        if (! $submission) {
            return back()->withErrors([
                'process' => 'CAD submission record is missing for this plan.',
            ]);
        }

        $plan->status = 'processing';
        $plan->save();

        $application->status = 'processing';
        $application->current_step = 'cad_processing';
        $application->save();

        $options = [];
        if ($useLabels) {
            $options['use_labels'] = true;
            $options['use_stored_dxf'] = ! empty($submission->stored_dxf_path);
        }
        if (! empty($submission->stored_dxf_path) && str_ends_with(strtolower($plan->original_file_path), '.dxf')) {
            $options['use_stored_dxf'] = true;
        }

        $dwgAbsPath = Storage::disk('local')->path($plan->original_file_path);

        try {
            $run = $this->cadComplianceService->processSubmission($submission, $dwgAbsPath, $options);
        } catch (\Throwable $e) {
            Log::error('CAD approval wizard processing failed', [
                'application_id' => $application->id,
                'plan_id' => $plan->id,
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);

            $run = [
                'status' => 'needs_expert_review',
                'error_code' => 'wizard_processing_exception',
                'message' => $e->getMessage(),
                'recommended_next_step' => [
                    'action' => 'open_expert_marking',
                    'instructions' => 'Review the uploaded drawing and rerun with expert labels.',
                ],
            ];

            $submission->analysis_result = $run;
            $submission->save();
        }

        $this->persistRuleResults($submission, $run, $useLabels ? 'expert' : 'system');
        $this->persistEntityFeatures($submission, $run);

        $submission->refresh();
        $status = $run['status'] ?? 'error';

        $plan->analysis_result = $submission->analysis_result ?? $run;
        $plan->overlay_pdf_path = $submission->overlay_pdf_path;
        $plan->drawing_pdf_path = $submission->drawing_pdf_path;

        if ($status === 'ok') {
            $plan->status = $this->hasFailedRules($run) ? 'failed' : 'passed';
        } else {
            $plan->status = 'needs_expert_review';
        }

        $layerValidation = $this->layerDetectionService->validatePlanLayers($plan);
        $plan->layer_validation_json = $layerValidation;
        $plan->detected_layers_json = $layerValidation['found_layers'] ?? [];
        $plan->confidence_score = $layerValidation['confidence_score'] ?? null;

        $plan->save();

        $application->status = $this->mapApplicationStatus($application);
        $application->current_step = $plan->status === 'needs_expert_review' ? 'expert_review' : 'layer_validation';
        $application->save();

        $this->recordEvent(
            $application,
            $useLabels ? 'plan_rerun' : 'plan_processed',
            ($useLabels ? 'Plan rerun with labels: ' : 'Plan processed: ') . $plan->label,
            $plan,
            [
                'status' => $plan->status,
                'cad_status' => $status,
                'error_code' => $run['error_code'] ?? null,
                'layer_validation_status' => $layerValidation['status'] ?? null,
            ]
        );

        return redirect()
            ->route('admin.plan.approval-wizard.show', $application)
            ->with('status', $plan->label . ' processing completed with status: ' . str_replace('_', ' ', $plan->status) . '.');
    }

    private function persistRuleResults(CadSubmission $submission, array $run, string $source): void
    {
        $query = CadRuleResult::where('cad_submission_id', $submission->id);

        if ($source === 'system') {
            $query->where(function ($builder) {
                $builder->whereNull('source')->orWhere('source', 'system');
            });
        } else {
            $query->where('source', $source);
        }

        $query->delete();

        foreach (($run['rules'] ?? []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            CadRuleResult::create([
                'cad_submission_id' => $submission->id,
                'source' => $source,
                'rule_id' => (string) ($rule['id'] ?? ''),
                'rule_type' => $rule['type'] ?? null,
                'title' => $rule['title'] ?? null,
                'required_value' => $this->normalizeRuleValue($rule['required'] ?? null),
                'measured_value' => $this->normalizeRuleValue($rule['measured'] ?? null),
                'unit' => $rule['unit'] ?? null,
                'operator' => $rule['operator'] ?? null,
                'is_compliant' => array_key_exists('pass', $rule) ? ($rule['pass'] === null ? null : (bool) $rule['pass']) : null,
                'details' => $rule['details'] ?? null,
            ]);
        }
    }

    private function persistEntityFeatures(CadSubmission $submission, array $run): void
    {
        if (! array_key_exists('entity_features', $run)) {
            return;
        }

        CadEntityFeature::where('cad_submission_id', $submission->id)->delete();

        foreach (($run['entity_features'] ?? []) as $feature) {
            if (! is_array($feature)) {
                continue;
            }

            CadEntityFeature::create([
                'cad_submission_id' => $submission->id,
                'entity_handle' => (string) ($feature['handle'] ?? ''),
                'entity_type' => (string) ($feature['type'] ?? ''),
                'layer' => $feature['layer'] ?? $feature['raw_layer'] ?? $feature['original_layer_name'] ?? $feature['standard_layer'] ?? null,
                'is_closed' => (bool) ($feature['is_closed'] ?? false),
                'num_vertices' => (int) ($feature['num_vertices'] ?? 0),
                'area' => $feature['area'] ?? null,
                'bbox_x0' => $feature['bbox']['x0'] ?? null,
                'bbox_y0' => $feature['bbox']['y0'] ?? null,
                'bbox_x1' => $feature['bbox']['x1'] ?? null,
                'bbox_y1' => $feature['bbox']['y1'] ?? null,
                'bbox_w' => $feature['bbox']['w'] ?? null,
                'bbox_h' => $feature['bbox']['h'] ?? null,
                'rectangularity' => $feature['rectangularity'] ?? null,
                'centroid_x' => $feature['centroid']['x'] ?? null,
                'centroid_y' => $feature['centroid']['y'] ?? null,
                'points_xy' => $feature['points_xy'] ?? null,
            ]);
        }
    }

    private function recordEvent(
        CadApprovalApplication $application,
        string $eventType,
        string $message,
        ?CadApprovalPlan $plan = null,
        ?array $payload = null
    ): void {
        CadApprovalEvent::create([
            'cad_approval_application_id' => $application->id,
            'cad_approval_plan_id' => $plan?->id,
            'event_type' => $eventType,
            'message' => $message,
            'payload' => $payload,
        ]);
    }

    private function ensurePlanBelongsToApplication(CadApprovalApplication $application, CadApprovalPlan $plan): void
    {
        abort_unless($plan->cad_approval_application_id === $application->id, 404);
    }

    private function validateDetails(Request $request): array
    {
        return $request->validate([
            'applicant_name' => ['required', 'string', 'max:255'],
            'identification_number' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:255'],
            'mobile_number' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'application_type' => ['nullable', 'string', 'max:255'],
            'plot_number' => ['required', 'string', 'max:255'],
            'scheme' => ['nullable', 'string', 'max:255'],
            'phase' => ['nullable', 'string', 'max:255'],
            'block' => ['nullable', 'string', 'max:255'],
            'plot_size_category' => ['required', Rule::in(array_keys($this->plotSizeOptions()))],
            'plot_area_sqft' => ['nullable', 'numeric', 'min:0'],
            'building_type' => ['nullable', 'string', 'max:255'],
            'property_type' => ['nullable', 'string', 'max:255'],
            'submitted_floors' => ['nullable', 'array'],
            'submitted_floors.*' => ['string', Rule::in($this->floorOptions())],
            'has_basement' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string'],
            'ruleset' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function plotSizeOptions(): array
    {
        $meta = $this->ruleService->loadRulesMeta();
        $options = [];

        foreach (($meta['plot_size_categories'] ?? []) as $key => $config) {
            $options[$key] = $config['label'] ?? $key;
        }

        return $options;
    }

    private function mapApplicationStatus(CadApprovalApplication $application): string
    {
        $finalStatus = $this->ruleService->determineFinalStatus($application->fresh('plans'));

        return match ($finalStatus) {
            'needs_expert_review' => 'Needs Expert Review',
            'needs_correction' => 'needs_correction',
            'ready_for_submission', 'ready_for_submission_with_manual_notes' => 'Layer Validation Completed',
            default => 'processing',
        };
    }

    private function normalizeRuleValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value);
    }

    private function hasFailedRules(array $run): bool
    {
        foreach (($run['rules'] ?? []) as $rule) {
            if (is_array($rule) && (($rule['pass'] ?? null) === false)) {
                return true;
            }
        }

        return false;
    }

    private function floorOptions(): array
    {
        return [
            'basement',
            'ground',
            'first',
            'second',
            'roof',
            'site',
            'services',
        ];
    }
}
