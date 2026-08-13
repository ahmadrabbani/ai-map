@extends('layouts.app')

@section('title', 'AD ePermit Login')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card">
            <div class="card-header">AD ePermit Officer Login</div>
            <div class="card-body">
                <p class="text-muted small">Only AD ePermit users can view official review cases and open technical CAD screens.</p>
                @if($errors->any())
                    <div class="alert alert-danger">{{ $errors->first() }}</div>
                @endif
                <form method="POST" action="{{ route('admin.plan.bp.ad.login.store') }}" class="row g-3">
                    @csrf
                    <div class="col-12">
                        <label class="form-label">Email</label>
                        <input class="form-control" type="email" name="email" value="{{ old('email', 'ad.epermit@example.com') }}" required autofocus>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Password</label>
                        <input class="form-control" type="password" name="password" placeholder="password" required>
                    </div>
                    <div class="col-12 form-check ms-2">
                        <input class="form-check-input" type="checkbox" name="remember" value="1" id="rememberAd">
                        <label class="form-check-label" for="rememberAd">Remember me</label>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary w-100" type="submit">Login to AD ePermit Desk</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
