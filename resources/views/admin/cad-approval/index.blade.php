@extends('layouts.app')

@section('title', 'CAD Approval Wizard')

@section('content')
    <div class="d-flex justify-content-between align-items-center page-header">
        <div>
            <h1>CAD Approval Wizard</h1>
            <div class="subtitle">Create and manage building-plan approval applications without affecting the existing CAD compliance flow.</div>
        </div>
        <a href="{{ route('admin.plan.approval-wizard.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> New Application
        </a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card">
        <div class="card-header">Applications</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Applicant</th>
                            <th>Plot</th>
                            <th>Plot Size</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($applications as $application)
                            <tr>
                                <td>#{{ $application->id }}</td>
                                <td>{{ $application->applicant_name }}</td>
                                <td>{{ $application->plot_number }}</td>
                                <td>{{ str_replace('_', ' ', $application->plot_size_category) }}</td>
                                <td><span class="badge text-bg-light border">{{ str_replace('_', ' ', $application->status) }}</span></td>
                                <td>{{ $application->updated_at?->diffForHumans() }}</td>
                                <td class="text-end">
                                    <a href="{{ route('admin.plan.approval-wizard.show', $application) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No applications created yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if (method_exists($applications, 'links'))
            <div class="card-footer">{{ $applications->links() }}</div>
        @endif
    </div>
@endsection
