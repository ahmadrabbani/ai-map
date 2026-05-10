@extends('layouts.app')

@section('title', 'QR Verification')

@section('content')
<div class="page-header">
    <h1>Building Plan Verification</h1>
    <div class="subtitle">Verified from application QR code.</div>
</div>

<div class="card mb-3">
    <div class="card-header">Application Verification Summary</div>
    <div class="card-body row g-2">
        <div class="col-md-4"><strong>Application:</strong> {{ $application->application_number }}</div>
        <div class="col-md-4"><strong>Status:</strong> {{ $application->status }}</div>
        <div class="col-md-4"><strong>Recommendation:</strong> {{ $report?->ai_recommendation ?: 'N/A' }}</div>
        <div class="col-md-12"><strong>AI Analysis Status:</strong> {{ $report?->analysis_status ?: 'N/A' }}</div>
    </div>
</div>

<div class="card">
    <div class="card-header">Disclaimer</div>
    <div class="card-body">{{ \App\Services\AiReportGenerationService::DISCLAIMER }}</div>
</div>
@endsection
