@extends('layouts.app')

@section('title', 'CAD Approval Summary')

@section('content')
    <div class="d-flex justify-content-between align-items-center page-header">
        <div>
            <h1>Application Summary</h1>
            <div class="subtitle">Review mandatory uploads, plan status, and readiness before generating the final report.</div>
        </div>
        <a href="{{ route('admin.plan.approval-wizard.show', $application) }}" class="btn btn-outline-secondary">Back to Wizard</a>
    </div>

    @include('admin.cad-approval.partials.steps', ['currentStep' => 'summary'])

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Overall Status</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-6">Application</dt>
                        <dd class="col-sm-6">{{ str_replace('_', ' ', $application->status) }}</dd>
                        <dt class="col-sm-6">Final Status</dt>
                        <dd class="col-sm-6">{{ str_replace('_', ' ', $summary['final_status']) }}</dd>
                        <dt class="col-sm-6">Basement</dt>
                        <dd class="col-sm-6">{{ $summary['basement_required'] ? 'Required' : 'Optional' }}</dd>
                    </dl>
                </div>
            </div>
            <div class="card mt-4">
                <div class="card-header">Verification Answers</div>
                <div class="card-body">
                    @forelse (($application->verification_answers_json ?? []) as $key => $answer)
                        <div class="border rounded p-2 mb-2 small">
                            <div class="fw-semibold">{{ str_replace('_', ' ', $key) }}</div>
                            <div><strong>Answer:</strong> {{ strtoupper($answer['answer'] ?? '-') }}</div>
                            @if (!empty($answer['remarks']))
                                <div><strong>Remarks:</strong> {{ $answer['remarks'] }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="text-muted small">No verification answers saved yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            @include('admin.cad-approval.partials.layer-guidelines-table', ['guidelineSummary' => $guidelineSummary])
            <div class="card">
                <div class="card-header">Plan Checklist and Validation</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Plan</th>
                                    <th>Requirement</th>
                                    <th>Uploaded</th>
                                    <th>Status</th>
                                    <th>Layer Validation</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($application->plans as $plan)
                                    <tr>
                                        <td>{{ $plan->label }}</td>
                                        <td>{{ $plan->is_required ? 'Required' : 'Optional' }}</td>
                                        <td>{{ $plan->is_uploaded ? 'Yes' : 'No' }}</td>
                                        <td>{{ str_replace('_', ' ', $plan->status) }}</td>
                                        <td>{{ str_replace('_', ' ', $plan->layer_validation_json['status'] ?? 'pending') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <a href="{{ route('admin.plan.approval-wizard.expert-review', $application) }}" class="btn btn-outline-warning">Expert Review</a>
                    <form method="POST" action="{{ route('admin.plan.approval-wizard.generate-report', $application) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary">Generate Final Report</button>
                    </form>
                    @if (! empty($application->final_report_json))
                        <a href="{{ route('admin.plan.approval-wizard.report', $application) }}" class="btn btn-outline-primary">View Report</a>
                    @endif
                </div>
            </div>
            <div class="card mt-4">
                <div class="card-header">Rule Validation Summary</div>
                <div class="card-body">
                    <div class="mb-3">
                        <strong>Application outcome:</strong> {{ str_replace('_', ' ', $ruleValidation['final_status'] ?? 'pending') }}
                    </div>
                    <div class="accordion" id="ruleValidationAccordion">
                        @foreach (($ruleValidation['floors'] ?? []) as $floor)
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="heading-{{ $loop->index }}">
                                    <button class="accordion-button {{ $loop->first ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-{{ $loop->index }}">
                                        {{ $floor['label'] ?? ucfirst($floor['floor_type'] ?? 'Floor') }} • {{ str_replace('_', ' ', $floor['status'] ?? 'pending') }}
                                    </button>
                                </h2>
                                <div id="collapse-{{ $loop->index }}" class="accordion-collapse collapse {{ $loop->first ? 'show' : '' }}" data-bs-parent="#ruleValidationAccordion">
                                    <div class="accordion-body small">
                                        <div class="mb-2"><strong>Measurements:</strong></div>
                                        <pre class="bg-light border rounded p-2">{{ json_encode($floor['measurements'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        <div class="mb-2"><strong>Failed rules:</strong> {{ count($floor['failed_rules'] ?? []) }}</div>
                                        <div><strong>Manual review rules:</strong> {{ count($floor['manual_review_rules'] ?? []) }}</div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="card mt-4">
                <div class="card-header">Expert Marking Summary</div>
                <div class="card-body">
                    @forelse ($application->expertMarkings as $marking)
                        <div class="border rounded p-3 mb-2 small">
                            <div class="fw-semibold">{{ str_replace('_', ' ', $marking->marking_type) }}</div>
                            <div><strong>Floor:</strong> {{ $marking->floor_type ?: 'General' }}</div>
                            @if (!is_null($marking->measured_area))
                                <div><strong>Measured Area:</strong> {{ $marking->measured_area }}</div>
                            @endif
                            @if (!is_null($marking->measured_length))
                                <div><strong>Measured Length:</strong> {{ $marking->measured_length }}</div>
                            @endif
                            @if (!empty($marking->remarks))
                                <div><strong>Remarks:</strong> {{ $marking->remarks }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="text-muted small">No expert marking records saved yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
