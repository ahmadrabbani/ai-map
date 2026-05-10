@extends('layouts.app')

@section('title', 'DDTP Review')

@section('content')
<h1 class="h4 mb-3">DDTP Review: {{ $application->application_number }}</h1>
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

<div class="card mb-3">
    <div class="card-header">Application Packet</div>
    <div class="card-body">
        <div><strong>Applicant:</strong> {{ $application->applicant_name ?: '-' }}</div>
        <div><strong>Uploaded File:</strong> {{ $application->uploaded_file_name }}</div>
        <div><strong>AI Recommendation:</strong> {{ $application->aiReport->ai_recommendation ?? '-' }}</div>
        <div><strong>AI Confidence:</strong> {{ $application->aiReport->ai_confidence_score ?? 0 }}%</div>
        <div><strong>Verification URL:</strong> <a href="{{ $application->verification_url }}">{{ $application->verification_url }}</a></div>
    </div>
</div>

<div class="card">
    <div class="card-header">DDTP Decision</div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.plan.bp.ddtp.update', $application) }}" class="row g-2">
            @csrf
            <div class="col-md-4">
                <select name="action" class="form-select" required>
                    <option value="approve">Approve</option>
                    <option value="reject">Reject</option>
                    <option value="needs_expert_review">Needs Expert Review</option>
                    <option value="return_for_correction">Return for Correction</option>
                </select>
            </div>
            <div class="col-md-8"><input class="form-control" type="text" name="remarks" placeholder="Decision remarks"></div>
            <div class="col-12"><button class="btn btn-success" type="submit">Save Decision</button></div>
        </form>
    </div>
</div>
@endsection
