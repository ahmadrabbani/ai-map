@extends('layouts.app')

@section('title', 'Expert Review')

@section('content')
    <div class="d-flex justify-content-between align-items-center page-header">
        <div>
            <h1>Expert Review</h1>
            <div class="subtitle">Step 4: review detected geometry, use measurement tools, and override system detection where needed.</div>
        </div>
        <a href="{{ route('admin.plan.approval-wizard.show', $application) }}" class="btn btn-outline-secondary">Back to Wizard</a>
    </div>

    @include('admin.cad-approval.partials.steps', ['currentStep' => 'expert_review'])

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">Review Plans</div>
                <div class="card-body">
                    <p class="text-muted small">
                        Use the existing viewer for pan, zoom, polygon selection, distance measurement, layer toggling, and expert marking. Low-confidence or unclear layer matches should be reviewed here before final submission.
                    </p>
                    <div class="alert alert-warning small">
                        Expert marking overrides low-confidence system detection. After adjusting labels or geometry, rerun plan processing so the compliance output uses the corrected mapping.
                    </div>
                    <div class="row g-3">
                        @foreach ($application->plans as $plan)
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="fw-semibold">{{ $plan->label }}</div>
                                    <div class="small text-muted mb-2">{{ str_replace('_', ' ', $plan->status) }}</div>
                                    <div class="d-grid gap-2">
                                        @if ($plan->cad_submission_id)
                                            <a href="{{ route('admin.plan.cad-layer-viewer', $plan->cad_submission_id) }}" class="btn btn-sm btn-outline-primary">Open Measurement Viewer</a>
                                            <a href="{{ route('admin.plan.cad-expert-label.edit', $plan->cad_submission_id) }}" class="btn btn-sm btn-outline-warning">Open Expert Labeling</a>
                                        @else
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled>No CAD submission yet</button>
                                        @endif
                                    </div>
                                    @if (!empty($plan->layer_validation_json))
                                        <div class="mt-3 small">
                                            <div><strong>Layer validation:</strong> {{ $plan->layer_validation_json['status'] ?? 'n/a' }}</div>
                                            <div><strong>Confidence:</strong> {{ $plan->confidence_score ?? 'n/a' }}</div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            @include('admin.cad-approval.partials.layer-guidelines-table', ['guidelineSummary' => $guidelineSummary])
            <div class="card mt-4">
                <div class="card-header">Manual Expert Marking Log</div>
                <div class="card-body">
                    <div class="small text-muted mb-3">
                        Suggested marking types: plot boundary, building footprint, basement footprint, ground floor footprint, and front/rear/side setbacks.
                    </div>
                    <form method="POST" action="{{ route('admin.plan.approval-wizard.save-expert-marking', $application) }}" class="row g-3">
                        @csrf
                        <div class="col-12">
                            <label class="form-label">Plan Reference</label>
                            <select class="form-select" name="cad_approval_plan_id">
                                <option value="">General application note</option>
                                @foreach ($application->plans as $plan)
                                    <option value="{{ $plan->id }}">{{ $plan->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Floor</label>
                            <select class="form-select" name="floor_type">
                                <option value="">General</option>
                                @foreach ($application->plans as $plan)
                                    <option value="{{ $plan->floor_type }}">{{ $plan->label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Marking Type</label>
                            <select class="form-select" name="marking_type" required>
                                @foreach (['plot_boundary','building_footprint','basement','setback_front','setback_rear','setback_left','setback_right'] as $type)
                                    <option value="{{ $type }}">{{ str_replace('_', ' ', $type) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Measured Area</label>
                            <input type="number" step="0.001" class="form-control" name="measured_area">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Measured Length</label>
                            <input type="number" step="0.001" class="form-control" name="measured_length">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Remarks</label>
                            <textarea class="form-control" name="remarks" rows="3" placeholder="Expert review remarks or manual override note"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Save Expert Marking Note</button>
                        </div>
                    </form>

                    @if ($application->expertMarkings->isNotEmpty())
                        <hr>
                        <div class="small">
                            @foreach ($application->expertMarkings as $marking)
                                <div class="border rounded p-2 mb-2">
                                    <strong>{{ str_replace('_', ' ', $marking->marking_type) }}</strong>
                                    <div>{{ $marking->remarks ?: 'No remarks' }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
