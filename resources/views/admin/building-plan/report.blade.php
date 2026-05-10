@extends('layouts.app')

@section('title', 'AI Report')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">AI Report: {{ $application->application_number }}</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.plan.bp.report.download', $application) }}" class="btn btn-primary">Download Report PDF</a>
        <a href="{{ route('admin.plan.bp.show', $application) }}" class="btn btn-outline-secondary">Back</a>
    </div>
</div>

@if($report)
    <div class="card mb-3">
        <div class="card-header">Report Header</div>
        <div class="card-body row g-2">
            <div class="col-md-4"><strong>Application No:</strong> {{ $application->application_number }}</div>
            <div class="col-md-4"><strong>AI Status:</strong> {{ $report->analysis_status }}</div>
            <div class="col-md-4"><strong>AI Recommendation:</strong> {{ $report->ai_recommendation }}</div>
            <div class="col-md-4"><strong>AI Confidence:</strong> {{ $report->ai_confidence_score }}%</div>
            <div class="col-md-8"><strong>File:</strong> {{ $application->uploaded_file_name }}</div>
            <div class="col-md-4"><strong>Textual Recommendation:</strong> {{ $textualRecommendation }}</div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Textual Data vs AI/CAD Read Values</div>
        <div class="card-body">
            <div class="alert alert-info mb-3">
                This section uses the architect-provided text table layers first. Pass/fail below is based on textual values extracted from the drawing, while AI/CAD values are shown beside them for officer verification.
            </div>
            @if(!empty($textualFindings))
                <ul class="mb-3">
                    @foreach($textualFindings as $finding)
                        <li>{{ $finding }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Item</th>
                        <th>Textual Value</th>
                        <th>AI/CAD Value</th>
                        <th>Required</th>
                        <th>Status From Text</th>
                        <th>Basis</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($comparisonRows as $row)
                        @php
                            $badge = match($row['status']) {
                                'pass' => 'text-bg-success',
                                'fail' => 'text-bg-danger',
                                'reference' => 'text-bg-secondary',
                                default => 'text-bg-warning text-dark',
                            };
                        @endphp
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td>{{ ($row['textual_value'] === null || $row['textual_value'] === '') ? '-' : $row['textual_value'] }}</td>
                            <td>{{ ($row['ai_value'] === null || $row['ai_value'] === '') ? '-' : $row['ai_value'] }}</td>
                            <td>
                                @if($row['operator'] && $row['required'] !== null)
                                    {{ $row['operator'] }} {{ $row['required'] }}
                                @else
                                    Reference
                                @endif
                            </td>
                            <td><span class="badge {{ $badge }}">{{ strtoupper(str_replace('_', ' ', $row['status'])) }}</span></td>
                            <td class="text-muted small">{{ $row['basis'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Rule-wise Compliance Results</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr><th>Rule</th><th>Status</th><th>Required</th><th>Actual</th></tr>
                    </thead>
                    <tbody>
                    @foreach(($report->rule_results_json ?? []) as $row)
                        <tr>
                            <td>{{ $row['id'] ?? $row['rule_code'] ?? 'N/A' }}</td>
                            <td>{{ $row['status'] ?? (($row['pass'] ?? null) === true ? 'pass' : (($row['pass'] ?? null) === false ? 'fail' : 'needs_review')) }}</td>
                            <td>{{ $row['required'] ?? $row['required_value'] ?? '-' }}</td>
                            <td>{{ $row['measured'] ?? $row['actual'] ?? $row['actual_value'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Disclaimer</div>
        <div class="card-body">
            {{ $disclaimer }}
        </div>
    </div>
@else
    <div class="alert alert-warning">No AI report found.</div>
@endif
@endsection
