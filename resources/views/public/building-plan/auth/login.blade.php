@extends('public.building-plan.layout')
@section('title', 'Applicant Login')
@section('content')
<div class="row justify-content-center align-items-center g-4">
    <div class="col-lg-5">
        <div class="card auth-card">
            <div class="card-header d-flex align-items-center gap-2"><i data-lucide="user"></i> Applicant Login</div>
            <div class="card-body p-4">
                <p class="helper mb-3">Login using CNIC and password to continue your building plan application workflow.</p>
                <form method="POST" action="{{ route('public.bp.login.store') }}">@csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">CNIC</label>
                        <input class="form-control" name="cnic" placeholder="35202-1234567-1" value="{{ old('cnic') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password</label>
                        <input type="password" class="form-control" name="password" required>
                    </div>
                    <button class="btn btn-primary w-100"><i data-lucide="log-in" class="me-1"></i> Login</button>
                </form>
                <div class="mt-3 small">No account? <a href="{{ route('public.bp.register') }}">Register here</a>.</div>
            </div>
        </div>
    </div>
</div>
@endsection
