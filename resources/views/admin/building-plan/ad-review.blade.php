@extends('layouts.app')

@section('title', 'AD ePermit Review')

@section('content')
<h1 class="h4 mb-3">AD ePermit Review: {{ $application->application_number }}</h1>
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3"><div class="card-header">AI Report Snapshot</div><div class="card-body">
            <div><strong>Recommendation:</strong> {{ $application->aiReport->ai_recommendation ?? '-' }}</div>
            <div><strong>Confidence:</strong> {{ $application->aiReport->ai_confidence_score ?? 0 }}%</div>
            <div><strong>Expert review required:</strong> {{ count($application->aiReport->expert_review_items_json ?? []) ? 'Yes' : 'No' }}</div>
            <div class="mt-2"><a href="{{ route('admin.plan.bp.report.show', $application) }}">Open full report</a></div>
            @if($application->cad_submission_id)
                <div class="mt-2">
                    <a href="{{ url('/admin/plan/cad-submissions/'.$application->cad_submission_id.'/label') }}" target="_blank">Open Expert Labeling (CAD)</a>
                </div>
                @if($application->map_drawing_id)
                    <div class="mt-2">
                        <a href="{{ url('/admin/plan/cad-submissions/'.$application->cad_submission_id.'/viewer?map_drawing_id='.$application->map_drawing_id) }}" target="_blank">Open Technical CAD Viewer</a>
                    </div>
                @endif
            @endif
        </div></div>

        <div class="card">
            <div class="card-header">AD ePermit Actions</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.plan.bp.ad.update', $application) }}" class="row g-2">
                    @csrf
                    <div class="col-md-4">
                        <select name="action" class="form-select" required>
                            <option value="add_remarks">Add Remarks</option>
                            <option value="return_for_correction">Return for Correction</option>
                            <option value="needs_expert_review">Mark Needs Expert Review</option>
                            <option value="forward_to_ddtp">Forward to DDTP</option>
                        </select>
                    </div>
                    <div class="col-md-8"><input class="form-control" type="text" name="remarks" placeholder="Remarks"></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Save Action</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        @include('admin.building-plan.partials-chatbot', ['application' => $application])
    </div>
</div>
@endsection
