@extends('public.building-plan.layout')
@section('title', 'Applicant Dashboard')
@section('content')
<div class="card mb-3">
    <div class="card-body d-flex justify-content-between flex-wrap gap-3 align-items-center">
        <div>
            <h5 class="mb-1">Welcome, {{ $applicant->name }}</h5>
            <div class="text-muted">CNIC: {{ $applicant->cnic }}</div>
        </div>
        <a class="btn btn-success" href="{{ route('public.bp.applications.create') }}">Submit New Building Plan</a>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold">My Building Plan Applications</div>
    <div class="card-body table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Application No</th>
                    <th>Plot Reference</th>
                    <th>Submitted Date</th>
                    <th>AI Status</th>
                    <th>Current Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($applications as $app)
                <tr>
                    <td>{{ $app->application_no ?: 'Draft-' . $app->id }}</td>
                    <td>{{ $app->plot_ref ?: '-' }}</td>
                    <td>{{ $app->submitted_at?->format('d M Y H:i') ?: '-' }}</td>
                    <td>{{ $app->ai_status }}</td>
                    <td>{{ $app->status }}</td>
                    <td class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('public.bp.applications.show', $app->id) }}">View</a>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('public.bp.applications.download-report', $app->id) }}">Download AI Report</a>
                        @if($app->status === 'Draft')
                            <a class="btn btn-sm btn-outline-warning" href="{{ route('public.bp.applications.edit', $app->id) }}">Continue/Edit</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted">No applications found.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $applications->links() }}
    </div>
</div>
@endsection
