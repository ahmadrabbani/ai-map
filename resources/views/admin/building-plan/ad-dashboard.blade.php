@extends('layouts.app')

@section('title', 'AD ePermit Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">AD ePermit Review Dashboard</h1>
        <div class="text-muted small">Logged in as {{ auth()->user()->name ?? 'AD officer' }}</div>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="{{ route('admin.plan.bp.ad.index') }}">Home / Dashboard</a>
        <form method="POST" action="{{ route('admin.plan.bp.ad.logout') }}">
            @csrf
            <button class="btn btn-outline-secondary" type="submit">Logout</button>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    @php
        $cards = [
            ['key' => 'assigned', 'label' => 'Total Assigned Cases', 'tone' => 'primary', 'link' => route('admin.plan.bp.ad.index', ['status' => 'assigned'])],
            ['key' => 'under_process', 'label' => 'Under Process Cases', 'tone' => 'warning', 'link' => route('admin.plan.bp.ad.index', ['status' => 'under_process'])],
            ['key' => 'observation', 'label' => 'Observation Cases', 'tone' => 'danger', 'link' => route('admin.plan.bp.ad.index', ['status' => 'observation'])],
            ['key' => 'marked_to_dfps', 'label' => 'Marked to DFPS', 'tone' => 'success', 'link' => route('admin.plan.bp.ad.index', ['status' => 'marked_to_dfps'])],
            ['key' => 'approved', 'label' => 'Approved / Recommended', 'tone' => 'info', 'link' => route('admin.plan.bp.ad.index', ['status' => 'approved'])],
            ['key' => 'rejected', 'label' => 'Rejected / Objection', 'tone' => 'dark', 'link' => route('admin.plan.bp.ad.index', ['status' => 'rejected'])],
        ];
    @endphp
    @foreach($cards as $card)
        <div class="col-md-4 col-xl-2">
            <a href="{{ $card['link'] }}" class="text-decoration-none">
                <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <div class="text-uppercase small text-muted">{{ $card['label'] }}</div>
                        <div class="fs-3 fw-bold text-{{ $card['tone'] }}">{{ (int) ($stats[$card['key']] ?? 0) }}</div>
                    </div>
                </div>
            </a>
        </div>
    @endforeach
</div>

@if($statusFilter !== '')
    <div class="alert alert-info">Filtered by: <strong>{{ str_replace('_', ' ', $statusFilter) }}</strong></div>
@endif

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>Application</th><th>Applicant</th><th>Plot</th><th>Status</th><th>Submitted</th><th>DFPS</th><th>Action</th></tr></thead>
                <tbody>
                @forelse($applications as $app)
                    @php $latestPush = $app->dfpsPushLogs->first(); @endphp
                    <tr>
                        <td>{{ $app->application_no }}</td>
                        <td>{{ $app->applicant_name ?: '-' }}</td>
                        <td>{{ $app->scheme_name ?: $app->scheme }} / {{ $app->block_name ?: $app->block }} / {{ $app->plot_no ?: $app->plot_ref }}</td>
                        <td><span class="badge text-bg-light border">{{ $app->current_status ?: $app->status }}</span></td>
                        <td>{{ optional($app->submitted_at)->format('Y-m-d H:i') ?: '-' }}</td>
                        <td>
                            @if($latestPush)
                                <span class="badge {{ $latestPush->success ? 'text-bg-success' : 'text-bg-danger' }}">{{ $latestPush->success ? 'Pushed' : 'Failed' }}</span>
                            @else
                                <span class="text-muted small">Pending</span>
                            @endif
                        </td>
                        <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.plan.bp.ad.show', $app) }}">Review</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted">No AD ePermit applications.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="mt-3">{{ $applications->links() }}</div>
@endsection
