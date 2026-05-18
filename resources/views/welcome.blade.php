<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LDA Plan Approval Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
        :root{--navy:#022a5c;--navy2:#05224c;--teal:#138a88;--text:#10284f;--muted:#56657d;--gold:#f0c55d;--line:#d9e2ec;}
        body{background:#f8fbff;color:var(--text);font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;}
        .topbar{background:linear-gradient(90deg,var(--navy),var(--navy2));}
        .container-main{max-width:1540px;}
        .logo-mark{width:56px;height:56px;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.25);}
        .logo-mark i{color:var(--gold);width:28px;height:28px;}
        .brand-title{font-weight:700;color:#fff;font-size:.92rem;line-height:1.1;}
        .brand-sub{color:#d5e2f6;font-size:.92rem;}
        .nav-link-top{color:#fff;text-decoration:none;font-weight:600;padding:.55rem .35rem;border-bottom:3px solid transparent;}
        .nav-link-top.active,.nav-link-top:hover{border-color:var(--gold);}
        .btn-login{border:1px solid #8eaad0;color:#fff;border-radius:10px;padding:.52rem 1.45rem;font-weight:600;}
        .btn-register{background:var(--gold);color:#12223e;border-radius:10px;padding:.52rem 1.45rem;font-weight:700;border:1px solid #e3b84e;}

        .hero{background:#fff;border:1px solid var(--line);border-top:none;}
        .pill{display:inline-block;background:#eaf8f7;color:#0e8b87;border:1px solid #cfeeee;border-radius:10px;padding:.43rem .95rem;font-weight:700;letter-spacing:.02em;}
        .hero-title{font-size:3.7rem;line-height:1.01;font-weight:800;color:#092b5a;}
        .hero-copy{font-size:.92rem;color:#5e6c82;line-height:1.35;}
        .cta-primary{background:#062d62;color:#fff;border-radius:12px;padding:.9rem 1.65rem;font-size:1rem;font-weight:700;border:none;}
        .cta-outline{border:2px solid #355d93;color:#153560;border-radius:12px;padding:.82rem 1.65rem;font-size:1rem;font-weight:700;background:#fff;}
        .mini-points{font-size:.95rem;color:#51617a;}
        .mini-points i{color:#0f8f88;width:18px;height:18px;vertical-align:-3px;}
        .hero-right-wrap{position:relative;min-height:560px;}
        .hero-build{position:absolute;left:0;bottom:18px;width:55%;max-width:440px;z-index:1;}
        .hero-dashboard{position:absolute;right:0;top:0;width:74%;max-width:820px;z-index:2;border-radius:22px;box-shadow:0 16px 32px rgba(13,39,79,.14);}

        .steps-card,.feature-card,.benefit-strip,.important-strip{border:1px solid var(--line);border-radius:14px;background:#fff;}
        .step-icon{width:70px;height:70px;border-radius:50%;display:grid;place-items:center;}
        .step-no{color:#0b8a87;font-weight:800;font-size:1rem;}
        .step-title{font-size:.92rem;font-weight:700;color:#0d2758;line-height:1.12;}
        .step-text{font-size:.95rem;color:#51617a;line-height:1.24;}

        .section-title{font-size:1.65rem;font-weight:800;color:#102c5a;}
        .feature-title{font-size:.82rem;line-height:1.08;font-weight:700;color:#112b59;}
        .feature-text{font-size:.92rem;color:#51617a;line-height:1.24;}

        .benefit-strip{background:#f4fbfa;}
        .benefit-item{border-right:1px solid #b9d8d6;}
        .benefit-item:last-child{border-right:none;}
        .benefit-title{font-weight:800;color:#107e7c;font-size:1.05rem;}
        .benefit-text{font-size:.9rem;color:#4c5c73;line-height:1.2;}

        .important-strip{background:#fffaf0;border-color:#f2d48f;}
        .important-title{font-size:1.05rem;font-weight:800;color:#31240f;}
        .important-text{font-size:.9rem;color:#5c4e34;}

        .footer{background:linear-gradient(90deg,#052a5a,#041f46);color:#e6eefb;}
        .footer-title{font-size:.92rem;font-weight:700;}
        .footer-sub{font-size:.9rem;color:#c2d2e9;}
        .footer-head{font-size:.86rem;font-weight:700;}
        .footer-link{font-size:.9rem;color:#dbe7f8;text-decoration:none;display:block;margin:.22rem 0;}
        .footer-mini{font-size:.9rem;color:#c2d2e9;}
        .social-round{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:#fff;color:#133765;font-size:1rem;}
        .social-round i{width:16px;height:16px;}
        .store-btn{border:1px solid #6f8fb8;border-radius:10px;padding:.35rem .7rem;display:inline-flex;align-items:center;gap:.45rem;color:#fff;text-decoration:none;font-size:.86rem;}
        .store-btn i{width:16px;height:16px;}
        .copyright{font-size:.82rem;color:#c1d0e6;}

        .icon-circle{display:grid;place-items:center;border-radius:999px;background:#e9f7f6;color:#138a88;}
        .icon-sm{width:34px;height:34px;}
        .icon-lg{width:66px;height:66px;}
        .icon-circle i{width:24px;height:24px;stroke-width:2.1;}
        .icon-sm i{width:17px;height:17px;}

        @media (max-width:1200px){
            .hero-title{font-size:3.2rem}.hero-copy{font-size:1.25rem}.step-title{font-size:1.35rem}.step-text,.feature-text{font-size:1rem}.feature-title{font-size:1.3rem}
            .brand-title{font-size:1.35rem}.brand-sub{font-size:.95rem}
            .hero-right-wrap{min-height:410px}
        }
        @media (max-width:991px){
            .hero-right-wrap{min-height:340px}.hero-build{width:52%}.hero-dashboard{width:76%}
            .benefit-item{border-right:none;border-bottom:1px solid #b9d8d6}.benefit-item:last-child{border-bottom:none}
        }
    </style>
</head>
<body>
<header class="topbar py-3">
    <div class="container-main container-fluid d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="logo-mark"><i data-lucide="landmark"></i></div>
            <div>
                <div class="brand-title">Lahore Development Authority</div>
                <div class="brand-sub">Building Better Lahore</div>
            </div>
        </div>
        <nav class="d-flex align-items-center gap-4">
            <a class="nav-link-top active" href="{{ url('/') }}">Home</a>
            <a class="nav-link-top" href="{{ session('bp_applicant_id') ? route('public.bp.applications.create') : route('public.bp.login') }}">How It Works</a>
            <a class="nav-link-top" href="{{ session('bp_applicant_id') ? route('public.bp.dashboard') : route('public.bp.login') }}">Track Application</a>
            <a class="nav-link-top" href="{{ session('bp_applicant_id') ? route('public.bp.dashboard') : route('public.bp.login') }}">Help</a>
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

<main class="hero pb-4">
    <div class="container-main container-fluid pt-4">
        <div class="row g-4 align-items-start">
            <div class="col-xl-6 pt-2">
                <span class="pill">LDA PLAN APPROVAL PORTAL</span>
                <h1 class="hero-title mt-3 mb-3">AI-Assisted<br>Building Plan Approval</h1>
                <p class="hero-copy mb-4">Register with your CNIC, upload building plans and required documents, get AI-powered preliminary scrutiny, and track your application through the official approval workflow.</p>
                <div class="d-flex flex-wrap gap-3 mb-4">
                    <a class="cta-primary text-decoration-none" href="{{ session('bp_applicant_id') ? route('public.bp.applications.create') : route('public.bp.register') }}"><i data-lucide="user-plus" class="me-2"></i>Start Application</a>
                    <a class="cta-outline text-decoration-none" href="{{ session('bp_applicant_id') ? route('public.bp.dashboard') : route('public.bp.login') }}"><i data-lucide="user" class="me-2"></i>Applicant Login</a>
                </div>
                <div class="d-flex gap-4 flex-wrap mini-points">
                    <span><i data-lucide="shield-check"></i> Secure & Official</span>
                    <span><i data-lucide="clock-3"></i> Faster Decisions</span>
                    <span><i data-lucide="circle-check"></i> Transparent Process</span>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="hero-right-wrap">
                    <img class="hero-build" src="{{ asset('assets/map-approval-home/04_hero_building_illustration.png') }}" alt="Building Illustration">
                    <img class="hero-dashboard" src="{{ asset('assets/map-approval-home/05_hero_application_dashboard.png') }}" alt="Application Overview">
                </div>
            </div>
        </div>

        <section class="mt-3 steps-row">
            <div class="row g-3 align-items-center">
                @php
                    $steps = [
                        ['no'=>'01','title'=>'Register with CNIC','text'=>'Create your account using CNIC and secure login.','icon'=>'id-card'],
                        ['no'=>'02','title'=>'Upload Plan & Documents','text'=>'Upload building plans, maps and required documents.','icon'=>'cloud-upload'],
                        ['no'=>'03','title'=>'AI Validation & Report','text'=>'AI reviews your submission and generates a scrutiny report.','icon'=>'cpu'],
                        ['no'=>'04','title'=>'Track Routing & Decision','text'=>'Track application routing and final decision in real time.','icon'=>'route'],
                    ];
                @endphp
                @foreach($steps as $s)
                    <div class="col-lg-3 col-md-6">
                        <div class="steps-card p-3 h-100 d-flex gap-3 align-items-center">
                            <div class="step-icon"><span class="icon-circle icon-lg"><i data-lucide="{{ $s['icon'] }}"></i></span></div>
                            <div>
                                <div class="step-no">{{ $s['no'] }}</div>
                                <div class="step-title">{{ $s['title'] }}</div>
                                <div class="step-text">{{ $s['text'] }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mt-4">
            <h2 class="section-title mb-3">Powerful Features for a Smarter Approval Experience</h2>
            @php
                $features = [
                    ['title'=>'CNIC-Based<br>Secure Login','text'=>'Verify your identity with CNIC for a secure and trusted experience.','icon'=>'badge-check'],
                    ['title'=>'Document<br>Validation','text'=>'Automatic checks for mandatory documents and completeness.','icon'=>'file-check-2'],
                    ['title'=>'CAD / Map<br>Upload','text'=>'Upload CAD drawings, maps, plans in PDF, DWG and other formats.','icon'=>'map'],
                    ['title'=>'AI Scrutiny<br>Report','text'=>'Instant AI analysis with compliance score and detailed observations.','icon'=>'brain-circuit'],
                    ['title'=>'QR-Linked<br>Tracking','text'=>'QR-linked reports and real-time tracking of application status.','icon'=>'qr-code'],
                    ['title'=>'Directorate<br>Workflow','text'=>'Seamless routing to the concerned directorate for review & decision.','icon'=>'landmark'],
                ];
            @endphp
            <div class="row g-3">
                @foreach($features as $f)
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <div class="feature-card p-3 h-100">
                            <span class="icon-circle icon-lg mb-2"><i data-lucide="{{ $f['icon'] }}"></i></span>
                            <div class="feature-title mb-1">{!! $f['title'] !!}</div>
                            <div class="feature-text">{{ $f['text'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="benefit-strip mt-3 p-3">
            <div class="row g-0">
                <div class="col-lg-3 benefit-item p-3 d-flex gap-3"><span class="icon-circle icon-lg"><i data-lucide="rocket"></i></span><div><div class="benefit-title">Fast Digital Submission</div><div class="benefit-text">Submit plans and documents online within minutes.</div></div></div>
                <div class="col-lg-3 benefit-item p-3 d-flex gap-3"><span class="icon-circle icon-lg"><i data-lucide="eye"></i></span><div><div class="benefit-title">Transparent Tracking</div><div class="benefit-text">Track every step of your application with full visibility.</div></div></div>
                <div class="col-lg-3 benefit-item p-3 d-flex gap-3"><span class="icon-circle icon-lg"><i data-lucide="sparkles"></i></span><div><div class="benefit-title">AI Preliminary Scrutiny</div><div class="benefit-text">AI-powered report helps identify issues early to save time.</div></div></div>
                <div class="col-lg-3 p-3 d-flex gap-3"><span class="icon-circle icon-lg"><i data-lucide="lock"></i></span><div><div class="benefit-title">Secure Records</div><div class="benefit-text">All data is encrypted and stored securely with audit trail.</div></div></div>
            </div>
        </section>

        <section class="important-strip mt-3 p-3 d-flex gap-3 align-items-start">
            <i data-lucide="info" style="width:32px;height:32px;color:#a97819;"></i>
            <div>
                <div class="important-title">Important Information</div>
                <div class="important-text">The AI-generated scrutiny report is for preliminary validation and advisory purposes only. Final approval, rejection, or objection shall remain subject to review and decision by the concerned authority/directorate.</div>
            </div>
        </section>
    </div>
</main>

<footer class="footer pt-4 pb-2">
    <div class="container-main container-fluid">
        <div class="row g-4">
            <div class="col-xl-3">
                <div class="d-flex align-items-center gap-2 mb-2"><div class="logo-mark" style="width:44px;height:44px;"><i data-lucide="landmark"></i></div><div class="footer-title">Lahore Development Authority</div></div>
                <div class="footer-sub">Official platform for building plan submission, AI scrutiny and approval workflow.</div>
                <div class="d-flex gap-2 mt-2">
                    <span class="social-round"><i data-lucide="facebook"></i></span>
                    <span class="social-round"><i data-lucide="twitter"></i></span>
                    <span class="social-round"><i data-lucide="youtube"></i></span>
                    <span class="social-round"><i data-lucide="linkedin"></i></span>
                </div>
            </div>
            <div class="col-xl-2 col-md-6">
                <div class="footer-head">Quick Links</div>
                <a class="footer-link" href="{{ url('/') }}">Home</a>
                <a class="footer-link" href="{{ session('bp_applicant_id') ? route('public.bp.applications.create') : route('public.bp.login') }}">How It Works</a>
                <a class="footer-link" href="{{ session('bp_applicant_id') ? route('public.bp.dashboard') : route('public.bp.login') }}">Track Application</a>
                <a class="footer-link" href="{{ route('public.bp.login') }}">Help & FAQs</a>
            </div>
            <div class="col-xl-2 col-md-6">
                <div class="footer-head">Resources</div>
                <div class="footer-link">Guidelines & SOPs</div>
                <div class="footer-link">Document Requirements</div>
                <div class="footer-link">Fee Structure</div>
                <div class="footer-link">Public Notices</div>
            </div>
            <div class="col-xl-2 col-md-6">
                <div class="footer-head">Support</div>
                <div class="footer-link"><i data-lucide="phone" class="me-1"></i>042-111-523-523</div>
                <div class="footer-link"><i data-lucide="mail" class="me-1"></i>support@lda.gov.pk</div>
                <div class="footer-link"><i data-lucide="clock-3" class="me-1"></i>Mon - Fri: 9:00 AM - 5:00 PM</div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="footer-head">Download App</div>
                <div class="footer-mini mb-2">Coming Soon on</div>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="store-btn"><i data-lucide="smartphone"></i>App Store</span>
                    <span class="store-btn"><i data-lucide="smartphone"></i>Google Play</span>
                </div>
            </div>
        </div>
        <hr class="border-light opacity-25 my-3">
        <div class="d-flex justify-content-between flex-wrap gap-2 copyright">
            <div>© {{ date('Y') }} Lahore Development Authority. All rights reserved.</div>
            <div>Privacy Policy &nbsp; | &nbsp; Terms of Use &nbsp; | &nbsp; Accessibility</div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script>lucide.createIcons();</script>
</body>
</html>
