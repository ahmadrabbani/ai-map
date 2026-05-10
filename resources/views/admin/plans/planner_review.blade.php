<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Planner Review Desk</title>
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=space-grotesk:500,600,700|manrope:400,500,600,700" rel="stylesheet" />
  <style>
    :root { --bg:#f7f2ea; --ink:#101418; --muted:#5a6470; --line:rgba(16,20,24,.12); --green:#0f6b5f; --amber:#946200; --red:#a91f1f; --blue:#2356a3; }
    * { box-sizing:border-box; }
    body { margin:0; font-family:Manrope, sans-serif; color:var(--ink); background:radial-gradient(circle at 15% 20%, #f8f1dd, transparent 50%), radial-gradient(circle at 85% 15%, #d4efe7, transparent 52%), var(--bg); }
    a { color:inherit; text-decoration:none; }
    .shell { max-width:1280px; margin:0 auto; padding:28px; }
    .topbar { display:flex; justify-content:space-between; align-items:center; gap:20px; padding:12px 18px; border:1px solid var(--line); border-radius:24px; background:rgba(255,255,255,.72); backdrop-filter:blur(12px); }
    .brand { display:flex; align-items:center; gap:12px; font-family:"Space Grotesk", sans-serif; font-weight:700; }
    .mark { width:40px; height:40px; display:grid; place-items:center; border-radius:14px; color:white; background:linear-gradient(135deg, var(--green), #133f39); }
    .nav { display:flex; flex-wrap:wrap; gap:14px; color:var(--muted); font-size:14px; }
    .btn { display:inline-flex; align-items:center; justify-content:center; border:1px solid var(--line); border-radius:999px; padding:10px 14px; background:white; font-weight:700; }
    .btn.primary { background:var(--green); color:white; border-color:var(--green); }
    .hero { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:20px; margin:36px 0 18px; align-items:stretch; }
    h1,h2,h3 { font-family:"Space Grotesk", sans-serif; margin:0; }
    h1 { font-size:42px; line-height:1.05; }
    h2 { font-size:20px; margin-bottom:12px; }
    h3 { font-size:16px; margin-bottom:8px; }
    .muted { color:var(--muted); }
    .card { background:white; border:1px solid var(--line); border-radius:20px; padding:18px; box-shadow:0 18px 38px rgba(16,20,24,.08); }
    .grid { display:grid; gap:16px; }
    .grid.two { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    .grid.three { grid-template-columns:repeat(3, minmax(0, 1fr)); }
    .status { display:inline-flex; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:800; letter-spacing:.02em; }
    .pass,.ready_for_submission { background:#e9f7f2; color:var(--green); }
    .fail,.needs_correction { background:#ffe9e9; color:var(--red); }
    .needs_review,.needs_expert_review { background:#fff6e6; color:var(--amber); }
    .warn { background:#eef3ff; color:var(--blue); }
    .metric { border:1px solid var(--line); border-radius:16px; padding:12px; background:#fbfaf8; }
    .metric b { display:block; font-size:22px; margin-top:4px; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th,td { text-align:left; padding:10px 8px; border-bottom:1px solid #eee; vertical-align:top; }
    th { color:var(--muted); font-weight:800; background:#fbfaf8; }
    .flow { display:grid; gap:8px; }
    .flow-row { display:flex; gap:10px; align-items:center; padding:8px; border-radius:12px; background:#fbfaf8; }
    .dot { width:10px; height:10px; border-radius:999px; background:var(--green); flex:0 0 auto; }
    .dot.review { background:var(--amber); }
    .chat { display:grid; gap:10px; }
    .chat-box { min-height:150px; border:1px solid var(--line); border-radius:16px; padding:12px; background:#fbfaf8; }
    .input-row { display:flex; gap:8px; }
    input { flex:1; border:1px solid var(--line); border-radius:999px; padding:11px 14px; font:inherit; }
    textarea { width:100%; border:1px solid var(--line); border-radius:16px; padding:12px 14px; font:inherit; resize:vertical; }
    label { display:grid; gap:6px; font-size:13px; font-weight:800; }
    label span { color:var(--muted); font-weight:700; }
    @media (max-width: 980px) { .hero,.grid.two,.grid.three { grid-template-columns:1fr; } .shell { padding:18px; } }
  </style>
</head>
<body>
@php
  $rules = collect($report['rules'] ?? []);
  $geometry = $report['geometry'] ?? [];
  $status = $report['status'] ?? 'needs_expert_review';
  $needsReview = $rules->where('status', 'needs_review')->count();
  $failed = $rules->where('status', 'fail')->count();
  $passed = $rules->where('status', 'pass')->count();
  $measurementsConfirmed = (bool) data_get(optional($mapDrawing)->metadata_json, 'expert_measurements_confirmed', false);
  $measurementOverrides = data_get(optional($mapDrawing)->metadata_json, 'measurement_overrides', []);
  $systemMeasurementPrefill = [
      'plot_area_sqft' => data_get($geometry, 'plot_area_sqft'),
      'ground_floor_area_sqft' => data_get($geometry, 'ground_floor_area_sqft'),
      'first_floor_area_sqft' => data_get($geometry, 'first_floor_area_sqft'),
      'second_floor_area_sqft' => data_get($geometry, 'second_floor_area_sqft'),
      'ground_coverage_percent' => data_get($geometry, 'ground_coverage_percent'),
      'far' => data_get($geometry, 'far'),
      'front_setback_ft' => data_get($geometry, 'front_setback_ft'),
      'rear_setback_ft' => data_get($geometry, 'rear_setback_ft'),
      'left_setback_ft' => data_get($geometry, 'left_setback_ft'),
      'right_setback_ft' => data_get($geometry, 'right_setback_ft'),
      'porch_length_ft' => data_get($geometry, 'porch_length_ft'),
      'storey_count' => data_get($geometry, 'storey_count'),
  ];
  // Prefill priority: saved planner overrides > current system-computed values.
  $prefillMeasurements = array_merge($systemMeasurementPrefill, (array) $measurementOverrides);
  $measurementVerificationRequired = (($geometry['measurement_verification'] ?? null) === 'required');
  $decisionBadge = match($plannerDecision ?? null) {
      'approved' => 'pass',
      'revision_required' => 'needs_review',
      default => 'warn',
  };
  $precheck = [
    ['label' => 'DWG uploaded', 'ok' => (bool) $submission->stored_dwg_path],
    ['label' => 'DXF available', 'ok' => (bool) $submission->stored_dxf_path],
    ['label' => 'Semantic drawing record', 'ok' => (bool) $mapDrawing],
    ['label' => 'Mapped plot + floor entities', 'ok' => empty($report['missing_required_entities'] ?? ['missing'])],
    ['label' => 'Measurement verification', 'ok' => ! $measurementVerificationRequired],
  ];
@endphp
<div class="shell">
  <header class="topbar">
    <a class="brand" href="/">
      <span class="mark">M</span>
      <span>Map AI Verification</span>
    </a>
    <nav class="nav">
      <a href="{{ route('admin.plan.cad-compliance.form') }}">Compliance Hub</a>
      <a href="{{ route('admin.plan.cad-expert-label.edit', $submission->id) }}">Expert Labels</a>
      <a href="{{ route('admin.plan.cad-layer-viewer', ['id' => $submission->id, 'map_drawing_id' => optional($mapDrawing)->id]) }}">Layer Viewer</a>
    </nav>
      <a class="btn primary" href="{{ route('admin.plan.cad-compliance.form') }}">New submission</a>
  </header>

  @if(session('status'))
    <div class="card" style="margin-top:18px; border-color:rgba(15,107,95,.28);">
      <b>{{ session('status') }}</b>
    </div>
  @endif

  <section class="hero">
    <div class="card">
      <div class="status {{ $status }}">{{ str_replace('_', ' ', strtoupper($status)) }}</div>
      <h1 style="margin-top:14px;">Town Planner Review Desk</h1>
      <p class="muted" style="max-width:760px;">Use this screen for internal quick review. It separates trusted mapped data, draft measurements, rules needing expert confirmation, and trainable records. The raw CAD viewer is only for debugging or manual marking.</p>
      <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
        <a class="btn primary" href="{{ route('admin.plan.cad-layer-viewer', ['id' => $submission->id, 'map_drawing_id' => optional($mapDrawing)->id]) }}">Open visual marking</a>
        <a class="btn" href="{{ route('admin.plan.cad-expert-label.edit', $submission->id) }}">Expert correction form</a>
      </div>
      <p class="muted" style="margin-top:10px;">Note: CAD text overlays are rendered in <b>Open visual marking</b> viewer, not on this Planner Review summary page.</p>
    </div>
    <div class="card">
      <h2>Submission</h2>
      <div class="muted">ID</div>
      <b>#{{ $submission->id }}</b>
      <div class="muted" style="margin-top:10px;">File</div>
      <b>{{ $submission->original_filename }}</b>
      <div class="muted" style="margin-top:10px;">Drawing</div>
      <b>{{ optional($mapDrawing)->id ? 'Map drawing #'.optional($mapDrawing)->id : 'Not created yet' }}</b>
      <div class="muted" style="margin-top:10px;">Planner decision</div>
      <b>
        @if($plannerDecision === 'approved')
          Approved
        @elseif($plannerDecision === 'revision_required')
          Revision required
        @else
          Pending decision
        @endif
      </b>
    </div>
  </section>

  @if($mapDrawing)
    <section class="card" style="margin-bottom:16px; border-color:rgba(35,86,163,.35);">
      <h2>Final Planner Decision</h2>
      <p class="muted">This is the explicit approval gate for the mapped drawing after review.</p>
      <div style="margin:10px 0;">
        <span class="status {{ $decisionBadge }}">
          {{ $plannerDecision ? str_replace('_', ' ', strtoupper($plannerDecision)) : 'PENDING' }}
        </span>
        @if($plannerDecisionAt)
          <span class="muted" style="margin-left:8px;">at {{ $plannerDecisionAt }}</span>
        @endif
      </div>
      @if(!empty($plannerDecisionNote))
        <div class="muted" style="margin-bottom:10px;"><b>Last note:</b> {{ $plannerDecisionNote }}</div>
      @endif
      <form method="POST" action="{{ route('admin.plan.cad-planner-review.decision', $submission->id) }}" class="grid" style="gap:10px;">
        @csrf
        <input type="hidden" name="map_drawing_id" value="{{ $mapDrawing->id }}">
        <textarea name="decision_note" rows="3" placeholder="Reason for approval or revision request...">{{ old('decision_note') }}</textarea>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn primary" type="submit" name="decision" value="approved">Approve map</button>
          <button class="btn" style="border-color:rgba(169,31,31,.35); color:var(--red);" type="submit" name="decision" value="revision_required">Request revision</button>
        </div>
      </form>
    </section>
  @endif

  @if($mapDrawing && $measurementVerificationRequired)
    <section class="card" style="margin-bottom:16px; border-color:rgba(148,98,0,.35);">
      <h2>Make This Case Conclusive</h2>
      <p class="muted">The system has mapped plot and floor geometry, but it is still treating reconstructed CAD measurements as draft. If the automatic footprint is oversized, enter the architect-verified measurements from the plan. These values override weak geometry estimates, rerun rules, and are saved as training data.</p>
      <form method="POST" action="{{ route('admin.plan.cad-planner-review.confirm-measurements', $submission->id) }}" class="grid" style="gap:10px;">
        @csrf
        <input type="hidden" name="map_drawing_id" value="{{ $mapDrawing->id }}">
        <div class="grid three">
          @foreach([
            'plot_area_sqft' => 'Plot area (sqft)',
            'ground_floor_area_sqft' => 'Ground floor covered area',
            'first_floor_area_sqft' => 'First floor covered area',
            'second_floor_area_sqft' => 'Second floor covered area',
            'ground_coverage_percent' => 'Ground coverage %',
            'far' => 'FAR',
            'front_setback_ft' => 'Front setback ft',
            'rear_setback_ft' => 'Rear setback ft',
            'left_setback_ft' => 'Left setback ft',
            'right_setback_ft' => 'Right setback ft',
            'porch_length_ft' => 'Porch length ft',
            'storey_count' => 'Storey count',
          ] as $key => $label)
            <label>
              <span>{{ $label }}</span>
              <input type="number" step="0.001" name="measurement_overrides[{{ $key }}]" value="{{ old('measurement_overrides.'.$key, $prefillMeasurements[$key] ?? '') }}" placeholder="Auto: {{ $geometry[$key] ?? '-' }}">
            </label>
          @endforeach
        </div>
        <textarea name="confirmation_note" rows="3" placeholder="Optional note, e.g. Checked against architect dimensions and visual plot boundary."></textarea>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn primary" type="submit">Confirm measurements and rerun final decision</button>
          <a class="btn" href="{{ route('admin.plan.cad-layer-viewer', ['id' => $submission->id, 'map_drawing_id' => $mapDrawing->id]) }}">Review visually first</a>
        </div>
      </form>
    </section>
  @elseif($measurementsConfirmed)
    <section class="card" style="margin-bottom:16px; border-color:rgba(15,107,95,.28);">
      <h2>Planner Measurements Confirmed</h2>
      <p class="muted">This report is now using planner-confirmed measurements for pass/fail rule decisions.</p>
    </section>
  @endif

  <div class="grid three">
    <div class="card">
      <h2>Precheck</h2>
      <div class="flow">
        @foreach($precheck as $item)
          <div class="flow-row">
            <span class="dot {{ $item['ok'] ? '' : 'review' }}"></span>
            <span>{{ $item['label'] }}</span>
            <b style="margin-left:auto;">{{ $item['ok'] ? 'OK' : 'Review' }}</b>
          </div>
        @endforeach
      </div>
    </div>
    <div class="card">
      <h2>Rule Snapshot</h2>
      <div class="grid three">
        <div class="metric"><span class="muted">Pass</span><b>{{ $passed }}</b></div>
        <div class="metric"><span class="muted">Review</span><b>{{ $needsReview }}</b></div>
        <div class="metric"><span class="muted">Fail</span><b>{{ $failed }}</b></div>
      </div>
      <p class="muted">Draft values are not final when measurement verification is required.</p>
    </div>
    <div class="card">
      <h2>Training Confidence</h2>
      <div class="grid two">
        <div class="metric"><span class="muted">Mapped</span><b>{{ $trainingStats['mapped_entities'] }}</b></div>
        <div class="metric"><span class="muted">Verified</span><b>{{ $trainingStats['expert_verified'] }}</b></div>
      </div>
      <p class="muted">Every expert correction becomes repeatable training data for the next file.</p>
    </div>
  </div>

  <div class="grid two" style="margin-top:16px;">
    <div class="card">
      <h2>Measurements</h2>
      <div class="grid two">
        @foreach([
          'plot_area_sqft' => 'Plot area',
          'ground_floor_area_sqft' => 'Ground floor',
          'first_floor_area_sqft' => 'First floor',
          'ground_coverage_percent' => 'Coverage %',
          'far' => 'FAR',
          'storey_count' => 'Storeys',
        ] as $key => $label)
          <div class="metric">
            <span class="muted">{{ $label }}</span>
            <b>{{ $geometry[$key] ?? '-' }}</b>
          </div>
        @endforeach
      </div>
    </div>
    <div class="card">
      <h2>Mapped Entities</h2>
      <table>
        <thead><tr><th>Semantic</th><th>Layer</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($mappedEntities as $entity)
          <tr>
            <td><b>{{ $entity->semantic_entity }}</b><div class="muted">{{ $entity->handle }}</div></td>
            <td>{{ $entity->layer_name }}</td>
            <td><span class="status {{ $entity->mapping_status }}">{{ $entity->mapping_status }}</span></td>
          </tr>
        @empty
          <tr><td colspan="3" class="muted">No semantic entities mapped yet.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid two" style="margin-top:16px;">
    <div class="card">
      <h2>Rule Checklist</h2>
      <table>
        <thead><tr><th>Rule</th><th>Required</th><th>Actual</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($rules as $rule)
          <tr>
            <td><b>{{ $rule['rule_code'] ?? '-' }}</b><div class="muted">{{ $rule['message'] ?? '' }}</div></td>
            <td>{{ $rule['required'] ?? '-' }}</td>
            <td>{{ $rule['actual'] ?? '-' }}</td>
            <td><span class="status {{ $rule['status'] ?? 'needs_review' }}">{{ $rule['status'] ?? '-' }}</span></td>
          </tr>
        @empty
          <tr><td colspan="4" class="muted">Run semantic validation to generate rule results.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <div class="card">
      <h2>OpenClaw Chat Assistant</h2>
      <div class="chat">
        <div class="chat-box">
          <b>Planner assistant placeholder</b>
          <p class="muted">Next integration: connect OpenClaw to answer from FAQ, explain this report, and list the exact next expert action. It should not approve/reject by itself.</p>
          <p><b>Suggested prompt:</b> “Explain why this case needs expert review and what the planner should verify first.”</p>
        </div>
        <div class="input-row">
          <input value="Ask about this report..." readonly>
          <button class="btn" type="button">Connect OpenClaw</button>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
