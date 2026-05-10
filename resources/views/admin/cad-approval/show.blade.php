@extends('layouts.app')

@section('title', 'CAD Approval Application')

@section('content')
    <div class="d-flex justify-content-between align-items-center page-header">
        <div>
            <h1>Application #{{ $application->id }}</h1>
            <div class="subtitle">{{ $application->applicant_name }} • Plot {{ $application->plot_number }} • Status: {{ str_replace('_', ' ', $application->status) }}</div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.plan.approval-wizard.summary', $application) }}" class="btn btn-outline-secondary">Summary</a>
            @if (! empty($application->final_report_json))
                <a href="{{ route('admin.plan.approval-wizard.report', $application) }}" class="btn btn-outline-primary">Final Report</a>
            @endif
        </div>
    </div>

    @include('admin.cad-approval.partials.steps', ['currentStep' => $application->current_step ?: 'details'])

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if (session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header">Application Details</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.plan.approval-wizard.update-details', $application) }}" class="row g-3">
                        @csrf
                        @include('admin.cad-approval.partials.details-form-fields', ['plotSizeOptions' => $plotSizeOptions, 'floorOptions' => $floorOptions])
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm">Update Details</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Verified Data</div>
                <div class="card-body">
                    <p class="small text-muted mb-2">Confirm applicant, plot, basement, and floor details before uploading drawings.</p>
                    <div class="small mb-3">
                        <strong>Verification status:</strong>
                        {{ empty($application->verification_answers_json) ? 'Pending' : 'Saved' }}
                    </div>
                    <a href="{{ route('admin.plan.approval-wizard.verification', $application) }}" class="btn btn-outline-primary btn-sm">Open Verification Step</a>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Rules Summary</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-6">Plot Size</dt>
                        <dd class="col-sm-6">{{ $summary['plot_size_label'] }}</dd>
                        <dt class="col-sm-6">Submitted Floors</dt>
                        <dd class="col-sm-6">{{ collect($application->submitted_floors ?? [])->map(fn ($floor) => ucfirst($floor))->implode(', ') ?: 'Not selected' }}</dd>
                        <dt class="col-sm-6">Ground Floor</dt>
                        <dd class="col-sm-6">Required</dd>
                        <dt class="col-sm-6">Basement</dt>
                        <dd class="col-sm-6">{{ $summary['basement_required'] ? 'Required' : 'Optional' }}</dd>
                        <dt class="col-sm-6">Required Uploads</dt>
                        <dd class="col-sm-6">{{ $summary['required_uploads_complete'] ? 'Complete' : 'Pending' }}</dd>
                        <dt class="col-sm-6">Final Status</dt>
                        <dd class="col-sm-6">{{ str_replace('_', ' ', $summary['final_status']) }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Actions</div>
                <div class="card-body d-grid gap-2">
                    <form method="POST" action="{{ route('admin.plan.approval-wizard.save-draft', $application) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-dark w-100">Save Draft</button>
                    </form>
                    <a href="{{ route('admin.plan.approval-wizard.expert-review', $application) }}" class="btn btn-outline-warning">Open Expert Review</a>
                    <a href="{{ route('admin.plan.approval-wizard.summary', $application) }}" class="btn btn-outline-secondary">Open Summary</a>
                    <form method="POST" action="{{ route('admin.plan.approval-wizard.generate-report', $application) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary w-100">Generate Final Report</button>
                    </form>
                    <form method="POST" action="{{ route('admin.plan.approval-wizard.submit', $application) }}">
                        @csrf
                        <button type="submit" class="btn btn-success w-100">Submit for Processing</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            @include('admin.cad-approval.partials.layer-guidelines-table', ['guidelineSummary' => $guidelineSummary])
            <div class="card mb-4">
                <div class="card-header">Step 3: Upload Plans and Validate Layers</div>
                <div class="card-body">
                    <div class="alert alert-info small">
                        Drawings should follow the official layer naming guidelines from <code>list.pdf</code>. The system first checks known guideline layers, then aliases, and only uses geometry as a fallback. Any uncertain detection is flagged for expert review.
                    </div>
                    <div class="alert alert-secondary small">
                        Repeat uploads floor by floor until all required plans are complete. You can stop after the highest submitted floor or when no more plans need to be added.
                    </div>
                    <form method="POST" action="{{ route('admin.plan.approval-wizard.upload-plans', $application) }}" enctype="multipart/form-data">
                        @csrf
                        <div class="row g-3">
                            @foreach ($application->plans as $plan)
                                <div class="col-md-6">
                                    @include('admin.cad-approval.partials.plan-upload-card', ['plan' => $plan, 'application' => $application])
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-3 d-flex justify-content-between align-items-center">
                            <div class="text-muted small">Accepted formats: DWG, DXF, PDF. Max upload size: {{ config('cad_approval.max_upload_kb', 51200) / 1024 }} MB.</div>
                            <button type="submit" class="btn btn-primary">Upload Selected Files</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">Step 4 Preview: Rule and Review Readiness</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Final Status</div>
                                <div class="fw-semibold">{{ str_replace('_', ' ', $ruleValidation['final_status'] ?? 'pending') }}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Plans Requiring Review</div>
                                <div class="fw-semibold">{{ collect($application->plans)->where('status', 'needs_expert_review')->count() }}</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded p-3 h-100">
                                <div class="text-muted small">Passed Plans</div>
                                <div class="fw-semibold">{{ collect($application->plans)->where('status', 'passed')->count() }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Activity Log</div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        @forelse ($application->events as $event)
                            <div class="list-group-item px-0">
                                <div class="d-flex justify-content-between">
                                    <strong>{{ $event->message }}</strong>
                                    <span class="text-muted small">{{ $event->created_at?->diffForHumans() }}</span>
                                </div>
                                <div class="small text-muted">{{ $event->event_type }}</div>
                            </div>
                        @empty
                            <div class="text-muted">No events logged yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="mt-4">
                @include('admin.cad-approval.partials.faq-assistant')
            </div>
        </div>
    </div>
@endsection

@section('footer_scripts')
    @parent
    @stack('footer_scripts_inline')
@endsection
