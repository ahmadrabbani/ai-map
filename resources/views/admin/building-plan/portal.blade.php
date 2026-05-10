@extends('layouts.app')

@section('title', 'Building Plan Application Portal')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1">Your Building Plan Application</h1>
        <div class="text-muted">Application ID: <strong>{{ $application->application_number }}</strong></div>
        <div class="text-muted">Current status: {{ $application->status }}</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="{{ route('admin.plan.bp.verify', $application->qr_token) }}">QR Verification Page</a>
        <a class="btn btn-outline-secondary" href="{{ route('admin.plan.bp.report.show', $application) }}">View AI Report</a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">Auto Report Cards (From Uploaded Textual Table)</div>
            <div class="card-body">
                <div class="row g-2">
                    @foreach($reportCards as $card)
                        <div class="col-md-6">
                            <div class="border rounded p-2 h-100">
                                <div class="fw-bold">{{ $card['key'] }} {{ $card['title'] }}</div>
                                <div class="text-muted small mb-2">{{ $card['description'] }}</div>
                                @foreach($card['items'] as $label => $value)
                                    <div><strong>{{ $label }}:</strong> {{ ($value === null || $value === '') ? '-' : $value }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        @if(!empty($sectionCards))
            <div class="card mb-3">
                <div class="card-header">Detected Section Rows From Document</div>
                <div class="card-body">
                    <div class="row g-2">
                        @foreach($sectionCards as $row)
                            <div class="col-md-6">
                                <div class="border rounded p-2 h-100">
                                    <div class="fw-bold">{{ $row['key'] }} {{ $row['title'] }}</div>
                                    <div class="text-muted small">{{ $row['description'] ?: 'No description' }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-header">AI Summary (Layman View)</div>
            <div class="card-body">
                <div><strong>AI Recommendation:</strong> {{ $application->aiReport->ai_recommendation ?? 'Needs Expert Review' }}</div>
                <div><strong>AI Confidence:</strong> {{ number_format((float) ($application->aiReport->ai_confidence_score ?? 0), 2) }}%</div>
                <div class="mt-2">
                    <span class="badge text-bg-success me-1">Passed: {{ $counts['pass'] }}</span>
                    <span class="badge text-bg-danger me-1">Failed: {{ $counts['fail'] }}</span>
                    <span class="badge text-bg-warning text-dark">Needs Review: {{ $counts['needs_review'] }}</span>
                </div>
                <div class="mt-3">
                    <strong>Key findings</strong>
                    <ul class="mb-0 mt-2">
                        @forelse($keyFindings as $finding)
                            <li>
                                <strong>{{ $finding['rule'] }}</strong> ({{ $finding['status'] }}):
                                {{ $finding['message'] }}
                                @if($finding['required'] !== null || $finding['actual'] !== null)
                                    [Required: {{ $finding['required'] ?? '-' }}, Actual: {{ $finding['actual'] ?? '-' }}]
                                @endif
                            </li>
                        @empty
                            <li>No critical issues detected in AI preliminary analysis.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Submit For Authority Review</div>
            <div class="card-body">
                <p class="text-muted mb-2">
                    Once submitted, the technical CAD layer analysis and chat conversation will be available to AD ePermit for official review.
                </p>
                <form method="POST" action="{{ route('admin.plan.bp.submit-ad', $application) }}">
                    @csrf
                    <button class="btn btn-success" type="submit">Submit to AD ePermit</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        @include('admin.building-plan.partials-chatbot', ['application' => $application])
    </div>
</div>

<div class="alert alert-info mt-3 mb-0">
    This portal shows applicant-friendly AI guidance from extracted text/table data and AI analysis output. Final approval/rejection is decided only by the competent authority.
</div>
@endsection
