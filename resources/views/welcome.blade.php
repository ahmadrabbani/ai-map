<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Map AI Verification') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,600,700|manrope:400,500,600" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

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

            .hero {
                display: grid;
                grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
                gap: 36px;
                margin-top: 56px;
                align-items: center;
            }

            .hero h1 {
                font-family: "Space Grotesk", "Manrope", sans-serif;
                font-size: clamp(2.4rem, 3.2vw, 3.6rem);
                line-height: 1.05;
                margin: 0 0 18px;
            }

            .hero p {
                margin: 0 0 24px;
                color: var(--muted);
                font-size: 1.05rem;
            }

            .hero-cta {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                margin-bottom: 24px;
            }

            .hero-meta {
                display: flex;
                gap: 18px;
                flex-wrap: wrap;
                color: var(--muted);
                font-size: 0.9rem;
            }

            .meta-chip {
                padding: 8px 12px;
                background: rgba(255, 255, 255, 0.75);
                border-radius: 999px;
                border: 1px solid var(--line);
            }

            .hero-panel {
                background: var(--surface);
                border-radius: var(--radius-lg);
                border: 1px solid var(--line);
                padding: 28px;
                box-shadow: var(--shadow);
                position: relative;
            }

            .panel-badge {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-size: 0.85rem;
                padding: 6px 12px;
                border-radius: 999px;
                background: rgba(15, 107, 95, 0.1);
                color: var(--accent);
                font-weight: 600;
            }

            .panel-title {
                margin: 18px 0 14px;
                font-size: 1.2rem;
                font-weight: 600;
            }

            .panel-grid {
                display: grid;
                gap: 14px;
            }

            .panel-item {
                display: grid;
                gap: 6px;
                padding: 14px;
                border-radius: var(--radius-md);
                background: #f8f5f1;
                border: 1px solid rgba(16, 20, 24, 0.08);
            }

            .panel-item strong {
                font-size: 1.05rem;
            }

            .section {
                margin-top: 72px;
            }

            .section-title {
                font-family: "Space Grotesk", "Manrope", sans-serif;
                font-size: 1.7rem;
                margin: 0 0 10px;
            }

            .section-subtitle {
                color: var(--muted);
                margin: 0 0 28px;
            }

            .card-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 18px;
            }

            .card {
                background: var(--surface);
                border-radius: var(--radius-md);
                padding: 20px;
                border: 1px solid var(--line);
                box-shadow: 0 16px 30px rgba(16, 20, 24, 0.08);
            }

            .card h3 {
                margin: 0 0 8px;
                font-size: 1.1rem;
            }

            .card p {
                margin: 0;
                color: var(--muted);
            }

            .workflow {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 16px;
            }

            .step {
                border-radius: var(--radius-sm);
                background: #fff;
                border: 1px solid var(--line);
                padding: 16px 18px;
            }

            .step span {
                font-size: 0.85rem;
                color: var(--accent);
                font-weight: 600;
            }

            .metrics {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 16px;
            }

            .metric {
                background: #0f2f2b;
                color: #fff;
                border-radius: var(--radius-md);
                padding: 18px;
            }

            .metric strong {
                font-size: 1.6rem;
                display: block;
            }

            .cta {
                margin-top: 80px;
                padding: 32px;
                background: linear-gradient(120deg, rgba(15, 107, 95, 0.1), rgba(242, 139, 45, 0.12));
                border-radius: var(--radius-lg);
                border: 1px solid var(--line);
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 18px;
            }

            .cta h2 {
                margin: 0 0 8px;
                font-size: 1.6rem;
                font-family: "Space Grotesk", "Manrope", sans-serif;
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

                .hero {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 600px) {
                .shell {
                    padding: 24px 18px 72px;
                }

                .topbar {
                    padding: 14px;
                }

                .hero-panel {
                    padding: 22px;
                }
            }
        </style>
    </head>
    <body>
        <div class="page">
            <div class="shell">
                <header class="topbar">
                    <div class="brand">
                        <div class="brand-mark">M</div>
                        <span>Map AI Verification</span>
                    </div>
                    <nav class="nav">
                        <a href="#platform">Platform</a>
                        <a href="#workflow">Workflow</a>
                        <a href="#capabilities">Capabilities</a>
                        <a href="#compliance">Compliance</a>
                        <a href="/admin/plan/cad-compliance">CAD Compliance</a>
                    </nav>
                    <div class="actions">
                        <a class="btn ghost" href="#contact">Contact</a>
                        <a class="btn primary" href="/admin/plan/cad-compliance">Start Review</a>
                    </div>
                </header>

                <main>
                    <section class="hero">
                        <div>
                            <h1>Confident zoning verification for every CAD submission.</h1>
                            <p>
                                Map AI Verification turns zoning, setback, and parcel rules into a real-time review
                                experience. Upload DWG files, trace compliance signals, and export audit-ready reports in
                                minutes.
                            </p>
                            <div class="hero-cta">
                                <a class="btn primary" href="/admin/plan/cad-compliance">Run a compliance check</a>
                                <a class="btn ghost" href="#platform">Explore platform</a>
                            </div>
                            <div class="hero-meta">
                                <div class="meta-chip">Rule libraries synced</div>
                                <div class="meta-chip">DWG to insight in seconds</div>
                                <div class="meta-chip">Audit trail built in</div>
                            </div>
                        </div>
                        <div class="hero-panel">
                            <div class="panel-badge">Live project overview</div>
                            <div class="panel-title">Downtown rezoning review</div>
                            <div class="panel-grid">
                                <div class="panel-item">
                                    <strong>96%</strong>
                                    <span>Compliance confidence</span>
                                </div>
                                <div class="panel-item">
                                    <strong>12</strong>
                                    <span>Critical checks flagged</span>
                                </div>
                                <div class="panel-item">
                                    <strong>4 min</strong>
                                    <span>Average turnaround</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="section" id="platform">
                        <h2 class="section-title">A professional command center for plan review</h2>
                        <p class="section-subtitle">
                            Designed for planning teams, consultants, and reviewers who need a clean workflow from
                            submission to sign-off.
                        </p>
                        <div class="card-grid">
                            <div class="card">
                                <h3>Unified map intelligence</h3>
                                <p>Overlay zoning layers, parcel boundaries, and annotations without leaving the viewer.</p>
                            </div>
                            <div class="card">
                                <h3>Clear issue triage</h3>
                                <p>Instantly rank conflicts by severity and assign follow-up actions to reviewers.</p>
                            </div>
                            <div class="card">
                                <h3>Traceable decisions</h3>
                                <p>Generate compliance narratives and exportable review logs for approvals.</p>
                            </div>
                        </div>
                    </section>

                    <section class="section" id="workflow">
                        <h2 class="section-title">Workflow that keeps reviews on schedule</h2>
                        <p class="section-subtitle">Move from upload to verified decision in four streamlined steps.</p>
                        <div class="workflow">
                            <div class="step">
                                <span>Step 01</span>
                                <h3>Upload</h3>
                                <p>Drop DWG, DXF, or PDF submissions into a secure project workspace.</p>
                            </div>
                            <div class="step">
                                <span>Step 02</span>
                                <h3>Analyze</h3>
                                <p>AI locates setbacks, height limits, and frontage rules in real time.</p>
                            </div>
                            <div class="step">
                                <span>Step 03</span>
                                <h3>Collaborate</h3>
                                <p>Share annotations and review states with consultants and agencies.</p>
                            </div>
                            <div class="step">
                                <span>Step 04</span>
                                <h3>Deliver</h3>
                                <p>Export compliance packets, signed approvals, and archival records.</p>
                            </div>
                        </div>
                    </section>

                    <section class="section" id="capabilities">
                        <h2 class="section-title">Verification capabilities built for zoning teams</h2>
                        <p class="section-subtitle">Everything you need to confirm, explain, and defend each decision.</p>
                        <div class="card-grid">
                            <div class="card">
                                <h3>Setback intelligence</h3>
                                <p>Automate setback checks with visual overlays and ruler-grade accuracy.</p>
                            </div>
                            <div class="card">
                                <h3>Layer-aware checks</h3>
                                <p>Interpret CAD layers, labels, and callouts without manual prep work.</p>
                            </div>
                            <div class="card">
                                <h3>Compliance notes</h3>
                                <p>Convert findings into plain-language statements for permits and reports.</p>
                            </div>
                        </div>
                    </section>

                    <section class="section" id="compliance">
                        <h2 class="section-title">Metrics your stakeholders trust</h2>
                        <p class="section-subtitle">Show leadership how reviews are improving with consistent evidence.</p>
                        <div class="metrics">
                            <div class="metric">
                                <strong>48%</strong>
                                <span>Faster plan turnaround</span>
                            </div>
                            <div class="metric">
                                <strong>0.2m</strong>
                                <span>Average setback variance tolerance</span>
                            </div>
                            <div class="metric">
                                <strong>24/7</strong>
                                <span>Automated rule monitoring</span>
                            </div>
                        </div>
                    </section>

                    <section class="cta" id="contact">
                        <div>
                            <h2>Ready to streamline your next review?</h2>
                            <p>Launch a CAD compliance session or connect with the team for onboarding.</p>
                        </div>
                        <div class="hero-cta">
                            <a class="btn primary" href="/admin/plan/cad-compliance">Start a session</a>
                            <a class="btn ghost" href="mailto:hello@map-ai-verification.local">Request a walkthrough</a>
                        </div>
                    </section>
                </main>

                <footer class="footer">
                    <span>Map AI Verification</span>
                    <span>Secure zoning intelligence for modern planning teams.</span>
                </footer>
            </div>
        </div>
    </body>
</html>
