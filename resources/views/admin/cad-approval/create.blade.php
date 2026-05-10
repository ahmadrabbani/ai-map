@extends('layouts.app')

@section('title', 'Create CAD Approval Application')

@section('content')
    <div class="page-header">
        <h1>Create CAD Approval Application</h1>
        <div class="subtitle">Step 1: capture the applicant, plot, and floor submission details before the system decides required plans.</div>
    </div>

    @include('admin.cad-approval.partials.steps', ['currentStep' => 'details'])

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">Application Details</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.plan.approval-wizard.store-details') }}" class="row g-3">
                        @csrf
                        @include('admin.cad-approval.partials.details-form-fields', ['application' => null, 'plotSizeOptions' => $plotSizeOptions, 'floorOptions' => $floorOptions])
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save and Continue</button>
                            <a href="{{ route('admin.plan.approval-wizard.index') }}" class="btn btn-outline-secondary">Back</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header">What Happens Next</div>
                <div class="card-body">
                    <ol class="small mb-0 ps-3">
                        <li>Confirm applicant and plot data.</li>
                        <li>Upload DWG, DXF, or PDF plan files.</li>
                        <li>Check whether layers follow the official naming guideline.</li>
                        <li>Send unclear cases to expert review and measurement.</li>
                        <li>Generate the structured verification report.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
    <div class="mt-4">
        @include('admin.cad-approval.partials.faq-assistant')
    </div>
@endsection
