@extends('layouts.app')

@section('title', 'Building Plan AI Applications')

@section('content')
<div class="page-header">
    <h1>Building Plan AI Approval</h1>
    <div class="subtitle">Upload plan, run AI analysis, review report, and route to AD ePermit/DDTP.</div>
</div>

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">New Application Upload</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.plan.bp.store') }}" enctype="multipart/form-data" class="row g-3">
                    @csrf
                    <div class="col-12">
                        <label class="form-label">Applicant Name</label>
                        <input class="form-control" type="text" name="applicant_name" value="{{ old('applicant_name') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Applicant Email</label>
                        <input class="form-control" type="email" name="applicant_email" value="{{ old('applicant_email') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Applicant Phone</label>
                        <input class="form-control" type="text" name="applicant_phone" value="{{ old('applicant_phone') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Plan File (DWG / DXF / CAD / PDF)</label>
                        <input class="form-control" type="file" name="map_file" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">List Document (DOCX, optional)</label>
                        <input class="form-control" type="file" name="list_document" accept=".docx">
                        <div class="form-text">
                            If provided, applicant details, plot metadata, and layer table data are auto-extracted for prefill and review.
                        </div>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary" type="submit">Upload & Generate AI Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Recent Applications</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead>
                        <tr>
                            <th>No</th>
                            <th>Applicant</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($applications as $app)
                            <tr>
                                <td>{{ $app->application_number }}</td>
                                <td>{{ $app->applicant_name ?: '-' }}</td>
                                <td><span class="badge text-bg-secondary">{{ $app->status }}</span></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.plan.bp.portal', $app) }}">Open</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No applications yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
