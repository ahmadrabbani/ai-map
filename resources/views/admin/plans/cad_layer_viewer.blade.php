<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CAD Layer Viewer</title>
  <link rel="preconnect" href="https://fonts.bunny.net">
  <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700|manrope:400,500,600" rel="stylesheet" />
  <style>
    :root {
      color-scheme: light;
      --bg: #f7f2ea;
      --bg-deep: #efe6da;
      --surface: #ffffff;
      --ink: #101418;
      --muted: #4e5a66;
      --accent: #0f6b5f;
      --accent-bright: #f28b2d;
      --line: rgba(16, 20, 24, 0.12);
      --shadow: 0 22px 45px rgba(16, 20, 24, 0.12);
      --radius-lg: 24px;
      --radius-md: 16px;
      --radius-sm: 12px;
    }

    * { box-sizing: border-box; }

    body {
      font-family: "Manrope", "Segoe UI", sans-serif;
      margin: 0;
      color: var(--ink);
      background: radial-gradient(circle at 15% 20%, #f8f1dd, transparent 55%),
        radial-gradient(circle at 85% 15%, #d4efe7, transparent 55%),
        linear-gradient(140deg, var(--bg), var(--bg-deep));
      min-height: 100vh;
    }

    a { color: inherit; text-decoration: none; }

    .page {
      position: relative;
      overflow: hidden;
    }

    .page::before {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(120deg, rgba(255, 255, 255, 0.35), transparent 40%),
        radial-gradient(circle at 80% 70%, rgba(242, 139, 45, 0.14), transparent 50%);
      pointer-events: none;
      z-index: 0;
    }

    .shell {
      position: relative;
      z-index: 1;
      max-width: 1400px;
      margin: 0 auto;
      padding: 32px 24px 18px;
    }

    .topbar-nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 24px;
      padding: 12px 18px;
      background: rgba(255, 255, 255, 0.7);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      backdrop-filter: blur(12px);
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
      font-family: "Space Grotesk", "Manrope", sans-serif;
      font-weight: 600;
      letter-spacing: 0.02em;
    }

    .brand-mark {
      width: 40px;
      height: 40px;
      border-radius: 14px;
      display: grid;
      place-items: center;
      font-weight: 700;
      color: #fff;
      background: linear-gradient(135deg, var(--accent), #133f39);
      box-shadow: 0 10px 24px rgba(15, 107, 95, 0.28);
    }

    .nav {
      display: flex;
      flex-wrap: wrap;
      gap: 18px;
      font-size: 0.95rem;
      color: var(--muted);
    }

    .nav a {
      padding: 6px 0;
      border-bottom: 2px solid transparent;
    }

    .nav a:hover {
      color: var(--ink);
      border-color: var(--accent-bright);
    }

    .actions {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 18px;
      border-radius: 999px;
      border: 1px solid transparent;
      font-weight: 600;
      font-size: 0.95rem;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .btn.primary {
      background: var(--accent);
      color: #fff;
      box-shadow: 0 12px 24px rgba(15, 107, 95, 0.25);
    }

    .btn.ghost {
      border-color: var(--line);
      background: #fff;
    }

    .btn:hover { transform: translateY(-1px); }

    .viewer-shell {
      position: relative;
      z-index: 1;
      max-width: none;
      margin: 0 auto;
      padding: 18px 24px 48px;
    }

    #cad-layer-viewer-root {
      width: 100%;
    }

    .layout {
      display: flex;
      height: calc(100vh - 190px);
      min-height: 640px;
      border-radius: var(--radius-lg);
      border: 1px solid var(--line);
      background: var(--surface);
      box-shadow: var(--shadow);
      overflow: hidden;
    }

    .sidebar {
      width: 360px;
      border-right: 1px solid var(--line);
      padding: 16px;
      overflow: auto;
      background: #fbfaf8;
    }

    .main { flex: 1; position: relative; min-width: 0; min-height: 0; }

    .cad-view-column {
      flex: 0 0 66.666%;
      position: relative;
      min-width: 0;
      min-height: 0;
      border-right: 1px solid rgba(16,20,24,0.12);
      overflow: hidden;
    }

    .cad-details-panel {
      flex: 0 0 33.333%;
      min-width: 0;
      padding: 12px;
      overflow: auto;
      background: #fcfbfa;
    }

    .layout.officer-simple-layout > .sidebar { display: none; }
    .layout.officer-simple-layout .cad-view-column { flex: 0 0 68%; }
    .layout.officer-simple-layout .cad-details-panel { flex: 1 1 32%; }
    .cad-details-panel.officer-simple > :not(.officer-workflow) { display: none; }
    .learning-snapshot { width: 100%; max-height: 220px; object-fit: contain; border: 1px solid #cad5e1; border-radius: 8px; background: #fff; }

    @media (max-width: 900px) {
      .layout.officer-simple-layout { display: block; height: auto; min-height: 0; }
      .layout.officer-simple-layout .cad-view-column { height: 62vh; min-height: 460px; border-right: 0; }
      .layout.officer-simple-layout .cad-details-panel { max-height: none; }
    }

    .topbar {
      padding: 10px 14px;
      border-bottom: 1px solid var(--line);
      display: flex;
      gap: 10px;
      align-items: center;
      background: rgba(255, 255, 255, 0.9);
    }

    .canvas-wrap {
      position: absolute;
      inset: 52px 0 0 0;
      overflow: auto;
      overscroll-behavior: contain;
      scrollbar-gutter: stable;
      background: #fff;
    }
    .cad-canvas-stage {
      position: relative;
      width: max(100%, 960px);
      height: max(100%, 640px);
      min-width: 0;
      min-height: 0;
    }
    .cad-canvas-stage .loading-overlay { inset: 0; }
    #cad-canvas { width: 100%; height: 100%; display: block; }
    #cad-canvas.measuring { cursor: crosshair; }
    .layer-row { display: flex; gap: 8px; align-items: center; padding: 6px 0; border-bottom: 1px dashed rgba(16, 20, 24, 0.1); cursor: pointer; }
    .layer-row.selected { background: rgba(15, 107, 95, 0.08); border-left: 3px solid var(--accent); padding-left: 10px; }
    .layer-name { flex: 1; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .layer-info-popup {
      position: absolute;
      top: 78px;
      right: 18px;
      max-width: 320px;
      background: rgba(255, 255, 255, 0.94);
      border: 1px solid rgba(16, 20, 24, 0.12);
      border-radius: 16px;
      padding: 14px;
      box-shadow: 0 18px 36px rgba(16, 20, 24, 0.12);
      z-index: 3;
      font-size: 12px;
      max-height: calc(100% - 108px);
      overflow: auto;
      overscroll-behavior: contain;
    }
    .layer-info-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 6px;
      padding-right: 2px;
    }
    .layer-info-close {
      flex: 0 0 34px;
      width: 34px;
      height: 34px;
      display: grid;
      place-items: center;
      padding: 0;
      margin: -7px -7px 0 0;
      border-radius: 50%;
      border-color: rgba(16, 20, 24, 0.2);
      color: #25313b;
      background: #fff;
      font-size: 24px;
      line-height: 1;
      box-shadow: 0 3px 10px rgba(16, 20, 24, 0.12);
    }
    .layer-info-close:hover,
    .layer-info-close:focus-visible {
      color: #fff;
      background: #b21c1c;
      border-color: #b21c1c;
      outline: 3px solid rgba(178, 28, 28, 0.2);
    }
    .layer-info-feedback {
      margin-top: 8px;
      padding: 8px 10px;
      border-radius: 8px;
      font-weight: 600;
    }
    .layer-info-feedback.success {
      color: #0b5f49;
      background: #e8f8f1;
      border: 1px solid #9bd8c0;
    }
    .layer-info-feedback.error {
      color: #8d1515;
      background: #fff0f0;
      border: 1px solid #efb3b3;
    }
    select, button { padding: 8px 10px; border-radius: 999px; border: 1px solid var(--line); background: #fff; font-family: inherit; }
    button { cursor: pointer; }
    input[type="text"], input[type="number"], textarea { padding: 8px 10px; border: 1px solid var(--line); border-radius: 12px; font-family: inherit; }
    textarea { resize: vertical; }
    .pill { font-size: 12px; background: rgba(15, 107, 95, 0.12); color: var(--accent); padding: 2px 10px; border-radius: 999px; }
    .muted { color: var(--muted); font-size: 12px; }
    .warn { color: #b21c1c; font-weight: 600; }
    .card { background: #fff; border-radius: var(--radius-sm); }
    .loading-overlay {
      position:absolute;
      inset: 52px 0 0 0;
      display:flex;
      align-items:center;
      justify-content:center;
      background:rgba(255,255,255,0.88);
      font-size:14px;
      color: var(--ink);
      z-index: 2;
    }
    .loading-box {
      background:#fff;
      border:1px solid var(--line);
      border-radius:10px;
      padding:16px 18px;
      box-shadow:0 12px 24px rgba(16, 20, 24, 0.12);
      min-width: 260px;
    }
    .loading-bar {
      height:6px;
      background:#f0f0f0;
      border-radius:999px;
      overflow:hidden;
      margin-top:10px;
    }
    .loading-bar > span {
      display:block;
      height:100%;
      background: var(--accent);
      width:40%;
      animation: pulse 1.2s ease-in-out infinite;
    }
    @keyframes pulse {
      0% { transform: translateX(-60%); }
      50% { transform: translateX(20%); }
      100% { transform: translateX(120%); }
    }
    @media (max-width: 1100px) {
      .layout { flex-direction: column; height: auto; min-height: 720px; }
      .sidebar { width: 100%; border-right: 0; border-bottom: 1px solid var(--line); }
      .main { min-height: 540px; }
    }

    @media (max-width: 900px) {
      .main { flex-direction: column; min-height: 900px; }
      .cad-view-column {
        flex: 0 0 560px;
        width: 100%;
        border-right: 0;
        border-bottom: 1px solid rgba(16,20,24,0.12);
      }
      .cad-details-panel {
        flex: 1 1 auto;
        width: 100%;
        max-height: 420px;
      }
      .cad-canvas-stage {
        width: max(100%, 760px);
        height: max(100%, 560px);
      }
      .layer-info-popup {
        position: fixed;
        top: auto;
        right: 12px;
        bottom: 12px;
        left: 12px;
        max-width: none;
        max-height: min(70vh, 520px);
        z-index: 30;
      }
    }

    @media (max-width: 900px) {
      .topbar-nav {
        flex-direction: column;
        align-items: flex-start;
      }
      .actions { width: 100%; justify-content: flex-start; }
    }
  </style>
</head>
<body>
@php
  $layerMap = isset($label) && $label->layer_map_json
      ? json_decode($label->layer_map_json, true)
      : null;
@endphp

  <div class="page">
    <div class="shell">
      <header class="topbar-nav">
        <a class="brand" href="/">
          <div class="brand-mark">M</div>
          <span>Map AI Verification</span>
        </a>
        <nav class="nav">
          <a href="/">Home</a>
          <a href="{{ url('/admin/plan/ad-epermit') }}">Dashboard</a>
          <a href="{{ route('admin.plan.cad-expert-label.edit', $submission->id) }}">Expert Labels</a>
          <a href="{{ route('admin.plan.cad-planner-review', ['id' => $submission->id, 'map_drawing_id' => optional($mapDrawing)->id]) }}">Planner Review</a>
          <a href="{{ route('admin.plan.cad-layer-viewer', $submission->id) }}">Layer Viewer</a>
          <a href="{{ route('admin.plan.cad-tagging.building-plans') }}">Tagging Queue</a>
          <a href="{{ route('admin.plan.cad-tagging.accuracy') }}">Accuracy</a>
        </nav>
        <div class="actions">
          <a class="btn ghost" href="{{ route('admin.plan.cad-expert-label.edit', $submission->id) }}">Back to labels</a>
          <a class="btn primary" href="{{ url('/admin/plan/ad-epermit') }}">Dashboard</a>
        </div>
      </header>
    </div>

    <div class="viewer-shell">
      <div id="cad-layer-viewer-root"></div>
    </div>
  </div>

  <script src="/vendor/dxf-parser/dxf-parser.js"></script>
  @php
    $activeLayerConfig = file_exists(base_path('rules/layer_35.json')) ? 'layer_35.json' : 'layers.json';
  @endphp
  @php
    $cadTextReport = [
      'metrics' => (array) data_get(optional($mapDrawing)->metadata_json, 'cad_text_measurement_metrics', []),
      'room_areas' => (array) data_get(optional($mapDrawing)->metadata_json, 'cad_text_room_areas', []),
      'plot' => (array) data_get(optional($mapDrawing)->metadata_json, 'cad_text_plot', []),
      'applicant' => (array) data_get(optional($mapDrawing)->metadata_json, 'cad_text_applicant', []),
      'sections' => (array) data_get(optional($mapDrawing)->metadata_json, 'cad_text_sections', []),
    ];
  @endphp
  <script>
    window.__cadViewerConfig = {
      submissionId: {{ $submission->id }},
      dxfUrl: "{{ route('admin.plan.cad-submissions.dxf', $submission->id) }}",
      storeUrl: "{{ route('admin.plan.cad-layer-map.store', $submission->id) }}",
      backToLabelUrl: "{{ route('admin.plan.cad-expert-label.edit', $submission->id) }}",
      storeExpertResultUrl: "{{ route('admin.plan.cad-expert-results.store', $submission->id) }}",
      csrfToken: "{{ csrf_token() }}",
      layerMap: @json($layerMap ?? new stdClass()),
      rules: @json($rules ?? []),
      rulesMetadata: @json($rulesMetadata ?? new stdClass()),
      expertResults: @json($expertResults ?? []),
      analysisResult: @json($submission->analysis_result ?? new stdClass()),
      cadTextReport: @json($cadTextReport),
      trainingLabel: @json(optional($submission->trainingLabel)->toArray() ?? new stdClass()),
      layerIdentificationReport: @json($layerIdentificationReport ?? new stdClass()),
      entitySummary: @json($entitySummary ?? new stdClass()),
      rulesetOverview: @json($rulesetOverview ?? new stdClass()),
      tagOptions: @json($tagOptions ?? []),
      floorContext: @json(request('floor_context', 'ground_floor')),
      mapDrawingId: @json(optional($mapDrawing)->id),
      mapEntitiesUrl: @json(optional($mapDrawing)->id ? route('api.map-approval.entities', ['drawing' => $mapDrawing->id]) : null),
      mapSummaryUrl: @json(optional($mapDrawing)->id ? route('api.map-approval.mapping-summary', ['drawing' => $mapDrawing->id]) : null),
      mapSuggestionsUrl: @json(optional($mapDrawing)->id ? route('api.map-approval.layer-suggestions', ['drawing' => $mapDrawing->id]) : null),
      mapManualMapUrl: @json(optional($mapDrawing)->id ? route('api.map-approval.manual-map', ['drawing' => $mapDrawing->id]) : null),
      mapValidationUrl: @json(optional($mapDrawing)->id ? route('api.map-approval.run-validation', ['drawing' => $mapDrawing->id]) : null),
      mapReportUrl: @json(optional($mapDrawing)->id ? route('api.map-approval.report', ['drawing' => $mapDrawing->id]) : null),
      cadEntitiesUrl: "{{ route('admin.plan.cad-entities.index', $submission->id) }}",
      cadLabelsUrl: "{{ route('admin.plan.cad-labels.index', $submission->id) }}",
      cadLabelMappingsStoreUrl: "{{ route('admin.plan.cad-label-mappings.store', $submission->id) }}",
      cadAutoSuggestMappingsUrl: "{{ route('admin.plan.cad-label-mappings.auto-suggest', $submission->id) }}",
      cadMappingReportUrl: "{{ route('admin.plan.cad-label-mappings.report', $submission->id) }}",
      cadLabelMappingsDeleteUrlTemplate: "/admin/plan/cad-submissions/{{ $submission->id }}/label-mappings/__MAPPING_ID__",
      expertMarkingsUrl: "{{ route('admin.plan.cad-expert-markings.index', $submission->id) }}",
      expertMarkingsStoreUrl: "{{ route('admin.plan.cad-expert-markings.store', $submission->id) }}",
      expertMarkingsUpdateUrlTemplate: "/admin/plan/cad-submissions/{{ $submission->id }}/expert-markings/__MARKING_ID__",
      expertMarkingsDeleteUrlTemplate: "/admin/plan/cad-submissions/{{ $submission->id }}/expert-markings/__MARKING_ID__",
      expertMarkingsConfirmUrlTemplate: "/admin/plan/cad-submissions/{{ $submission->id }}/expert-markings/__MARKING_ID__/confirm",
      expertMarkingReportUrl: "{{ route('admin.plan.cad-expert-markings.report', $submission->id) }}",
      cadTextReferencesStoreUrl: "{{ route('admin.plan.cad-text-references.store', $submission->id) }}",
      cadAssistantChatUrl: "{{ route('admin.plan.cad-assistant-chat', $submission->id) }}",
      taggingWorkspaceUrl: "{{ route('api.cad.workspace', $submission->id) }}",
      predictionImportUrl: "{{ route('api.cad.predictions.import', $submission->id) }}",
      predictionReviewUrlTemplate: "/api/cad-submissions/{{ $submission->id }}/predictions/__PREDICTION_ID__/review",
      predictionBulkReviewUrl: "{{ route('api.cad.predictions.bulk-review', $submission->id) }}",
      submitVerifiedTagsUrl: "{{ route('api.cad.tags.submit-verified', $submission->id) }}",
      evaluateTagsUrl: "{{ route('api.cad.evaluate', $submission->id) }}",
      activeLayerConfig: @json($activeLayerConfig),
      statusMessage: @json(session('status')),
      hasDxf: {{ $submission->stored_dxf_path ? 'true' : 'false' }},
      autoMapOnLoad: true,
    };
  </script>
  @viteReactRefresh
  @vite(['resources/css/app.css', 'resources/js/cad-layer-viewer.jsx'])
</body>
</html>
