<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Building Plan AI Portal')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root{--navy:#022a5c;--navy2:#05224c;--teal:#138a88;--gold:#f0c55d;--line:#d9e2ec;--muted:#5e6c82;}
        body{background:#f8fbff;color:#10284f;font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;}
        .topbar{background:linear-gradient(90deg,var(--navy),var(--navy2));}
        .container-main{max-width:1540px;}
        .logo-mark{width:42px;height:42px;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.25);}
        .logo-mark i{color:var(--gold);width:20px;height:20px;}
        .brand-title{font-weight:700;color:#fff;font-size:1rem;line-height:1.05;}
        .brand-sub{color:#d5e2f6;font-size:.8rem;}
        .nav-link-top{color:#fff;text-decoration:none;font-weight:600;padding:.55rem .35rem;border-bottom:3px solid transparent;}
        .nav-link-top.active,.nav-link-top:hover{border-color:var(--gold);}
        .btn-login{border:1px solid #8eaad0;color:#fff;border-radius:10px;padding:.4rem 1rem;font-weight:600;}
        .btn-register{background:var(--gold);color:#12223e;border-radius:10px;padding:.4rem 1rem;font-weight:700;border:1px solid #e3b84e;}
        .auth-shell{min-height:calc(100vh - 200px);}
        .auth-card{border:1px solid var(--line);border-radius:16px;box-shadow:0 15px 35px rgba(9,42,90,.08)}
        .auth-card .card-header{background:#fff;border-bottom:1px solid var(--line);font-weight:700;color:#0d2d62}
        .helper{font-size:.85rem;color:var(--muted)}
        .footer{background:linear-gradient(90deg,#052a5a,#041f46);color:#e6eefb;}
        .footer-mini{font-size:.82rem;color:#c2d2e9;}
    </style>
    @yield('head')
</head>
<body>
<header class="topbar py-2">
    <div class="container-main container-fluid d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <div class="logo-mark"><i data-lucide="landmark"></i></div>
            <div>
                <div class="brand-title">Lahore Development Authority</div>
                <div class="brand-sub">Building Better Lahore</div>
            </div>
        </div>
        <nav class="d-flex align-items-center gap-3">
            <a class="nav-link-top {{ request()->routeIs('public.bp.login') || request()->routeIs('public.bp.register') ? 'active' : '' }}" href="{{ url('/') }}">Home</a>
            <a class="nav-link-top" href="{{ session('bp_applicant_id') ? route('public.bp.dashboard') : route('public.bp.login') }}">Track Application</a>
        </nav>
        <div class="d-flex gap-2">
            @if(session('bp_applicant_id'))
                <a class="btn btn-login" href="{{ route('public.bp.dashboard') }}"><i data-lucide="user"></i> Dashboard</a>
                <form method="POST" action="{{ route('public.bp.logout') }}">@csrf<button class="btn btn-register" type="submit"><i data-lucide="log-out"></i> Logout</button></form>
            @else
                <a class="btn btn-login" href="{{ route('public.bp.login') }}"><i data-lucide="user"></i> Login</a>
                <a class="btn btn-register" href="{{ route('public.bp.register') }}"><i data-lucide="user-plus"></i> Register</a>
            @endif
        </div>
    </div>
</header>

<main class="py-4 auth-shell">
    <div class="container container-main">
        @if(session('status'))
            <div class="alert alert-info">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @yield('content')
    </div>
</main>

<footer class="footer py-3 mt-4">
    <div class="container-main container-fluid d-flex justify-content-between flex-wrap gap-2 footer-mini">
        <span>© {{ date('Y') }} Lahore Development Authority. All rights reserved.</span>
        <span>Privacy Policy | Terms of Use | Accessibility</span>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
@yield('scripts')
</body>
</html>
