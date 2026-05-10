@extends('layouts.app')

@section('title', 'CAD Approval Report')

@section('content')
    <div class="d-flex justify-content-between align-items-center page-header">
        <div>
            <h1>Final Report</h1>
            <div class="subtitle">Applicant-facing summary with internal review notes for Application #{{ $application->id }}.</div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.plan.approval-wizard.show', $application) }}" class="btn btn-outline-secondary">Back to Wizard</a>
            @if ($application->final_report_pdf_path)
                <a href="{{ asset('storage/' . $application->final_report_pdf_path) }}" class="btn btn-outline-primary" target="_blank">Open PDF</a>
            @endif
        </div>
    </div>

    @include('admin.cad-approval.partials.steps', ['currentStep' => 'final_report'])

    @if (! $application->final_report_pdf_path)
        <div class="alert alert-warning">TODO: configure a PDF generation library to enable downloadable report PDFs.</div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Application</div>
                    <div class="fw-semibold">#{{ $application->id }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Final Status</div>
                    <div class="fw-semibold">{{ str_replace('_', ' ', $report['final_status'] ?? 'pending') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Uploaded Plans</div>
                    <div class="fw-semibold">{{ count($report['uploaded_plans'] ?? []) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Expert Review Items</div>
                    <div class="fw-semibold">{{ count($report['expert_review_required_items'] ?? []) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="mb-4">
                <h5>1. Application Details</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['application_details'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>2. Required vs Optional Plan Checklist</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['required_optional_plan_checklist'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>3. Basement Requirement Decision</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['basement_requirement_decision'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>4. Uploaded Plans</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['uploaded_plans'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>5. CAD Processing Status</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['cad_processing_status'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>6. Floor-wise Analysis</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['floor_wise_analysis'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>7. Rule Compliance Summary</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['rule_compliance_summary'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>8. Expert Review Required Items</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['expert_review_required_items'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>9. Textual Records</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['textual_records'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>10. Measurable Records</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['measurable_records'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>11. Training Records</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['training_records'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>12. Finalized System</h5>
                <pre class="bg-light border rounded p-3">{{ json_encode($report['finalized_system'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            <div class="mb-4">
                <h5>13. Final Recommendation</h5>
                <p class="mb-0">{{ $report['final_recommendation'] ?? 'Report not generated yet.' }}</p>
            </div>
            <div>
                <h5>14. Next Steps</h5>
                <ul class="mb-0">
                    @foreach (($report['next_steps'] ?? []) as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endsection
