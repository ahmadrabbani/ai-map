<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Building Plan AI Report {{ $application->application_number }}</title>
    <style>
        @page { margin: 24px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #1f2933; font-size: 12px; line-height: 1.45; margin: 0; }
        .sheet { max-width: 960px; margin: 0 auto; }
        .header { display: table; width: 100%; border-bottom: 3px solid #286f63; padding-bottom: 14px; margin-bottom: 18px; }
        .header-left { display: table-cell; vertical-align: top; width: 72%; }
        .header-right { display: table-cell; vertical-align: top; width: 28%; text-align: right; }
        h1 { font-size: 24px; margin: 0 0 8px; color: #111827; }
        h2 { font-size: 15px; margin: 20px 0 8px; color: #111827; }
        .badge { display: inline-block; border-radius: 999px; padding: 4px 10px; font-weight: 700; font-size: 11px; background: #e8f4f1; color: #286f63; }
        .muted { color: #637083; }
        .qr { width: 112px; height: 112px; object-fit: contain; border: 1px solid #d7dee8; border-radius: 8px; padding: 4px; }
        .grid { display: table; width: 100%; margin-bottom: 10px; }
        .col { display: table-cell; width: 50%; padding-right: 12px; vertical-align: top; }
        .card { border: 1px solid #d7dee8; border-radius: 10px; padding: 12px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d7dee8; padding: 7px; vertical-align: top; }
        th { background: #f3f6f8; text-align: left; font-size: 11px; }
        .status { font-weight: 700; text-transform: uppercase; }
        .pass { color: #0f7a4f; }
        .fail { color: #b42318; }
        .needs_review { color: #a16207; }
        .reference { color: #475569; }
        .notice { background: #fff8e6; border: 1px solid #f2d38a; padding: 10px; border-radius: 8px; margin-top: 12px; }
        .footer { margin-top: 20px; border-top: 1px solid #d7dee8; padding-top: 10px; font-size: 10px; color: #637083; }
        .no-print { margin: 12px 0; }
        @media print { .no-print { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
@if(!empty($autoPrint))
    <div class="no-print" style="text-align:right">
        <button onclick="window.print()" style="padding:10px 16px;border-radius:8px;border:1px solid #286f63;background:#286f63;color:#fff;font-weight:700">Save / Print PDF</button>
    </div>
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
@endif
<div class="sheet">
    @php
        $cadConfidence = is_array(data_get($report, 'analysis_json.cad_confidence_assessment'))
            ? data_get($report, 'analysis_json.cad_confidence_assessment')
            : [];
        $dxfPatternProfile = is_array(data_get($report, 'analysis_json.dxf_pattern_profile'))
            ? data_get($report, 'analysis_json.dxf_pattern_profile')
            : [];
    @endphp
    <div class="header">
        <div class="header-left">
            <div class="badge">{{ $textualRecommendation }}</div>
            <h1>Building Plan AI Scrutiny Report</h1>
            <div><strong>Application No:</strong> {{ $application->application_number }}</div>
            <div><strong>File:</strong> {{ $application->uploaded_file_name }}</div>
            <div><strong>Status:</strong> {{ $application->status }}</div>
            <div><strong>Verification URL:</strong> {{ $application->verification_url }}</div>
        </div>
        <div class="header-right">
            @if($application->qr_code_url)
                <img class="qr" src="{{ $application->qr_code_url }}" alt="QR Code">
                <div class="muted">Scan to verify report</div>
            @endif
        </div>
    </div>

    <div class="grid">
        <div class="col">
            <div class="card">
                <h2>Applicant</h2>
                <div><strong>Name:</strong> {{ $application->applicant_name ?: data_get($application->applicant_data_json, 'name', '-') }}</div>
                <div><strong>Email:</strong> {{ $application->applicant_email ?: data_get($application->applicant_data_json, 'email', '-') }}</div>
                <div><strong>Phone:</strong> {{ $application->applicant_phone ?: data_get($application->applicant_data_json, 'phone', '-') }}</div>
            </div>
        </div>
        <div class="col">
            <div class="card">
                <h2>AI Summary</h2>
                <div><strong>AI Status:</strong> {{ $report?->analysis_status ?: '-' }}</div>
                <div><strong>AI Recommendation:</strong> {{ $report?->ai_recommendation ?: '-' }}</div>
                <div><strong>AI Confidence:</strong> {{ $report?->ai_confidence_score ?? 0 }}%</div>
                <div><strong>CAD Confidence:</strong> {{ number_format((float) data_get($cadConfidence, 'confidence_score', 0), 2) }}% ({{ strtoupper((string) data_get($cadConfidence, 'confidence_level', 'unknown')) }})</div>
                <div><strong>DXF Pattern:</strong> {{ data_get($dxfPatternProfile, 'pattern_family', 'generic_dxf') }} ({{ number_format((float) data_get($dxfPatternProfile, 'pattern_strength', 0), 2) }})</div>
                <div><strong>Dimension Source:</strong> {{ data_get($cadConfidence, 'dimension_source', 'unknown') }}</div>
                <div><strong>Fallback Method:</strong> {{ data_get($cadConfidence, 'fallback_method_used', 'unknown') }}</div>
                <div><strong>Missing Layers:</strong> {{ implode(', ', (array) data_get($cadConfidence, 'missing_layers', [])) ?: '-' }}</div>
                @if(!empty(data_get($cadConfidence, 'warnings', [])))
                    <div><strong>Warnings:</strong></div>
                    <ul style="margin-top:4px; margin-bottom:0;">
                        @foreach((array) data_get($cadConfidence, 'warnings', []) as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif
                <div><strong>Textual Recommendation:</strong> {{ $textualRecommendation }}</div>
            </div>
        </div>
    </div>

    @php
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
        $aiDecision = $normalizeDecision((string) ($report?->ai_recommendation ?: $textualRecommendation));
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

    <div class="card">
        <h2>AI vs AD ePermit Recommendation</h2>
        <table>
            <thead><tr><th>AI Recommendation</th><th>AD Recommendation</th><th>Comparison</th><th>Confidence</th></tr></thead>
            <tbody>
            <tr>
                <td>{{ $report?->ai_recommendation ?: $textualRecommendation }}</td>
                <td>{{ $adDecisionRaw !== '' ? ucfirst(str_replace('_', ' ', $adDecisionRaw)) : 'AD comments not submitted yet' }}</td>
                <td>{{ $comparisonNote }}</td>
                <td>{{ number_format((float) data_get($cadConfidence, 'confidence_score', 0), 2) }}% / {{ strtoupper((string) data_get($cadConfidence, 'confidence_level', 'unknown')) }}</td>
            </tr>
            </tbody>
        </table>
        <div style="margin-top:8px;"><strong>AD Comments:</strong> {{ $adRemarks !== '' ? $adRemarks : 'AD comments not submitted yet' }}</div>
        @if($application->reviewed_at)
            <div style="margin-top:4px;"><strong>Decision Date/Time:</strong> {{ $application->reviewed_at->format('Y-m-d H:i') }}</div>
        @endif
    </div>

    <h2>Officer-Verified Object Identification</h2>
    <div class="card">
        <div class="muted">Latest AD ePermit layer markings captured as expert training labels. Model retraining is a separate governed step.</div>
        <table>
            <thead><tr><th>CAD Layer</th><th>Identified Object</th><th>Verification</th><th>Training Capture</th></tr></thead>
            <tbody>
            @forelse((array) data_get($layerIdentificationReport, 'objects', []) as $object)
                <tr>
                    <td>{{ $object['cad_layer'] ?? '-' }}</td>
                    <td>{{ $object['object_name'] ?? $object['object_key'] ?? '-' }}</td>
                    <td>{{ str_replace('_', ' ', $object['verification_status'] ?? 'officer_verified') }}</td>
                    <td>{{ str_replace('_', ' ', $object['training_status'] ?? 'captured_as_expert_label') }}</td>
                </tr>
            @empty
                <tr><td colspan="4">No officer-verified layer identifications have been saved yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h2>Textual Data vs AI/CAD Read Values</h2>
    @if(!empty($textualFindings))
        <div class="card">
            @foreach($textualFindings as $finding)
                <div>{{ $finding }}</div>
            @endforeach
        </div>
    @endif
    <table>
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
            <tr>
                <td>{{ $row['label'] }}</td>
                <td>{{ ($row['textual_value'] === null || $row['textual_value'] === '') ? '-' : $row['textual_value'] }}</td>
                <td>{{ ($row['ai_value'] === null || $row['ai_value'] === '') ? '-' : $row['ai_value'] }}</td>
                <td>{{ $row['operator'] && $row['required'] !== null ? $row['operator'].' '.$row['required'] : ($row['required'] ?? '-') }}</td>
                <td class="status {{ $row['status'] }}">{{ strtoupper(str_replace('_', ' ', $row['status'] === 'pass' ? 'clear' : $row['status'])) }}</td>
                <td>{{ $row['basis'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Further Details: Room / Space Areas From CAD Text</h2>
    <div class="card">
        <div class="muted">
            Parsed from CAD text overlays. The matched category can come from the visible text or the CAD layer hint.
            Area is calculated as width x height from nearby dimension text.
            Keys use floor prefixes: BF, GF, FF, SF, TH.
        </div>
        @if(!empty($roomAreaTotals))
            <table>
                <thead><tr><th>Floor</th><th>Detected Boxes</th><th>Total Area</th></tr></thead>
                <tbody>
                @foreach($roomAreaTotals as $total)
                    <tr>
                        <td>{{ $total['floor'] }}</td>
                        <td>{{ $total['count'] }}</td>
                        <td>{{ number_format((float) $total['area_sqft'], 2) }} sqft</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
        <table>
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
                    <td>{{ $row['key'] ?? '-' }}</td>
                    <td>{{ $row['category'] ?? '-' }}</td>
                    <td>{{ $row['layer_hint'] ?? '-' }}</td>
                    <td>{{ $row['floor'] ?? '-' }}</td>
                    <td>{{ isset($row['width_ft']) ? number_format((float) $row['width_ft'], 2) : '-' }}</td>
                    <td>{{ isset($row['height_ft']) ? number_format((float) $row['height_ft'], 2) : '-' }}</td>
                    <td>{{ isset($row['area_sqft']) ? number_format((float) $row['area_sqft'], 2) : '-' }}</td>
                    <td>{{ $row['dimension_text'] ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="8">No room/space dimension pairs were detected from CAD text overlays.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h2>Structural Findings (Geometry-First)</h2>
    <div class="card">
        <div><strong>Extraction Confidence:</strong> {{ number_format((float) $structuralConfidence * 100, 1) }}%</div>
        @if(!empty($structuralSummary['by_type']))
            <div style="margin-top:6px;">
                <strong>Detected Types:</strong>
                @foreach($structuralSummary['by_type'] as $type => $count)
                    <span style="margin-right:8px;">{{ strtoupper(str_replace('_', ' ', (string) $type)) }}: {{ $count }}</span>
                @endforeach
            </div>
        @endif
        <table>
            <thead><tr><th>Type</th><th>Layer</th><th>Confidence</th><th>Reason</th></tr></thead>
            <tbody>
            @forelse(array_slice($structuralEntities, 0, 20) as $item)
                <tr>
                    <td>{{ strtoupper(str_replace('_', ' ', (string) ($item['semantic_type'] ?? '-'))) }}</td>
                    <td>{{ $item['layer'] ?? '-' }}</td>
                    <td>{{ number_format(((float) ($item['confidence'] ?? 0)) * 100, 1) }}%</td>
                    <td>{{ $item['reason'] ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="4">No structural items available.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h2>Structural Graph Model</h2>
    <div class="card">
        <div><strong>Nodes:</strong> {{ count($structuralGraphNodes ?? []) }}</div>
        <div><strong>Edges:</strong> {{ count($structuralGraphEdges ?? []) }}</div>
        @if(!empty($structuralGraphRelationCounts))
            <div style="margin-top:6px;">
                <strong>Relation Types:</strong>
                @foreach($structuralGraphRelationCounts as $relation => $count)
                    <span style="margin-right:8px;">{{ strtoupper(str_replace('_', ' ', (string) $relation)) }}: {{ $count }}</span>
                @endforeach
            </div>
        @endif
        <table>
            <thead><tr><th>From</th><th>To</th><th>Relation</th><th>CAD Distance (ft)</th><th>Raw Distance</th></tr></thead>
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
                <tr><td colspan="5">No structural graph edges available.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h2>Rule-wise AI/CAD Compliance Results</h2>
    <table>
        <thead><tr><th>Rule</th><th>Clause Reference</th><th>Status</th><th>Required</th><th>AI/CAD Actual</th></tr></thead>
        <tbody>
        @forelse(($reportRuleRows ?? []) as $row)
            <tr>
                <td>{{ str_replace('_', ' ', (string) ($row['id'] ?? $row['rule_code'] ?? 'N/A')) }}</td>
                <td>{{ $row['clause_reference'] ?? 'Clause reference pending mapping' }}</td>
                <td>{{ $row['status'] ?? (($row['pass'] ?? null) === true ? 'pass' : (($row['pass'] ?? null) === false ? 'fail' : 'needs_review')) }}</td>
                <td>{{ $row['required'] ?? $row['required_value'] ?? '-' }}</td>
                <td>{{ $row['measured'] ?? $row['actual'] ?? $row['actual_value'] ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="5">No AI/CAD rule rows available.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="notice"><strong>Disclaimer:</strong> {{ $disclaimer }}</div>
    <div class="footer">Generated by Map AI Verification. Final approval, rejection, or correction remains with the competent authority.</div>
</div>
</body>
</html>
