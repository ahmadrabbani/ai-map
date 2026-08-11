<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Plan Checker')</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Bootstrap 5 CSS --}}
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous"
    >
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        body {
            background: #f3f4f6;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .app-navbar {
            background: linear-gradient(135deg, #0f172a, #1e293b);
        }

        .app-navbar .navbar-brand {
            font-weight: 600;
            letter-spacing: 0.03em;
        }

        .app-navbar .navbar-brand span {
            color: #38bdf8;
        }

        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.6rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .page-header .subtitle {
            color: #64748b;
            font-size: 0.9rem;
        }

        .card {
            border-radius: 0.75rem;
            border: 1px solid rgba(148, 163, 184, 0.25);
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.05);
        }

        .card-header {
            border-bottom: 1px solid rgba(148, 163, 184, 0.25);
            font-weight: 600;
            font-size: 0.95rem;
            background: #ffffff;
        }

        .badge-pill-soft {
            border-radius: 999px;
            padding: 0.25rem 0.6rem;
            font-size: 0.75rem;
        }

        .badge-soft-success {
            background: rgba(34, 197, 94, 0.08);
            color: #16a34a;
        }

        .badge-soft-danger {
            background: rgba(239, 68, 68, 0.08);
            color: #dc2626;
        }

        .badge-soft-secondary {
            background: rgba(148, 163, 184, 0.16);
            color: #475569;
        }

        .attr-label-badge {
            border-radius: 999px;
            background: #e5e7eb;
            color: #374151;
            padding: 0.2rem 0.6rem;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .attr-label-badge i {
            font-size: 0.7rem;
        }

        footer.app-footer {
            padding: 1rem 0;
            font-size: 0.8rem;
            color: #9ca3af;
        }
    </style>

    @yield('header_styles')
    @vite(['resources/js/app.js'])
</head>
<body>
@php
    $isAdEpermitContext = request()->is('admin/plan/ad-epermit*') || request()->routeIs('admin.plan.bp.ad.*');
@endphp

<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
    <div class="container-fluid container-xl">
        <a class="navbar-brand" href="{{ $isAdEpermitContext ? route('admin.plan.bp.ad.index') : url('/admin/plan/building-plan-applications') }}">
            LDA <span>{{ $isAdEpermitContext ? 'AD ePermit' : 'Plan Tools' }}</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#mainNavbar" aria-controls="mainNavbar"
                aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                @if($isAdEpermitContext)
                    <li class="nav-item"><a class="nav-link {{ request()->routeIs('admin.plan.bp.ad.index') ? 'active' : '' }}" href="{{ route('admin.plan.bp.ad.index') }}">Home / Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->query('status') === 'assigned' ? 'active' : '' }}" href="{{ route('admin.plan.bp.ad.index', ['status' => 'assigned']) }}">Building Plan Cases</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->query('status') === 'under_process' ? 'active' : '' }}" href="{{ route('admin.plan.bp.ad.index', ['status' => 'under_process']) }}">Under Process Cases</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->query('status') === 'observation' ? 'active' : '' }}" href="{{ route('admin.plan.bp.ad.index', ['status' => 'observation']) }}">Observation Cases</a></li>
                    <li class="nav-item"><a class="nav-link {{ request()->query('status') === 'marked_to_dfps' ? 'active' : '' }}" href="{{ route('admin.plan.bp.ad.index', ['status' => 'marked_to_dfps']) }}">Marked to DFPS</a></li>
                @else
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('admin/plan/check-setback') ? 'active' : '' }}"
                           href="{{ url('/admin/plan/check-setback') }}">
                            Setback Checker
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('admin/plan/cad-compliance*') ? 'active' : '' }}"
                           href="{{ url('/admin/plan/cad-compliance') }}">
                            CAD Compliance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('admin/plan/approval-wizard*') ? 'active' : '' }}"
                           href="{{ url('/admin/plan/approval-wizard') }}">
                            CAD Approval Wizard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('admin/plan/building-plan-applications*') ? 'active' : '' }}"
                           href="{{ route('admin.plan.bp.index') }}">
                            Building Plan AI
                        </a>
                    </li>
                @endif
            </ul>
            @if($isAdEpermitContext && auth()->check())
                <a class="btn btn-outline-light btn-sm me-2" href="{{ route('admin.plan.bp.ad.index') }}">Back to Dashboard</a>
            @endif
        </div>
    </div>
</nav>

<main class="py-4">
    <div class="container-xl">
        @yield('content')
    </div>
</main>

<footer class="app-footer">
    <div class="container-xl text-center">
        <span>Building Plan Tools • {{ date('Y') }}</span>
    </div>
</footer>

{{-- Bootstrap JS --}}
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
    crossorigin="anonymous"
></script>

@yield('footer_scripts')
@stack('footer_scripts_inline')
</body>
</html>
