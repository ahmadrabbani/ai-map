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
    @php
        $cadConfidence = is_array(data_get($report, 'analysis_json.cad_confidence_assessment'))
            ? data_get($report, 'analysis_json.cad_confidence_assessment')
            : [];
        $dxfPatternProfile = is_array(data_get($report, 'analysis_json.dxf_pattern_profile'))
            ? data_get($report, 'analysis_json.dxf_pattern_profile')
            : [];
    @endphp
    <div class="card mb-3">
        <div class="card-header">Report Header</div>
        <div class="card-body row g-2">
            <div class="col-md-4"><strong>Application No:</strong> {{ $application->application_number }}</div>
            <div class="col-md-4"><strong>AI Status:</strong> {{ $report->analysis_status }}</div>
            <div class="col-md-4"><strong>AI Recommendation:</strong> {{ $report->ai_recommendation }}</div>
            <div class="col-md-4"><strong>AI Confidence:</strong> {{ $report->ai_confidence_score }}%</div>
            <div class="col-md-4"><strong>CAD Confidence:</strong> {{ number_format((float) data_get($cadConfidence, 'confidence_score', 0), 2) }}% ({{ strtoupper((string) data_get($cadConfidence, 'confidence_level', 'unknown')) }})</div>
            <div class="col-md-4"><strong>DXF Pattern:</strong> {{ data_get($dxfPatternProfile, 'pattern_family', 'generic_dxf') }} ({{ number_format((float) data_get($dxfPatternProfile, 'pattern_strength', 0), 2) }})</div>
            <div class="col-md-4"><strong>Dimension Source:</strong> {{ data_get($cadConfidence, 'dimension_source', 'unknown') }}</div>
            <div class="col-md-4"><strong>Fallback:</strong> {{ data_get($cadConfidence, 'fallback_method_used', 'unknown') }}</div>
            <div class="col-12"><strong>Missing Layers:</strong> {{ implode(', ', (array) data_get($cadConfidence, 'missing_layers', [])) ?: '-' }}</div>
            @if(!empty(data_get($cadConfidence, 'warnings', [])))
                <div class="col-12">
                    <strong>Warnings:</strong>
                    <ul class="mb-0">
                        @foreach((array) data_get($cadConfidence, 'warnings', []) as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
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
                        <th>Provided</th>
                        <th>AI/CAD Value</th>
                        <th>Required</th>
                        <th>Status From Text</th>
                        <th>Basis</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($comparisonRows as $row)
                        @php
                            $displayStatus = $row['status'] === 'pass' ? 'clear' : $row['status'];
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
                                    {{ $row['required'] ?? '-' }}
                                @endif
                            </td>
                            <td><span class="badge {{ $badge }}">{{ strtoupper(str_replace('_', ' ', $displayStatus)) }}</span></td>
                            <td class="text-muted small">{{ $row['basis'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Further Details: Room / Space Areas From CAD Text</div>
        <div class="card-body">
            <details>
                <summary class="fw-bold">
                    Show room, bath, lounge, kitchen and other box calculations
                    @if(!empty($roomAreas))
                        ({{ count($roomAreas) }} item{{ count($roomAreas) === 1 ? '' : 's' }})
                    @endif
                </summary>
                <div class="text-muted small mt-2 mb-3">
                    These rows are parsed from CAD text overlays. The matched category can come from the visible text or the CAD layer hint.
                    Area is calculated as width x height from nearby dimension text. Keys use floor prefixes: BF, GF, FF, SF, TH.
                </div>
                @if(!empty($roomAreaTotals))
                    <div class="row g-2 mb-3">
                        @foreach($roomAreaTotals as $total)
                            <div class="col-md-3">
                                <div class="border rounded p-2">
                                    <div class="fw-bold">{{ $total['floor'] }}</div>
                                    <div>{{ $total['count'] }} box{{ $total['count'] == 1 ? '' : 'es' }}</div>
                                    <div>{{ number_format((float) $total['area_sqft'], 2) }} sqft</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Key</th>
                            <th>Matched Category</th>
                            <th>Layer Hint</th>
                            <th>Floor</th>
                            <th>Width ft</th>
                            <th>Height ft</th>
                            <th>Calculated Area sqft</th>
                            <th>Dimension Text</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($roomAreas as $row)
                            <tr>
                                <td><code>{{ $row['key'] ?? '-' }}</code></td>
                                <td>{{ $row['category'] ?? '-' }}</td>
                                <td class="text-muted small">{{ $row['layer_hint'] ?? '-' }}</td>
                                <td>{{ $row['floor'] ?? '-' }}</td>
                                <td>{{ isset($row['width_ft']) ? number_format((float) $row['width_ft'], 2) : '-' }}</td>
                                <td>{{ isset($row['height_ft']) ? number_format((float) $row['height_ft'], 2) : '-' }}</td>
                                <td>{{ isset($row['area_sqft']) ? number_format((float) $row['area_sqft'], 2) : '-' }}</td>
                                <td class="text-muted small">{{ $row['dimension_text'] ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-muted">No room/space dimension pairs were detected from CAD text overlays.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Structural Findings (Geometry-First)</div>
        <div class="card-body">
            <div class="mb-2">
                <strong>Extraction Confidence:</strong> {{ number_format((float) $structuralConfidence * 100, 1) }}%
            </div>
            <div class="mb-3">
                <strong>Detected Structural Entities:</strong>
                @if(!empty($structuralSummary['by_type']))
                    @foreach($structuralSummary['by_type'] as $type => $count)
                        <span class="badge text-bg-secondary me-1">{{ strtoupper(str_replace('_', ' ', (string) $type)) }}: {{ $count }}</span>
                    @endforeach
                @else
                    <span class="text-muted">No structural entities detected yet.</span>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr><th>Type</th><th>Layer</th><th>Confidence</th><th>Reason</th></tr>
                    </thead>
                    <tbody>
                    @forelse(array_slice($structuralEntities, 0, 20) as $item)
                        <tr>
                            <td>{{ strtoupper(str_replace('_', ' ', (string) ($item['semantic_type'] ?? '-'))) }}</td>
                            <td>{{ $item['layer'] ?? '-' }}</td>
                            <td>{{ number_format(((float) ($item['confidence'] ?? 0)) * 100, 1) }}%</td>
                            <td class="text-muted small">{{ $item['reason'] ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">No structural items available.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">Structural Graph Model</div>
        <div class="card-body">
            <div class="mb-2">
                <strong>Nodes:</strong> {{ count($structuralGraphNodes ?? []) }}
                <span class="mx-2">|</span>
                <strong>Edges:</strong> {{ count($structuralGraphEdges ?? []) }}
            </div>
            <div class="mb-3">
                <strong>Relation Types:</strong>
                @if(!empty($structuralGraphRelationCounts))
                    @foreach($structuralGraphRelationCounts as $relation => $count)
                        <span class="badge text-bg-light border me-1">{{ strtoupper(str_replace('_', ' ', (string) $relation)) }}: {{ $count }}</span>
                    @endforeach
                @else
                    <span class="text-muted">No graph relations detected yet.</span>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr><th>From</th><th>To</th><th>Relation</th><th>CAD Distance (ft)</th><th>Raw Distance</th></tr>
                    </thead>
                    <tbody>
                    @forelse(array_slice($structuralGraphEdges ?? [], 0, 30) as $edge)
                        <tr>
                            <td>{{ $edge['from'] ?? '-' }}</td>
                            <td>{{ $edge['to'] ?? '-' }}</td>
                            <td>{{ strtoupper(str_replace('_', ' ', (string) ($edge['relation'] ?? '-'))) }}</td>
                            <td>{{ $edge['distance'] ?? '-' }}{{ ($edge['distance'] ?? null) !== null ? ' ft' : '' }}</td>
                            <td>{{ $edge['raw_distance'] ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">No structural graph edges available.</td></tr>
                    @endforelse
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
                    <tr><th>Rule</th><th>Clause Reference</th><th>Status</th><th>Required</th><th>Actual</th></tr>
                    </thead>
                    <tbody>
                    @foreach(($reportRuleRows ?? []) as $row)
                        <tr>
                            <td>{{ str_replace('_', ' ', (string) ($row['id'] ?? $row['rule_code'] ?? 'N/A')) }}</td>
                            <td>{{ $row['clause_reference'] ?? 'Clause reference pending mapping' }}</td>
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
