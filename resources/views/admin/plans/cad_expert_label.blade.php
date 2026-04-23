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

        .footer {
            margin-top: 48px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px;
            color: var(--muted);
            font-size: 0.9rem;
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
                <a href="/admin/plan/cad-compliance">CAD Compliance</a>
                <a href="#labels">Expert Labels</a>
                <a href="{{ route('admin.plan.cad-layer-viewer', $submission->id) }}">Layer Viewer</a>
            </nav>
            <div class="actions">
                <a class="btn ghost" href="{{ route('admin.plan.cad-compliance.form') }}">Back to compliance</a>
                <a class="btn primary" href="#labels">Save labels</a>
            </div>
        </header>

        <main>
            <section class="page-header">
                <div>
                    <h1>Expert marking workspace</h1>
                    <p>
                        Map layers and entities to semantic roles so the system learns how to measure plot, footprint,
                        dimensions, and front-side context.
                    </p>
                </div>
                <div class="status-card">
                    <strong>Trainable labels enabled</strong>
                    <p class="muted" style="color: rgba(255, 255, 255, 0.78); margin: 10px 0 0;">
                        Use layer mappings or entity handles to refine compliance accuracy.
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

            <section class="grid two" style="margin-top: 24px;">
                <div class="card">
                    <h3>Submission reference</h3>
                    <div style="margin: 8px 0 14px;">
                        <a class="btn ghost" href="{{ route('admin.plan.cad-layer-viewer', $submission->id) }}">Open 3D layer viewer</a>
                    </div>
                    <div><strong>ID:</strong> {{ $submission->id }}</div>
                    <div><strong>File:</strong> {{ $submission->original_filename }}</div>
                    @if($submission->drawing_pdf_path)
                        <div style="margin-top: 16px;">
                            <a class="btn ghost" href="{{ route('admin.plan.cad-compliance.drawing', ['id' => $submission->id]) }}" target="_blank">Open drawing PDF</a>
                        </div>
                        <iframe class="iframe" src="{{ route('admin.plan.cad-compliance.drawing', ['id' => $submission->id]) }}"></iframe>
                    @endif
                    @if($submission->overlay_pdf_path)
                        <div style="margin-top: 16px;">
                            <a class="btn ghost" href="{{ route('admin.plan.cad-compliance.overlay', ['id' => $submission->id]) }}" target="_blank">Open overlay PDF</a>
                        </div>
                        <iframe class="iframe" style="min-height: 420px;" src="{{ route('admin.plan.cad-compliance.overlay', ['id' => $submission->id]) }}"></iframe>
                    @else
                        <div class="muted" style="margin-top: 10px;">No overlay PDF available yet.</div>
                    @endif
                </div>

                <div class="card" id="labels">
                    <h3>Step 1: Layer mapping</h3>
                    <form action="{{ route('admin.plan.cad-expert-label.store', ['id' => $submission->id]) }}" method="POST">
                        @csrf

                        <div class="field">
                            <label><strong>Plot boundary layer</strong></label>
                            <input type="text" name="plot_layer" value="{{ old('plot_layer', $label->plot_layer) }}" placeholder="e.g. PLOT / BOUNDARY">
                        </div>

                        <div class="field">
                            <label><strong>Building footprint layer</strong></label>
                            <input type="text" name="building_layer" value="{{ old('building_layer', $label->building_layer) }}" placeholder="e.g. WALLS / GF_OUTLINE">
                        </div>

                        <div class="field">
                            <label><strong>Dimension layer</strong></label>
                            <input type="text" name="dimension_layer" value="{{ old('dimension_layer', $label->dimension_layer) }}" placeholder="e.g. DIM">
                        </div>

                        <div class="field">
                            <label><strong>Text/Notes layer</strong></label>
                            <input type="text" name="text_layer" value="{{ old('text_layer', $label->text_layer) }}" placeholder="e.g. TEXT">
                        </div>

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

                        <h3 style="margin: 18px 0 6px;">Step 2: Entity handles</h3>
                        <div class="muted" style="margin-bottom: 10px;">Pick exact closed polylines if layer names are inconsistent.</div>

                        <div class="field">
                            <label><strong>Plot polyline handle</strong></label>
                            <input type="text" name="plot_entity_handle" value="{{ old('plot_entity_handle', $label->plot_entity_handle) }}" placeholder="Handle from candidates table below">
                        </div>

                        <div class="field">
                            <label><strong>Building footprint polyline handle</strong></label>
                            <input type="text" name="building_entity_handle" value="{{ old('building_entity_handle', $label->building_entity_handle) }}" placeholder="Handle from candidates table below">
                        </div>

                        <button class="btn primary" type="submit">Save labels</button>
                    </form>

                    <div style="margin-top: 22px;">
                        <h4>Layer summary</h4>
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
                        <div class="muted">Use these handles for entity-level labeling if needed.</div>
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
</body>
</html>
