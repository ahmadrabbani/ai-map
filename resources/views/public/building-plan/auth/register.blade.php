@extends('public.building-plan.layout')
@section('title', 'Applicant Registration')
@section('content')
<div class="row justify-content-center align-items-center g-4">
    <div class="col-lg-6">
        <div class="card auth-card">
            <div class="card-header d-flex align-items-center gap-2"><i data-lucide="user-plus"></i> Applicant Registration</div>
            <div class="card-body p-4">
                <p class="helper mb-3">Create your account to start AI-assisted building plan submission and tracking.</p>
                <form method="POST" action="{{ route('public.bp.register.store') }}">@csrf
                    <div class="mb-3"><label class="form-label fw-semibold">Full Name</label><input class="form-control" name="name" value="{{ old('name') }}" required></div>
                    <div class="mb-3"><label class="form-label fw-semibold">CNIC</label><input class="form-control" name="cnic" placeholder="35202-1234567-1" value="{{ old('cnic') }}" required></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Mobile Number</label><input class="form-control" name="mobile" placeholder="03xx-xxxxxxx" value="{{ old('mobile') }}" required></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Email</label><input type="email" class="form-control" name="email" value="{{ old('email') }}" required></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Password</label><input type="password" class="form-control" name="password" required></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Confirm Password</label><input type="password" class="form-control" name="password_confirmation" required></div>
                    <button class="btn btn-primary w-100"><i data-lucide="badge-check" class="me-1"></i> Create Account</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
