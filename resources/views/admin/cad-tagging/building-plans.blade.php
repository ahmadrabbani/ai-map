@extends('layouts.app')

@section('title', 'CAD Tagging Queue')

@section('content')
<div class="d-flex justify-content-between align-items-start gap-3 mb-4">
    <div><h1 class="mb-1">Building Plans &amp; Tagging Queue</h1><div class="text-muted">Existing CAD submissions connected to their approval cases. No duplicate case records are created.</div></div>
    <a class="btn btn-outline-primary" href="{{ route('admin.plan.cad-tagging.accuracy') }}">Accuracy Dashboard</a>
</div>
<form class="card card-body mb-3" method="GET">
    <div class="row g-2">
        <div class="col-md-6"><input class="form-control" name="search" value="{{ request('search') }}" placeholder="Case, applicant, plot, scheme or CAD file"></div>
        <div class="col-md-2"><select class="form-select" name="floor"><option value="">All floors</option>@foreach(['basement','ground','first','second','roof','site','services'] as $floor)<option value="{{ $floor }}" @selected(request('floor') === $floor)>{{ ucfirst($floor) }}</option>@endforeach</select></div>
        <div class="col-md-2"><input class="form-control" name="status" value="{{ request('status') }}" placeholder="Status"></div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary">Filter</button></div>
    </div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Case / Plan</th><th>Applicant / Property</th><th>CAD file</th><th>Review</th><th>Model</th><th>Actions</th></tr></thead>
    <tbody>@forelse($plans as $plan)
        @php
            $predictions = $plan->submission->predictions; $tags = $plan->submission->tags;
            $total = $predictions->count(); $reviewed = $predictions->whereNotIn('status', ['unreviewed','ai_suggested'])->count();
            $percent = $total ? round($reviewed / $total * 100, 1) : 0;
        @endphp
        <tr>
            <td><strong>#{{ $plan->cad_approval_application_id }}</strong><div class="small text-muted">{{ $plan->label ?: ucfirst($plan->floor_type) }} · {{ $plan->status }}</div></td>
            <td>{{ $plan->application->applicant_name }}<div class="small text-muted">{{ $plan->application->scheme }} {{ $plan->application->phase }} {{ $plan->application->block }} · Plot {{ $plan->application->plot_number }}</div></td>
            <td>{{ $plan->submission->original_filename }}<div class="small text-muted">Uploaded {{ optional($plan->submission->created_at)->format('d M Y') }}</div></td>
            <td><div>{{ $reviewed }} / {{ $total }} ({{ $percent }}%)</div><div class="progress mt-1" style="height:6px"><div class="progress-bar" style="width:{{ $percent }}%"></div></div><div class="small text-muted">{{ $tags->where('status','confirmed')->count() }} confirmed · {{ $tags->where('status','corrected')->count() }} corrected</div></td>
            <td>{{ $predictions->pluck('model_version')->filter()->unique()->join(', ') ?: 'Not recorded' }}</td>
            <td><div class="d-flex gap-1 flex-wrap"><a class="btn btn-sm btn-primary" href="{{ route('admin.plan.cad-layer-viewer', $plan->cad_submission_id) }}">{{ $reviewed ? 'Continue Review' : 'Start Tagging' }}</a><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.plan.cad-tagging.accuracy') }}">View Accuracy</a></div></td>
        </tr>
    @empty<tr><td colspan="6" class="text-center text-muted py-5">No linked CAD plans match the current filters.</td></tr>@endforelse</tbody>
</table></div></div>
<div class="mt-3">{{ $plans->links() }}</div>
@endsection
