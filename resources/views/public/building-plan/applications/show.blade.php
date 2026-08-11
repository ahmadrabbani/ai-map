@extends('public.building-plan.layout')
@section('title', 'Application Detail')
@section('content')
@if(($unreadAdEpermitMessages ?? 0) > 0)
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <strong>AD ePermit sent you {{ $unreadAdEpermitMessages }} new {{ \Illuminate\Support\Str::plural('message', $unreadAdEpermitMessages) }}.</strong>
        Open the live chat to read and reply.
    </div>
    <button class="btn btn-sm btn-warning fw-semibold" type="button" data-open-ad-epermit-chat>Open AD ePermit Chat</button>
</div>
@endif

<div class="card mb-3">
    <div class="card-header fw-semibold">Application Detail</div>
    <div class="card-body row g-3">
        <div class="col-md-4"><strong>Application No:</strong> {{ $application->application_no ?: 'Draft-' . $application->id }}</div>
        <div class="col-md-4"><strong>Current Status:</strong> {{ $application->status }}</div>
        <div class="col-md-4"><strong>AI Status:</strong> {{ $application->ai_status }}</div>
        <div class="col-md-6"><strong>Applicant:</strong> {{ $application->applicant_name }} ({{ $application->applicant_cnic }})</div>
        <div class="col-md-6"><strong>Contact:</strong> {{ $application->applicant_phone }} | {{ $application->applicant_email }}</div>
        <div class="col-md-6"><strong>Property:</strong> {{ $application->scheme }} / {{ $application->phase ?: '-' }} / {{ $application->block ?: '-' }}</div>
        <div class="col-md-6"><strong>Plot Ref:</strong> {{ $application->plot_ref }}</div>
        <div class="col-12"><strong>Address:</strong> {{ $application->selected_address }}</div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header fw-semibold">Uploaded Documents</div>
    <div class="card-body table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th>Type</th><th>Validation</th><th>Message</th><th>Preview/Download</th></tr></thead>
            <tbody>
            @forelse($application->documents as $doc)
                <tr>
                    <td>{{ str_replace('_', ' ', ucfirst($doc->document_type)) }}</td>
                    <td>{{ ucfirst($doc->validation_status) }}</td>
                    <td>{{ $doc->validation_message }}</td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('public.bp.applications.document', [$application->id, $doc->id]) }}">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted text-center">No documents found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header fw-semibold">AI Scrutiny Result</div>
    <div class="card-body">
        <div class="mb-2 d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('public.bp.applications.report', $application->id) }}">View AI Report</a>
            <a class="btn btn-outline-dark btn-sm" href="{{ route('public.bp.applications.download-report', $application->id) }}">Download AI Report</a>
            @if(strtolower(pathinfo((string) $application->plan_file_path, PATHINFO_EXTENSION)) === 'pdf')
                <a class="btn btn-primary btn-sm" target="_blank" href="{{ route('public.bp.applications.plan-pdf', $application->id) }}">Open Plan PDF</a>
            @endif
        </div>
        @if($application->qr_code_path)
            <img src="{{ $application->qr_code_path }}" alt="QR" style="max-width:180px">
        @endif
    </div>
</div>

@if(strtolower(pathinfo((string) $application->plan_file_path, PATHINFO_EXTENSION)) === 'pdf')
<div class="card mb-3">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span>Uploaded Plan PDF (Printable)</span>
        <a class="btn btn-sm btn-outline-primary" target="_blank" href="{{ route('public.bp.applications.plan-pdf', $application->id) }}">Print / Save PDF</a>
    </div>
    <div class="card-body p-0">
        <iframe src="{{ route('public.bp.applications.plan-pdf', $application->id) }}" style="width:100%;height:900px;border:0;"></iframe>
    </div>
</div>
@endif

<div class="card">
    <div class="card-header fw-semibold">Timeline</div>
    <div class="card-body">
        <ul class="mb-0">
            <li>Registered: {{ $application->created_at?->format('d M Y H:i') }}</li>
            <li>Documents uploaded: {{ $application->created_at?->format('d M Y H:i') }}</li>
            <li>AI scrutiny generated: {{ $application->submitted_at?->format('d M Y H:i') ?: 'Pending' }}</li>
            <li>Sent for expert review: {{ $application->status === 'Needs Expert Review' ? 'Yes' : 'Pending' }}</li>
            <li>Routed to AD ePermit/DDTP: {{ $application->routed_to ?: 'Pending' }}</li>
        </ul>
    </div>
</div>

@include('public.building-plan.partials-chatbot', ['application' => $application])
@endsection
