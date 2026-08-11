@extends('public.building-plan.layout')
@section('title', 'AI Scrutiny Report')
@section('content')
@php
    $aliasCount = \App\Models\LayerAlias::query()->where('is_active', true)->count();
    $aliasTop = \App\Models\LayerAlias::query()
        ->where('is_active', true)
        ->orderByDesc('hit_count')
        ->limit(5)
        ->get();
    $report = is_array($application->ai_report_json) ? $application->ai_report_json : [];
    $analysis = is_array(data_get($report, 'analysis')) ? data_get($report, 'analysis') : [];
    $reportData = is_array(data_get($report, 'report_data')) ? data_get($report, 'report_data') : [];
    $rules = collect(data_get($reportData, 'rule_wise_compliance_results', []))->values();
    $measurementMetrics = [];
    $measurementPaths = [
        'analysis_json.map_report.cad_text_measurement_metrics',
        'analysis_json.cad_text_measurement_metrics',
        'analysis_result.cad_text_measurement_metrics',
        'map_report.cad_text_measurement_metrics',
        'cad_text_measurement_metrics',
    ];
    foreach ($measurementPaths as $mPath) {
        $candidate = data_get($analysis, $mPath);
        if (is_array($candidate) && !empty($candidate)) {
            $measurementMetrics = $candidate;
            break;
        }
    }
    if (empty($measurementMetrics)) {
        $candidateFromReport = data_get($reportData, 'cad_text_measurement_metrics');
        if (is_array($candidateFromReport)) {
            $measurementMetrics = $candidateFromReport;
        }
    }

    $statusNorm = fn($v) => strtolower(trim((string) $v));
    $passCountRules = $rules->filter(fn($r) => in_array($statusNorm(data_get($r,'status')), ['pass','passed'], true))->count();
    $failCount = $rules->filter(fn($r) => in_array($statusNorm(data_get($r,'status')), ['fail','failed'], true))->count();
    $reviewCountRules = $rules->filter(fn($r) => in_array($statusNorm(data_get($r,'status')), ['needs_review','review','warn'], true))->count();

    $confidence = data_get($analysis, 'confidence_score');
    if ($confidence === null) $confidence = data_get($analysis, 'analysis.confidence_score');
    if ($confidence === null) $confidence = data_get($reportData, 'ai_confidence_score');
    $confidence = is_numeric($confidence) ? round((float) $confidence, 2) : null;
    $cadConfidence = is_array(data_get($analysis, 'cad_confidence_assessment')) ? data_get($analysis, 'cad_confidence_assessment') : (is_array(data_get($reportData, 'cad_confidence_assessment')) ? data_get($reportData, 'cad_confidence_assessment') : []);
    $dxfPatternProfile = is_array(data_get($analysis, 'dxf_pattern_profile')) ? data_get($analysis, 'dxf_pattern_profile') : (is_array(data_get($reportData, 'dxf_pattern_profile')) ? data_get($reportData, 'dxf_pattern_profile') : []);

    $recommendation = (string) (data_get($analysis, 'recommendation') ?? $application->ai_status ?? 'Needs Expert Review');
    $warnings = collect(data_get($reportData, 'warnings', []))->filter()->values();
    $disclaimer = (string) (data_get($report, 'disclaimer') ?: 'This AI-based scrutiny report is generated to assist preliminary validation of building plan submissions. Final approval, rejection, or objection shall remain subject to review and decision by the concerned authority/directorate.');

    $detectedLayers = collect(data_get($reportData, 'detected_cad_layers_entities.layers', []));
    $detectedEntities = collect(data_get($reportData, 'detected_cad_layers_entities.entities', []));

    $layerText = strtolower(json_encode($detectedLayers->all()) . ' ' . json_encode($detectedEntities->all()));
    $has = function (array $needles) use ($layerText): bool {
        foreach ($needles as $n) {
            if (str_contains($layerText, strtolower($n))) return true;
        }
        return false;
    };

    $textLayerChecks = collect([
        ['title' => 'Applicant Information Text Layer', 'ok' => $has(['applicant information','applicant','owner information'])],
        ['title' => 'Plot Information Text Layer', 'ok' => $has(['plot information','plot no','scheme','block','phase'])],
        ['title' => 'Measurements Text Layer', 'ok' => $has(['measurement information','measurements','plot area','ground floor covered'])],
        ['title' => 'Submission Information Text Layer', 'ok' => $has(['submission information','application id','submission details'])],
    ]);

    $textClear = $textLayerChecks->where('ok', true)->count();
    $textReview = $textLayerChecks->where('ok', false)->count();

    $comparisonRowsCollection = collect($comparisonRows ?? [])->values();
    $textPassCount = $comparisonRowsCollection->where('status', 'pass')->count();
    $textFailCount = $comparisonRowsCollection->where('status', 'fail')->count();
    $textReviewCount = $comparisonRowsCollection->where('status', 'needs_review')->count();
    $textReferenceCount = $comparisonRowsCollection->where('status', 'reference')->count();

    $ruleToTextMetric = [
        'SETBACK_FRONT' => 'front_setback_ft',
        'SETBACK_REAR' => 'rear_setback_ft',
        'SETBACK_SIDE' => 'left_setback_ft',
        'GROUND_COVERAGE' => 'coverage_percent',
        'FAR_LIMIT' => 'far',
        'MAX_STOREYS' => 'number_of_floors',
        'MAX_HEIGHT' => 'provided_height_ft',
        'PORCH_LENGTH' => 'porch_length_ft',
        'REAR_TOILET_AREA' => 'rear_toilet_area_sqft',
    ];
    $adDecisionRaw = (string) ($application->ad_epermit_decision ?? '');
    $adRemarks = trim((string) ($application->ad_epermit_remarks ?? ''));
    $normalizeDecision = static function (string $value): string {
        $value = strtolower(trim($value));
        return match (true) {
            in_array($value, ['approve', 'approved', 'pass', 'passed', 'approval'], true) => 'approve',
            in_array($value, ['reject', 'rejected', 'fail', 'failed', 'objection'], true) => 'reject',
            in_array($value, ['observation', 'needs review', 'needs expert review', 'needs_expert_review', 'review'], true) => 'observation',
            default => $value,
        };
    };
    $aiDecision = $normalizeDecision($recommendation);
    $adDecision = $normalizeDecision($adDecisionRaw);
    $comparisonNote = 'AI and AD comparison not available yet.';
    if ($aiDecision !== '' && $adDecision !== '') {
        $comparisonNote = $aiDecision === $adDecision
            ? 'AI and AD both agree.'
            : ($aiDecision === 'approve' && $adDecision === 'observation'
                ? 'AI recommends approval but AD marked observation.'
                : ($aiDecision === 'reject' && $adDecision === 'approve'
                    ? 'AI found a violation but AD recommended approval.'
                    : 'AI and AD decisions differ.'));
    }
@endphp

<div class="card mb-3">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>AI Scrutiny Report - {{ $application->application_no ?: 'Draft-' . $application->id }}</span>
        <a class="btn btn-sm btn-outline-dark" href="{{ route('public.bp.applications.download-report', $application->id) }}">Download JSON</a>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4"><div class="border rounded p-2 h-100"><strong>Current Status</strong><div>{{ $application->status }}</div></div></div>
            <div class="col-md-4"><div class="border rounded p-2 h-100"><strong>AI Recommendation</strong><div>{{ $recommendation }}</div></div></div>
            <div class="col-md-4"><div class="border rounded p-2 h-100"><strong>AI Confidence</strong><div>{{ $confidence !== null ? number_format($confidence,2).'%' : 'N/A' }}</div></div></div>
            <div class="col-md-4"><div class="border rounded p-2 h-100"><strong>DXF Pattern</strong><div>{{ data_get($dxfPatternProfile, 'pattern_family', 'generic_dxf') }}</div><div class="text-muted small">Strength: {{ number_format((float) data_get($dxfPatternProfile, 'pattern_strength', 0), 2) }}</div></div></div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">CAD Data Quality / Confidence</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><div class="border rounded p-2 h-100"><strong>Level</strong><div>{{ strtoupper((string) data_get($cadConfidence, 'confidence_level', 'unknown')) }}</div></div></div>
                    <div class="col-md-3"><div class="border rounded p-2 h-100"><strong>Score</strong><div>{{ number_format((float) data_get($cadConfidence, 'confidence_score', 0), 2) }}%</div></div></div>
                    <div class="col-md-3"><div class="border rounded p-2 h-100"><strong>Dimension Source</strong><div>{{ data_get($cadConfidence, 'dimension_source', 'unknown') }}</div></div></div>
                    <div class="col-md-3"><div class="border rounded p-2 h-100"><strong>Fallback</strong><div>{{ data_get($cadConfidence, 'fallback_method_used', 'unknown') }}</div></div></div>
                    <div class="col-12"><strong>Missing Layers:</strong> {{ implode(', ', (array) data_get($cadConfidence, 'missing_layers', [])) ?: '-' }}</div>
                    <div class="col-12"><strong>Available Layers:</strong> {{ implode(', ', (array) data_get($cadConfidence, 'available_layers', [])) ?: '-' }}</div>
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
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">AI vs AD ePermit Recommendation</div>
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                        <strong>AI Recommendation</strong>
                        <div class="mt-1">{{ $recommendation }}</div>
                        <div class="small text-secondary mt-2">{{ $comparisonNote }}</div>
                        @if($aiDecision === 'approve' || $aiDecision === 'reject' || $aiDecision === 'observation')
                            <div class="small text-secondary">AI normalized decision: {{ ucfirst($aiDecision) }}</div>
                        @endif
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border rounded p-3 h-100">
                        <strong>AD ePermit Recommendation</strong>
                        <div class="mt-1">{{ $adDecisionRaw !== '' ? ucfirst(str_replace('_', ' ', $adDecisionRaw)) : 'AD comments not submitted yet' }}</div>
                        <div class="small text-secondary mt-2">{{ $adRemarks !== '' ? $adRemarks : 'AD comments not submitted yet' }}</div>
                        @if($application->reviewed_at)
                            <div class="small text-secondary">Decision date/time: {{ $application->reviewed_at->format('Y-m-d H:i') }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Quick Summary (Rules + Text Layers)</div>
            <div class="card-body row g-3">
                <div class="col-md-4"><span class="badge bg-success">Rule Clear: {{ $passCountRules }}</span></div>
                <div class="col-md-4"><span class="badge bg-danger">Rule Issues: {{ $failCount }}</span></div>
                <div class="col-md-4"><span class="badge bg-warning text-dark">Rule Under Review: {{ $reviewCountRules }}</span></div>
                <div class="col-md-4"><span class="badge bg-success">Text Clear: {{ $textPassCount }}</span></div>
                <div class="col-md-4"><span class="badge bg-danger">Text Issues: {{ $textFailCount }}</span></div>
                <div class="col-md-4"><span class="badge bg-warning text-dark">Text Under Review: {{ $textReviewCount }}</span></div>
                <div class="col-12 text-secondary small">
                    Text counts come from the “Textual Data vs AI/CAD Read Values” table below.
                    @if($textReferenceCount > 0)
                        Reference-only text rows: {{ $textReferenceCount }}.
                    @endif
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Text Layer Checks</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="min-width:260px">Text Layer Check</th>
                            <th style="min-width:150px">Result</th>
                            <th>Note</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($textLayerChecks as $chk)
                            <tr>
                                <td>{{ $chk['title'] }}</td>
                                <td>{{ $chk['ok'] ? 'Clear' : 'Under Review' }}</td>
                                <td>{{ $chk['ok'] ? 'Relevant text evidence detected in extracted CAD layers/entities.' : 'Text evidence not confidently detected; manual review recommended.' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Learning Progress</div>
            <div class="card-body">
                <div class="mb-2"><strong>Active Learned Aliases:</strong> {{ $aliasCount }}</div>
                @if($aliasTop->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Alias Layer Name</th>
                                <th>Mapped To</th>
                                <th>Label</th>
                                <th>Hits</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($aliasTop as $a)
                                <tr>
                                    <td>{{ $a->alias_name }}</td>
                                    <td>{{ $a->official_layer_name }}</td>
                                    <td>{{ $a->semantic_label ?: '-' }}</td>
                                    <td>{{ $a->hit_count }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-muted small">No learned aliases yet. Once experts confirm layer mappings, the system will learn and this section will populate.</div>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Extracted Text/OCR Measurements</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:220px">Measurement</th>
                                <th style="min-width:180px">Extracted Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($measurementMetrics as $metricKey => $metricValue)
                            @if(is_scalar($metricValue) && $metricValue !== '')
                            <tr>
                                <td>{{ ucwords(str_replace('_', ' ', (string) $metricKey)) }}</td>
                                <td>{{ is_numeric($metricValue) ? rtrim(rtrim(number_format((float) $metricValue, 4, '.', ''), '0'), '.') : (string) $metricValue }}</td>
                            </tr>
                            @endif
                        @empty
                            <tr><td colspan="2" class="text-center text-muted py-3">No OCR/text-layer measurement values found in this run.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Text-Driven Room / Space Areas</div>
            <div class="card-body">
                <div class="text-secondary small mb-3">
                    Each row shows the matched category, the CAD layer hint that helped identify it, the nearby dimension text, and the calculated area.
                </div>
                @if(!empty($roomAreaTotals ?? []))
                    <div class="row g-2 mb-3">
                        @foreach($roomAreaTotals as $total)
                            <div class="col-md-3">
                                <div class="border rounded p-2 bg-light">
                                    <div class="fw-bold">{{ $total['floor'] }}</div>
                                    <div>{{ $total['count'] }} item{{ $total['count'] === 1 ? '' : 's' }}</div>
                                    <div>{{ number_format((float) $total['area_sqft'], 2) }} sqft</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                @php
                    $roomAreaRows = $roomAreas ?? [];
                @endphp
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
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
                        @forelse($roomAreaRows as $row)
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
                                <td colspan="8" class="text-muted py-3">No room/space dimension pairs were detected from CAD text overlays.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if(!empty($comparisonRows ?? []))
        <div class="card mb-3">
            <div class="card-header fw-semibold">Textual Data vs AI/CAD Read Values</div>
            <div class="card-body">
                <div class="alert alert-info mb-3">
                    This section uses architect-provided text table layers first. Pass/fail is based on extracted textual values, with AI/CAD values shown for verification.
                </div>
                @if(!empty($textualFindings ?? []))
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
        @endif

        <div class="card mb-3">
            <div class="card-header fw-semibold">Rule Check Details</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:180px">Rule</th>
                                <th style="min-width:120px">Status</th>
                                <th style="min-width:140px">Required</th>
                                <th style="min-width:160px">System Calculated</th>
                                <th style="min-width:140px">Text/OCR Read</th>
                                <th>Observation</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($rules as $row)
                            @php
                                $rule = (string) (data_get($row,'rule_code') ?: data_get($row,'id') ?: 'Rule');
                                $status = (string) (data_get($row,'status') ?: 'needs_review');
                                $requiredVal = data_get($row, 'required_value', data_get($row, 'required'));
                                $actualVal = data_get($row, 'actual_value', data_get($row, 'actual', data_get($row, 'measured')));
                                $unitVal = (string) (data_get($row, 'unit') ?: '');
                                $textMetricKey = $ruleToTextMetric[$rule] ?? null;
                                $textMetricVal = $textMetricKey ? data_get($measurementMetrics, $textMetricKey) : null;
                                $message = (string) (data_get($row,'message') ?: 'Manual review required.');
                                $measurementWarning = 'Measurement verification required. The system calculated a draft value, but it was based on reconstructed or approximate CAD geometry and must be confirmed before pass/fail.';
                                if ($message === $measurementWarning) {
                                    $message = 'System calculated value is available; manual confirmation still required. Use Text/OCR value and source entities for final decision.';
                                }
                            @endphp
                            <tr>
                                <td>{{ $rule }}</td>
                                <td>{{ ucfirst(str_replace('_',' ',$status)) }}</td>
                                <td>{{ $requiredVal !== null && $requiredVal !== '' ? $requiredVal . ($unitVal !== '' ? ' ' . $unitVal : '') : '-' }}</td>
                                <td>{{ $actualVal !== null && $actualVal !== '' ? $actualVal . ($unitVal !== '' ? ' ' . $unitVal : '') : '-' }}</td>
                                <td>{{ $textMetricVal !== null && $textMetricVal !== '' ? $textMetricVal : '-' }}</td>
                                <td>{{ $message }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">No detailed rule checks available yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if($warnings->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header fw-semibold">Warnings / Items Requiring Attention</div>
            <div class="card-body">
                <ul class="mb-0">
                    @foreach($warnings as $w)
                        <li>{{ is_scalar($w) ? $w : json_encode($w) }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        @endif

        <div class="alert alert-warning mb-0">{{ $disclaimer }}</div>
    </div>
</div>
@endsection
