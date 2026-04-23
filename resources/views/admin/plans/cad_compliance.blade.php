<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CAD Compliance Check</title>
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
            grid-template-columns: minmax(0, 1fr) minmax(0, 0.55fr);
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
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        }

        .card {
            background: var(--surface);
            border-radius: var(--radius-md);
            padding: 20px;
            border: 1px solid var(--line);
            box-shadow: 0 16px 30px rgba(16, 20, 24, 0.08);
        }

        .card h2,
        .card h3 {
            margin: 0 0 12px;
            font-family: "Space Grotesk", "Manrope", sans-serif;
        }

        .field {
            display: grid;
            gap: 8px;
            margin-bottom: 14px;
        }

        .field input[type="text"],
        .field input[type="file"] {
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
            background: #fff;
        }

        .muted {
            color: var(--muted);
            font-size: 0.95rem;
        }

        .error {
            background: #fff2f2;
            border: 1px solid #f0c6c6;
            color: #7b1b1b;
            padding: 12px;
            border-radius: var(--radius-sm);
        }

        .ok {
            color: #0f6b5f;
            font-weight: 600;
        }

        .bad {
            color: #b21c1c;
            font-weight: 600;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        .table th,
        .table td {
            text-align: left;
            padding: 10px 6px;
            border-bottom: 1px solid rgba(16, 20, 24, 0.08);
            vertical-align: top;
        }

        .iframe {
            width: 100%;
            border: 1px solid rgba(16, 20, 24, 0.08);
            border-radius: var(--radius-sm);
            margin-top: 12px;
            min-height: 280px;
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
                <a href="/admin/plan/cad-expert-label">Expert Labels</a>
                <a href="#results">Results</a>
            </nav>
            <div class="actions">
                <a class="btn ghost" href="/">Back to home</a>
                <a class="btn primary" href="#upload">New submission</a>
            </div>
        </header>

        <main>
            @php
                $errors = $errors ?? (session()->get('errors') ?? new \Illuminate\Support\ViewErrorBag());
            @endphp

            <section class="page-header">
                <div>
                    <h1>CAD compliance workspace</h1>
                    <p>
                        Review DWG submissions, run rule checks, and deliver audit-ready overlays without leaving the
                        dashboard.
                    </p>
                </div>
                <div class="status-card">
                    <strong>DWG → DXF → Rule Check</strong>
                    <p class="muted" style="color: rgba(255, 255, 255, 0.78); margin: 10px 0 0;">
                        Upload a plan to generate compliance overlays and system vs. expert comparisons.
                    </p>
                </div>
            </section>

            @if(session('status'))
                <div class="card ok" style="margin-bottom: 18px;">{{ session('status') }}</div>
            @endif

            <section id="upload" class="card">
                <h2>Submit a plan for review</h2>
                <form action="{{ route('admin.plan.cad-compliance.submit') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="field">
                        <label><strong>DWG File</strong></label>
                        <input type="file" name="dwg_file" accept=".dwg" required>
                        @error('dwg_file')<div class="bad">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label><strong>Ruleset</strong></label>
                        <input type="text" name="ruleset_key" value="{{ old('ruleset_key', $submission?->ruleset_key ?? '5_marla_residential') }}">
                        <div class="muted">Currently uses <code>rules/5MRulesJSON.json</code> (extendable).</div>
                    </div>
                    <button class="btn primary" type="submit">Run compliance</button>
                </form>
            </section>

            @if(!empty($errorMessage))
                <div class="error" style="margin-top: 18px;"><strong>Error:</strong> {{ $errorMessage }}</div>
            @endif

            @if($submission)
                <section id="results" class="grid two" style="margin-top: 24px;">
                    <div class="card">
                        <h3>Submission</h3>
                        <div><strong>Original:</strong> {{ $submission->original_filename }}</div>
                        <div><strong>ID:</strong> {{ $submission->id }}</div>
                        <div style="margin-top: 10px;">
                            <a class="btn ghost" href="{{ route('admin.plan.cad-expert-label.edit', ['id' => $submission->id]) }}">Expert marking</a>
                        </div>
                        @if($submission->drawing_pdf_path)
                            <div style="margin-top: 18px;">
                                <strong>Drawing PDF:</strong>
                                <a class="btn ghost" href="{{ route('admin.plan.cad-compliance.drawing', ['id' => $submission->id]) }}" target="_blank">Open</a>
                            </div>
                            <iframe class="iframe" src="{{ route('admin.plan.cad-compliance.drawing', ['id' => $submission->id]) }}"></iframe>
                        @endif
                        @if($submission->overlay_pdf_path)
                            <div style="margin-top: 18px;">
                                <strong>Overlay PDF:</strong>
                                <a class="btn ghost" href="{{ route('admin.plan.cad-compliance.overlay', ['id' => $submission->id]) }}" target="_blank">Open</a>
                            </div>
                            <iframe class="iframe" style="min-height: 420px;" src="{{ route('admin.plan.cad-compliance.overlay', ['id' => $submission->id]) }}"></iframe>
                        @else
                            <div class="muted" style="margin-top: 10px;">No overlay PDF generated yet.</div>
                        @endif
                    </div>

                    <div class="card">
                        <h3>System Results</h3>
                        @if($results_system && count($results_system))
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>Rule</th>
                                    <th>Required</th>
                                    <th>Measured</th>
                                    <th>Status</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($results_system as $r)
                                    <tr>
                                        <td>
                                            <div><strong>{{ $r->rule_id }}</strong></div>
                                            <div class="muted">{{ $r->title }}</div>
                                            @if($r->details)
                                                <div class="muted">{{ $r->details }}</div>
                                            @endif
                                        </td>
                                        <td>{{ $r->operator }} {{ $r->required_value }} {{ $r->unit }}</td>
                                        <td>{{ $r->measured_value ?? '-' }} {{ $r->unit }}</td>
                                        <td>
                                            @if($r->is_compliant === null)
                                                <span class="muted">Manual</span>
                                            @elseif($r->is_compliant)
                                                <span class="ok">PASS</span>
                                            @else
                                                <span class="bad">FAIL</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @else
                            <div class="muted">No system results yet.</div>
                        @endif

                        <h3 style="margin-top: 22px;">Expert Results</h3>
                        @if($results_expert && count($results_expert))
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>Rule</th>
                                    <th>Required</th>
                                    <th>Measured</th>
                                    <th>Status</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($results_expert as $r)
                                    <tr>
                                        <td>
                                            <div><strong>{{ $r->rule_id }}</strong></div>
                                            <div class="muted">{{ $r->title }}</div>
                                            @if($r->details)
                                                <div class="muted">{{ $r->details }}</div>
                                            @endif
                                        </td>
                                        <td>{{ $r->operator }} {{ $r->required_value }} {{ $r->unit }}</td>
                                        <td>{{ $r->measured_value ?? '-' }} {{ $r->unit }}</td>
                                        <td>
                                            @if($r->is_compliant === null)
                                                <span class="muted">Manual</span>
                                            @elseif($r->is_compliant)
                                                <span class="ok">PASS</span>
                                            @else
                                                <span class="bad">FAIL</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @else
                            <div class="muted">No expert results yet.</div>
                        @endif
                    </div>
                </section>
            @endif
        </main>

        <footer class="footer">
            <span>Map AI Verification</span>
            <span>Compliance and zoning intelligence for modern planning teams.</span>
        </footer>
    </div>
</div>
</body>
</html>
