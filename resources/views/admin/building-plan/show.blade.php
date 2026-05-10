@extends('layouts.app')

@section('title', 'Building Plan Application')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Application {{ $application->application_number }}</h1>
        <div class="text-muted">Status: {{ $application->status }}</div>
        <div class="mt-1">
            <span class="badge text-bg-dark">Application ID: {{ $application->application_number }}</span>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="{{ route('admin.plan.bp.portal', $application) }}">Applicant Portal</a>
        <a class="btn btn-outline-secondary" href="{{ route('admin.plan.bp.report.show', $application) }}">AI Report</a>
        <a class="btn btn-outline-secondary" href="{{ route('admin.plan.bp.verify', $application->qr_token) }}">QR Verify Page</a>
        <a class="btn btn-outline-primary" href="{{ route('admin.plan.bp.ad.index') }}">AD ePermit Dashboard</a>
        <a class="btn btn-outline-secondary" href="{{ route('admin.plan.bp.ddtp.index') }}">DDTP Dashboard</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">Application Details</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-6"><strong>Applicant:</strong> {{ $application->applicant_name ?: '-' }}</div>
                    <div class="col-md-6"><strong>Email:</strong> {{ $application->applicant_email ?: '-' }}</div>
                    <div class="col-md-6"><strong>Phone:</strong> {{ $application->applicant_phone ?: '-' }}</div>
                    <div class="col-md-6"><strong>File:</strong> {{ $application->uploaded_file_name }} ({{ strtoupper($application->uploaded_file_type) }})</div>
                    <div class="col-md-12"><strong>Verification URL:</strong> <a href="{{ $application->verification_url }}">{{ $application->verification_url }}</a></div>
                    <div class="col-md-12"><strong>List Document:</strong> {{ $application->metadata_doc_name ?: '-' }}</div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Auto-filled From List Document</div>
            <div class="card-body">
                <div class="row g-2 mb-2">
                    <div class="col-md-4"><strong>Applicant Name:</strong> {{ data_get($application->applicant_data_json, 'name') ?: '-' }}</div>
                    <div class="col-md-4"><strong>Email:</strong> {{ data_get($application->applicant_data_json, 'email') ?: '-' }}</div>
                    <div class="col-md-4"><strong>Phone:</strong> {{ data_get($application->applicant_data_json, 'phone') ?: '-' }}</div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-4"><strong>Plot No:</strong> {{ data_get($application->plot_data_json, 'plot_no') ?: '-' }}</div>
                    <div class="col-md-4"><strong>Plot Size:</strong> {{ data_get($application->plot_data_json, 'plot_size') ?: '-' }}</div>
                    <div class="col-md-4"><strong>Street:</strong> {{ data_get($application->plot_data_json, 'street') ?: '-' }}</div>
                </div>
                <div>
                    <strong>Detected Layer Rows:</strong> {{ is_array($application->layer_table_json) ? count($application->layer_table_json) : 0 }}
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">AI Summary</div>
            <div class="card-body">
                @if($application->aiReport)
                    <div><strong>Analysis Status:</strong> {{ $application->aiReport->analysis_status }}</div>
                    <div><strong>AI Recommendation:</strong> {{ $application->aiReport->ai_recommendation }}</div>
                    <div><strong>AI Confidence Score:</strong> {{ $application->aiReport->ai_confidence_score }}%</div>
                    <div><strong>Warnings:</strong> {{ count($application->aiReport->warnings_json ?: []) }}</div>
                    <div><strong>Expert Review Items:</strong> {{ count($application->aiReport->expert_review_items_json ?: []) }}</div>
                @else
                    <div class="text-muted">AI report is not available.</div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">Submit to AD ePermit</div>
            <div class="card-body">
                <div class="mb-2 text-muted">
                    Flow: Uploaded -> AI Report Generated -> Submitted to AD ePermit -> Under AD Review -> Forwarded to DDTP -> DDTP Decision.
                </div>
                <form method="POST" action="{{ route('admin.plan.bp.submit-ad', $application) }}">
                    @csrf
                    <button class="btn btn-success" type="submit">Submit Application to AD ePermit</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">QR Code</div>
            <div class="card-body text-center">
                @if($application->qr_code_url)
                    <img src="{{ $application->qr_code_url }}" alt="QR" style="max-width:220px; width:100%; height:auto;">
                @endif
            </div>
        </div>

        @include('admin.building-plan.partials-chatbot', ['application' => $application])
    </div>
</div>
@endsection
