<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Expert Marking</title>
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

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Manrope", "Segoe UI", sans-serif;
            color: var(--ink);
            background: radial-gradient(circle at 15% 20%, #f8f1dd, transparent 55%),
                radial-gradient(circle at 85% 15%, #d4efe7, transparent 55%),
                linear-gradient(140deg, var(--bg), var(--bg-deep));
            min-height: 100vh;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

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
            max-width: 1180px;
            margin: 0 auto;
            padding: 32px 28px 96px;
        }

        .topbar {
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

        .btn:hover {
            transform: translateY(-1px);
        }

        .page-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 0.6fr);
            gap: 28px;
            margin: 56px 0 32px;
            align-items: center;
        }

        .page-header h1 {
            font-family: "Space Grotesk", "Manrope", sans-serif;
            font-size: clamp(2rem, 3vw, 3rem);
            line-height: 1.05;
            margin: 0 0 14px;
        }

        .page-header p {
            margin: 0;
            color: var(--muted);
            font-size: 1.05rem;
        }

        .status-card {
            background: #0f2f2b;
            color: #fff;
            border-radius: var(--radius-md);
            padding: 18px;
        }

        .grid {
            display: grid;
            gap: 18px;
        }

        .grid.two {
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }

        .card {
            background: var(--surface);
            border-radius: var(--radius-md);
            padding: 20px;
            border: 1px solid var(--line);
            box-shadow: 0 16px 30px rgba(16, 20, 24, 0.08);
        }

        .card h2,
        .card h3,
        .card h4 {
            margin: 0 0 12px;
            font-family: "Space Grotesk", "Manrope", sans-serif;
        }

        .muted {
            color: var(--muted);
            font-size: 0.95rem;
        }

        .ok {
            color: #0f6b5f;
            font-weight: 600;
        }

        .field {
            display: grid;
            gap: 8px;
            margin-bottom: 14px;
        }

        select,
        input[type="text"],
        textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            background: #fff;
            font-family: inherit;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        th,
        td {
            border-bottom: 1px solid rgba(16, 20, 24, 0.08);
            padding: 10px 6px;
            text-align: left;
        }

        .iframe {
            width: 100%;
            border: 1px solid rgba(16, 20, 24, 0.08);
            border-radius: var(--radius-sm);
            margin-top: 12px;
            min-height: 300px;
        }

        .viewer-frame {
            width: 100%;
            border: 1px solid rgba(16, 20, 24, 0.08);
            border-radius: var(--radius-sm);
            margin-top: 12px;
            min-height: 680px;
            background: #fff;
        }

        .workspace-switch {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .workspace-switch .pill-btn {
            text-decoration: none;
        }

        .footer {
            margin-top: 48px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .alert {
            padding: 14px 16px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
            background: rgba(242, 139, 45, 0.1);
            margin-bottom: 18px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            background: rgba(15, 107, 95, 0.12);
            color: var(--accent);
            font-weight: 600;
        }

        .mapping-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(220px, 0.9fr);
            gap: 10px;
            align-items: start;
        }

        .mapping-row {
            display: contents;
        }

        .mapping-cell {
            padding: 10px 12px;
            border: 1px solid rgba(16, 20, 24, 0.08);
            border-radius: var(--radius-sm);
            background: #fcfbfa;
        }

        .mapping-cell strong {
            display: block;
            margin-bottom: 4px;
        }

        .compact-select {
            width: 100%;
            min-width: 0;
            border-radius: 12px;
        }

        .manual-layer-input {
            margin-top: 8px;
        }

        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 0 0 10px;
        }

        .floor-switcher {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pill-btn {
            display: inline-flex;
            align-items: center;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            font-size: 0.9rem;
            color: var(--muted);
        }

        .pill-btn.active {
            background: rgba(15, 107, 95, 0.12);
            color: var(--accent);
            border-color: rgba(15, 107, 95, 0.22);
            font-weight: 700;
        }

        .helper-strip {
            margin-bottom: 16px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(15, 107, 95, 0.08);
            color: var(--muted);
            font-size: 0.92rem;
        }

        @media (max-width: 900px) {
            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .actions {
                width: 100%;
                justify-content: flex-start;
            }

            .page-header {
                grid-template-columns: 1fr;
            }

            .mapping-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {
            .shell {
                padding: 24px 18px 72px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="/">
                <div class="brand-mark">M</div>
                <span>Map AI Verification</span>
            </a>
            <nav class="nav">
                <a href="/">Home</a>
                <a href="{{ url('/admin/plan/ad-epermit') }}">Dashboard</a>
                <a href="#labels">Expert Labels</a>
                <a href="{{ route('admin.plan.cad-layer-viewer', $submission->id) }}">Layer Viewer</a>
            </nav>
            <div class="actions">
                <a class="btn ghost" href="{{ url('/admin/plan/ad-epermit') }}">Dashboard</a>
                <a class="btn primary" href="#labels">Open mapping</a>
            </div>
        </header>

        <main>
            @if($errors->any())
                <div class="alert">
                    <strong>Please review the form.</strong>
                    <ul style="margin: 10px 0 0 18px;">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="page-header">
                <div>
                    <h1>Layer selection and expert marking</h1>
                    <p>
                        Match each uploaded CAD layer to the official JSON-defined layer structure so the system can map
                        plot boundary, floors, setbacks, dimensions, and text correctly before rerun.
                    </p>
                </div>
                <div class="status-card">
                    <strong>Guideline-based mapping enabled</strong>
                    <p class="muted" style="color: rgba(255, 255, 255, 0.78); margin: 10px 0 0;">
                        Use detected layers first. Use entity handles only when the drawing does not follow the layer guideline.
                    </p>
                </div>
            </section>

            <section class="card">
                <h2>Run compliance with expert labels</h2>
                <form action="{{ route('admin.plan.cad-compliance.rerun', ['id' => $submission->id]) }}" method="POST">
                    @csrf
                    <button class="btn primary" type="submit">Run compliance with expert labels</button>
                    <span class="muted" style="margin-left: 8px;">Uses your layer map and handles to recompute results.</span>
                </form>
                @if(session('status'))
                    <div class="ok" style="margin-top: 10px;">{{ session('status') }}</div>
                @endif
            </section>

            <section style="margin-top: 24px;">
                <div class="card" id="labels">
                    <div class="helper-strip" style="margin-bottom: 18px;">
                        Use one focused CAD screen: open the dedicated viewer for layer highlighting and measurements, then return here to finalize layer mapping.
                        <div class="workspace-switch" style="margin-top:10px;">
                            <a class="pill-btn active" href="{{ route('admin.plan.cad-layer-viewer', array_merge(['id' => $submission->id], ['floor_context' => $floorContext])) }}" target="_blank">Open CAD viewer</a>
                            <a class="pill-btn" href="#labels">Stay on mapping form</a>
                        </div>
                    </div>
                    <div class="section-head">
                        <div>
                            <h3 style="margin: 0;">Step 1: Map uploaded layers to official layer definitions</h3>
                            <div class="muted" style="margin-top: 6px;">Only layers relevant to the selected floor are shown.</div>
                        </div>
                        <div class="floor-switcher">
                            @foreach([
                                'basement' => 'Basement',
                                'ground_floor' => 'Ground',
                                'first_floor' => 'First',
                                'second_floor' => 'Second',
                                'roof' => 'Roof',
                            ] as $floorKey => $floorLabel)
                                <a
                                    href="{{ route('admin.plan.cad-expert-label.edit', ['id' => $submission->id, 'floor_context' => $floorKey]) }}"
                                    class="pill-btn {{ $floorContext === $floorKey ? 'active' : '' }}"
                                >
                                    {{ $floorLabel }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                    @php
                        $floorOrder = ['basement' => 'Basement', 'ground_floor' => 'Ground', 'first_floor' => 'First', 'second_floor' => 'Second', 'roof' => 'Roof'];
                        $floorKeys = array_keys($floorOrder);
                        $currentFloorIndex = array_search($floorContext, $floorKeys, true);
                        $currentFloorIndex = $currentFloorIndex === false ? 1 : $currentFloorIndex;
                        $nextFloorKey = $floorKeys[min($currentFloorIndex + 1, count($floorKeys) - 1)];
                    @endphp
                    <div class="helper-strip" style="display:grid; gap:10px;">
                        <div>
                            Editing context: <strong>{{ ucwords(str_replace('_', ' ', $floorContext)) }}</strong>.
                            Mark the current floor first, then reuse repeated component patterns on the next floor instead of rediscovering them layer by layer.
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            @foreach($floorOrder as $floorKey => $floorLabel)
                                <a
                                    href="{{ route('admin.plan.cad-expert-label.edit', ['id' => $submission->id, 'floor_context' => $floorKey]) }}"
                                    class="pill-btn {{ $floorContext === $floorKey ? 'active' : '' }}"
                                >
                                    {{ $floorLabel }}
                                </a>
                            @endforeach
                            <a class="pill-btn active" href="{{ route('admin.plan.cad-layer-viewer', array_merge(['id' => $submission->id], ['floor_context' => $nextFloorKey])) }}" target="_blank">
                                Open next floor viewer
                            </a>
                        </div>
                        <div class="muted">
                            Example workflow: ground floor stairs, doors, and walls can be mapped once, then the viewer can help locate matching stair or wall layers on first and second floors.
                        </div>
                    </div>
                    <form action="{{ route('admin.plan.cad-expert-label.store', ['id' => $submission->id]) }}" method="POST">
                        @csrf
                        <input type="hidden" name="floor_context" value="{{ $floorContext }}">

                        @foreach($layerGroups as $category => $definitions)
                            <div style="margin-bottom: 18px;">
                                <h4 style="margin: 0 0 10px;">{{ ucwords(str_replace('_', ' ', $category)) }}</h4>
                                <div class="mapping-grid">
                                    @foreach($definitions as $definition)
                                        @php
                                            $currentMappedValue = $currentLayerMap[$definition['tag']] ?? '';
                                            if (is_array($currentMappedValue)) {
                                                $currentMappedValue = implode(' | ', array_values(array_filter(array_map('trim', $currentMappedValue))));
                                            }
                                            $selectedLayer = old('official_layer_map.'.$definition['tag'], $currentMappedValue);
                                            $datalistId = 'layer_options_'.$definition['tag'];
                                        @endphp
                                        <div class="mapping-row">
                                            <div class="mapping-cell">
                                                <strong>{{ $definition['code'] }}</strong>
                                                <div class="muted">{{ $definition['description'] }}</div>
                                                <div style="margin-top: 8px;"><span class="badge">{{ $definition['tag'] }}</span></div>
                                            </div>
                                            <div class="mapping-cell">
                                                <label for="official_layer_map_{{ $definition['tag'] }}" class="muted" style="display:block; margin-bottom:6px;"><strong>Detected CAD layer</strong></label>
                                                <select
                                                    id="official_layer_map_{{ $definition['tag'] }}"
                                                    class="compact-select"
                                                    onchange="document.getElementById('manual_layer_{{ $definition['tag'] }}').value = this.value;"
                                                >
                                                    <option value="">Leave unmapped</option>
                                                    @foreach($layers as $detectedLayer)
                                                        <option value="{{ $detectedLayer->layer }}" {{ $selectedLayer === $detectedLayer->layer ? 'selected' : '' }}>
                                                            {{ $detectedLayer->layer }}{{ isset($detectedLayer->cnt) ? ' ('.$detectedLayer->cnt.')' : '' }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <input
                                                    id="manual_layer_{{ $definition['tag'] }}"
                                                    type="text"
                                                    name="official_layer_map[{{ $definition['tag'] }}]"
                                                    value="{{ $selectedLayer }}"
                                                    class="manual-layer-input"
                                                    list="{{ $datalistId }}"
                                                    placeholder="Type or adjust layer name manually"
                                                >
                                                <datalist id="{{ $datalistId }}">
                                                    @foreach($layers as $detectedLayer)
                                                        <option value="{{ $detectedLayer->layer }}"></option>
                                                    @endforeach
                                                </datalist>
                                                <div class="muted" style="margin-top: 8px;">
                                                    Maps uploaded layer to <code>{{ $definition['tag'] }}</code>.
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        <div class="field">
                            <label><strong>Front side</strong> (helps setbacks)</label>
                            <select name="front_side">
                                @foreach(['auto'=>'Auto','north'=>'North','south'=>'South','east'=>'East','west'=>'West'] as $k=>$v)
                                    <option value="{{ $k }}" {{ old('front_side', $label->front_side ?? 'auto') === $k ? 'selected' : '' }}>{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="field">
                            <label><strong>Notes</strong> (optional)</label>
                            <textarea name="notes" rows="3" placeholder="Any layer conventions, special cases...">{{ old('notes', $label->notes) }}</textarea>
                        </div>

                        <h3 style="margin: 18px 0 6px;">Step 2: Manual geometry override</h3>
                        <div class="muted" style="margin-bottom: 10px;">Use exact handles only if the uploaded drawing does not follow the layer guideline or the wrong polygon is still being selected.</div>

                        <div class="field">
                            <label><strong>Plot polyline handle</strong></label>
                            <input type="text" name="plot_entity_handle" value="{{ old('plot_entity_handle', $label->plot_entity_handle) }}" placeholder="Handle from candidates table below">
                        </div>

                        <div class="field">
                            <label><strong>Building footprint polyline handle</strong></label>
                            <input type="text" name="building_entity_handle" value="{{ old('building_entity_handle', $label->building_entity_handle) }}" placeholder="Handle from candidates table below">
                        </div>

                        <div class="field">
                            <label><strong>Floor handles JSON</strong></label>
                            <textarea name="floor_handles_json" rows="4" placeholder='[{"floor":0,"handle":"1A2B"},{"floor":1,"handle":"1A2C"}]'>{{ old('floor_handles_json', optional($submission->trainingLabel)->floor_handles ? json_encode($submission->trainingLabel->floor_handles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea>
                            <div class="muted">Use this when multiple floor polygons must be selected manually after layer mapping is complete.</div>
                        </div>

                        @php
                            $savedFloorTemplates = optional($submission->trainingLabel)->floor_templates ?? [];
                            if (!is_array($savedFloorTemplates)) {
                                $savedFloorTemplates = [];
                            }
                        @endphp
                        <div class="field">
                            <label><strong>Floor templates JSON</strong></label>
                            <textarea name="floor_templates_json" id="floor_templates_json" rows="6" placeholder='{"ground_floor":{"layer_names":["ground_external_walls","ground_stairs"],"entity_handles":["1A2B"],"active_label_key":"stairs"}}'>{{ old('floor_templates_json', !empty($savedFloorTemplates) ? json_encode($savedFloorTemplates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea>
                            <div class="muted">
                                Saved templates let the viewer reuse a floor pattern later. Use the viewer button to capture the current floor into this JSON, or copy a saved template to the next floor.
                            </div>
                            @if(!empty($savedFloorTemplates))
                                <div style="margin-top: 10px;">
                                    <div class="muted" style="margin-bottom: 6px;">Saved floor templates</div>
                                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                        @foreach($savedFloorTemplates as $floorKey => $template)
                                            @php
                                                $layerCount = count(array_filter((array)($template['layer_names'] ?? [])));
                                                $handleCount = count(array_filter((array)($template['entity_handles'] ?? [])));
                                            @endphp
                                            <span class="badge">{{ ucwords(str_replace('_', ' ', $floorKey)) }}: {{ $layerCount }} layers / {{ $handleCount }} handles</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        <button class="btn primary" type="submit">Save layer mapping</button>
                    </form>

                    <div style="margin-top: 22px;">
                        <h4>Detected CAD layers</h4>
                        <div class="muted" style="margin-bottom: 8px;">These are the layers found in the uploaded drawing. Use them in the mapping selectors above.</div>
                        <table>
                            <thead><tr><th>Layer</th><th>Entities</th></tr></thead>
                            <tbody>
                            @foreach($layers as $l)
                                <tr><td>{{ $l->layer }}</td><td>{{ $l->cnt }}</td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top: 22px;">
                        <h4>Closed polyline candidates (top 50 by area)</h4>
                        <div class="muted">Use these handles only when layer-based mapping is not enough and you need to override plot or footprint selection manually.</div>
                        <table style="margin-top: 8px;">
                            <thead>
                            <tr>
                                <th>Handle</th>
                                <th>Layer</th>
                                <th>Area</th>
                                <th>BBox (W x H)</th>
                                <th>Rect.</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($candidates as $c)
                                <tr>
                                    <td><code>{{ $c->entity_handle }}</code></td>
                                    <td>{{ $c->layer }}</td>
                                    <td>{{ $c->area }}</td>
                                    <td>{{ $c->bbox_w }} x {{ $c->bbox_h }}</td>
                                    <td>{{ $c->rectangularity }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

        <footer class="footer">
            <span>Map AI Verification</span>
            <span>Compliance and zoning intelligence for modern planning teams.</span>
        </footer>
    </div>
</div>
<script>
    (function () {
        const allowedOrigin = window.location.origin;
        const floorTemplatesInput = document.getElementById('floor_templates_json');

        function parseJsonInput(node) {
            if (!node) return {};
            const raw = (node.value || '').trim();
            if (!raw) return {};
            try {
                const parsed = JSON.parse(raw);
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (err) {
                return {};
            }
        }

        function writeJsonInput(node, value) {
            if (!node) return;
            node.value = JSON.stringify(value || {}, null, 2);
            node.dispatchEvent(new Event('change', { bubbles: true }));
        }

        window.addEventListener('message', function (event) {
            if (event.origin !== allowedOrigin || !event.data || !event.data.type) {
                return;
            }

            if (event.data.type === 'cad-layer-map-suggestion') {
                const payload = event.data.payload || {};
                const officialTag = payload.officialTag || '';
                const layerNames = Array.isArray(payload.layerNames) ? payload.layerNames.filter(Boolean) : [];
                const layerName = payload.layerName || layerNames[0] || '';
                if (!officialTag || !layerName) {
                    return;
                }
                const layerValue = layerNames.length > 1 ? layerNames.join(' | ') : layerName;

                const select = document.getElementById('official_layer_map_' + officialTag);
                const input = document.getElementById('manual_layer_' + officialTag);
                if (select) {
                    let option = Array.from(select.options).find((opt) => opt.value === layerName);
                    if (!option) {
                        option = document.createElement('option');
                        option.value = layerName;
                        option.textContent = layerName + ' (mapped from viewer)';
                        select.appendChild(option);
                    }
                    select.value = layerName;
                }
                if (input) {
                    input.value = layerValue;
                    input.focus();
                }
                return;
            }

            if (event.data.type === 'cad-floor-template-suggestion') {
                const payload = event.data.payload || {};
                const floorContext = payload.floorContext || '';
                const template = payload.template || {};
                if (!floorContext) {
                    return;
                }
                const templates = parseJsonInput(floorTemplatesInput);
                templates[floorContext] = {
                    floor_context: floorContext,
                    floor_label: payload.floorLabel || '',
                    layer_names: Array.isArray(template.layer_names) ? template.layer_names.filter(Boolean) : [],
                    entity_handles: Array.isArray(template.entity_handles) ? template.entity_handles.filter(Boolean) : [],
                    selected_layer: template.selected_layer || '',
                    active_label_key: template.active_label_key || '',
                    source: payload.source || 'viewer',
                    captured_at: new Date().toISOString(),
                };
                writeJsonInput(floorTemplatesInput, templates);
                return;
            }
        });
    })();
</script>
</body>
</html>
