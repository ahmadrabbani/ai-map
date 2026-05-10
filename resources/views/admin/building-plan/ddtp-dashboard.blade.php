@extends('layouts.app')

@section('title', 'DDTP Dashboard')

@section('content')
<h1 class="h4 mb-3">DDTP Review Dashboard</h1>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>Application</th><th>Status</th><th>AI Recommendation</th><th>Action</th></tr></thead>
                <tbody>
                @forelse($applications as $app)
                    <tr>
                        <td>{{ $app->application_number }}</td>
                        <td>{{ $app->status }}</td>
                        <td>{{ $app->aiReport->ai_recommendation ?? '-' }}</td>
                        <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.plan.bp.ddtp.show', $app) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted">No DDTP applications.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="mt-3">{{ $applications->links() }}</div>
@endsection
