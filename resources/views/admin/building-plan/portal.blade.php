@extends('layouts.app')

@section('title', 'Building Plan Application Portal')

@section('content')
<style>
    .portal-hero {
        position: relative;
        border-radius: 18px;
        padding: 24px;
        background: linear-gradient(130deg, #0f172a 0%, #102a43 46%, #0f766e 100%);
        color: #f8fafc;
        margin-bottom: 16px;
        box-shadow: 0 24px 52px rgba(15, 23, 42, 0.2);
        overflow: hidden;
    }

    .portal-hero::before,
    .portal-hero::after {
        content: "";
        position: absolute;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255,255,255,.22), rgba(255,255,255,0));
        pointer-events: none;
    }
    .portal-hero::before { width: 250px; height: 250px; right: -110px; top: -110px; }
    .portal-hero::after { width: 200px; height: 200px; left: -90px; bottom: -90px; }

    .portal-title { font-size: clamp(1.35rem, 2.2vw, 1.9rem); font-weight: 800; letter-spacing: -.02em; margin-bottom: 8px; position: relative; z-index: 1; }
    .portal-sub { color: rgba(241,245,249,.9); position: relative; z-index: 1; }
    .portal-pills { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 8px; position: relative; z-index: 1; }
    .portal-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 12px;
        font-weight: 700;
        background: rgba(255,255,255,.14);
        border: 1px solid rgba(255,255,255,.2);
    }

    .portal-actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .portal-actions .btn { border-radius: 10px; font-weight: 700; }

    .portal-card {
        border-radius: 14px;
        border: 1px solid rgba(148, 163, 184, 0.24);
        box-shadow: 0 18px 36px rgba(15, 23, 42, 0.07);
    }

    .portal-card .card-header {
        border-radius: 14px 14px 0 0;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        font-weight: 700;
    }

    .portal-grid-card {
        border-radius: 12px;
        border: 1px solid #dbe4ee;
        background: #fbfdff;
        padding: 12px;
        height: 100%;
    }

    .portal-layman-box {
        border-radius: 12px;
        border: 1px solid #dbe4ee;
        background: #fbfdff;
        padding: 12px;
    }

    .portal-status-badges .badge { border-radius: 999px; font-size: 12px; }

    .portal-enter { animation: portalEnter .34s ease both; }
    @keyframes portalEnter { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="portal-hero portal-enter">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div class="portal-title">Your Building Plan Application</div>
            <div class="portal-sub">Application ID: <strong>{{ $application->application_number }}</strong></div>
            <div class="portal-sub">Current status: {{ $application->status }}</div>
            <div class="portal-pills">
                <span class="portal-pill">AI Confidence: {{ number_format((float) ($application->aiReport->ai_confidence_score ?? 0), 2) }}%</span>
                <span class="portal-pill">Recommendation: {{ $application->aiReport->ai_recommendation ?? 'Needs Expert Review' }}</span>
            </div>
        </div>
        <div class="portal-actions">
            <a class="btn btn-light" href="{{ route('admin.plan.bp.verify', $application->qr_token) }}">QR Verification Page</a>
            <a class="btn btn-outline-light" href="{{ route('admin.plan.bp.report.show', $application) }}">View AI Report</a>
        </div>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success portal-enter">{{ session('status') }}</div>
@endif

<div class="row g-3">
    <div class="col-12 portal-enter">
        <div class="card portal-card mb-3">
            <div class="card-header">Auto Report Cards (From Uploaded Textual Table)</div>
            <div class="card-body">
                <div class="row g-2">
                    @foreach($reportCards as $card)
                        <div class="col-md-6">
                            <div class="portal-grid-card">
                                <div class="fw-bold">{{ $card['key'] }} {{ $card['title'] }}</div>
                                <div class="text-muted small mb-2">{{ $card['description'] }}</div>
                                @foreach($card['items'] as $label => $value)
                                    <div><strong>{{ $label }}:</strong> {{ ($value === null || $value === '') ? '-' : $value }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="card portal-card mb-3">
            <div class="card-header">AI Summary (Layman View)</div>
            <div class="card-body">
                <div><strong>AI Recommendation:</strong> {{ $application->aiReport->ai_recommendation ?? 'Needs Expert Review' }}</div>
                <div><strong>AI Confidence:</strong> {{ number_format((float) ($application->aiReport->ai_confidence_score ?? 0), 2) }}%</div>
                <div class="mt-2 portal-status-badges">
                    <span class="badge text-bg-success me-1">Clear: {{ $counts['pass'] }}</span>
                    <span class="badge text-bg-danger me-1">Issue Found: {{ $counts['fail'] }}</span>
                    <span class="badge text-bg-warning text-dark">Needs Review: {{ $counts['needs_review'] }}</span>
                </div>
                <div class="mt-3 portal-layman-box">
                    <strong>What this means</strong>
                    <div class="small text-muted mt-1">
                        This is a simplified report for applicants. Clear items look compliant, "Issue Found" items need correction,
                        and "Needs Review" items require authority verification.
                    </div>
                </div>
                <div class="mt-3 alert alert-secondary mb-0">
                    <strong>Suggested next step:</strong> {{ $laymanNextStep }}
                </div>
                <div class="mt-3">
                    <strong>Clause references checked</strong>
                    <ul class="mb-0 mt-2">
                        @forelse(($laymanClauseReferences ?? []) as $item)
                            <li>
                                <strong>{{ str_replace('_', ' ', (string) $item['rule_code']) }}</strong>: {{ $item['clause_reference'] }}
                            </li>
                        @empty
                            <li class="text-muted">No clause references available yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>

        <div class="card portal-card">
            <div class="card-header">Submit For Authority Review</div>
            <div class="card-body">
                <p class="text-muted mb-2">
                    Once submitted, the technical CAD layer analysis and chat conversation will be available to AD ePermit for official review.
                </p>
                @php
                    $isAlreadySubmittedToAd = in_array($application->status, [
                        'Submitted to AD ePermit',
                        'Under AD ePermit Review',
                        'Forwarded to DDTP',
                        'Under DDTP Review',
                        'Approved',
                        'Rejected',
                    ], true);
                @endphp

                @if($isAlreadySubmittedToAd)
                    <div class="alert alert-info mb-0">
                        Already submitted to AD ePermit.
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.plan.bp.submit-ad', $application) }}">
                        @csrf
                        <button class="btn btn-success" type="submit">Submit to AD ePermit</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

@include('admin.building-plan.partials-chatbot', ['application' => $application])

<div class="alert alert-info mt-3 mb-0 portal-enter" style="animation-delay:.08s;">
    This portal shows applicant-friendly AI guidance from extracted text/table data and AI analysis output. Final approval/rejection is decided only by the competent authority.
</div>
@endsection
