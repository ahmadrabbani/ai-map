@extends('layouts.app')

@section('title', 'Verify Application Data')

@section('content')
    <div class="page-header">
        <h1>Verify Application Data</h1>
        <div class="subtitle">Step 2: confirm the key details before moving to drawing upload and layer validation.</div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if (session('warning'))
        <div class="alert alert-warning">{{ session('warning') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @include('admin.cad-approval.partials.steps', ['currentStep' => 'verification'])

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">Verified Data Summary</div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="fw-semibold mb-2">Applicant Details</div>
                        <dl class="row small mb-0">
                            <dt class="col-sm-4">Name</dt>
                            <dd class="col-sm-8">{{ data_get($snapshot, 'applicant.name', '-') }}</dd>
                            <dt class="col-sm-4">CNIC</dt>
                            <dd class="col-sm-8">{{ data_get($snapshot, 'applicant.cnic', '-') }}</dd>
                            <dt class="col-sm-4">Mobile</dt>
                            <dd class="col-sm-8">{{ data_get($snapshot, 'applicant.mobile', '-') }}</dd>
                            <dt class="col-sm-4">Email</dt>
                            <dd class="col-sm-8">{{ data_get($snapshot, 'applicant.email', '-') }}</dd>
                        </dl>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold mb-2">Plot Details</div>
                        <dl class="row small mb-0">
                            <dt class="col-sm-4">Plot No.</dt>
                            <dd class="col-sm-8">{{ data_get($snapshot, 'plot.plot_number', '-') }}</dd>
                            <dt class="col-sm-4">Scheme</dt>
                            <dd class="col-sm-8">{{ data_get($snapshot, 'plot.scheme', '-') }}</dd>
                            <dt class="col-sm-4">Block</dt>
                            <dd class="col-sm-8">{{ data_get($snapshot, 'plot.block', '-') }}</dd>
                            <dt class="col-sm-4">Phase</dt>
                            <dd class="col-sm-8">{{ data_get($snapshot, 'plot.phase', '-') }}</dd>
                            <dt class="col-sm-4">Plot Size</dt>
                            <dd class="col-sm-8">{{ data_get($snapshot, 'plot.plot_size_category', '-') }}</dd>
                            <dt class="col-sm-4">Area</dt>
                            <dd class="col-sm-8">{{ data_get($snapshot, 'plot.plot_area_sqft', '-') }}</dd>
                        </dl>
                    </div>
                    <div>
                        <div class="fw-semibold mb-2">Submitted Floors</div>
                        <div class="small">
                            {{ collect(data_get($snapshot, 'submission.floors', []))->map(fn ($floor) => ucfirst($floor))->implode(', ') ?: 'No floors selected' }}
                        </div>
                        <div class="small mt-2"><strong>Basement included:</strong> {{ data_get($snapshot, 'submission.has_basement') ? 'Yes' : 'No' }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">Confirmation Questions</div>
                <div class="card-body">
                    <div class="alert alert-info small">
                        If you answer <strong>No</strong> to a critical question, the wizard will keep the case open for correction before upload continues.
                    </div>
                    <form method="POST" action="{{ route('admin.plan.approval-wizard.save-verification', $application) }}" class="row g-3">
                        @csrf
                        @foreach ($questions as $question)
                            <div class="col-12 border rounded p-3">
                                <div class="fw-semibold mb-2">{{ $question['question'] }}</div>
                                <div class="d-flex gap-3 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="answers[{{ $question['key'] }}][answer]" value="yes" id="{{ $question['key'] }}_yes" @checked(old("answers.{$question['key']}.answer", data_get($answers, $question['key'].'.answer')) === 'yes') required>
                                        <label class="form-check-label" for="{{ $question['key'] }}_yes">Yes</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="answers[{{ $question['key'] }}][answer]" value="no" id="{{ $question['key'] }}_no" @checked(old("answers.{$question['key']}.answer", data_get($answers, $question['key'].'.answer')) === 'no')>
                                        <label class="form-check-label" for="{{ $question['key'] }}_no">No</label>
                                    </div>
                                </div>
                                <textarea class="form-control" name="answers[{{ $question['key'] }}][remarks]" rows="2" placeholder="Optional remarks if you selected No">{{ old("answers.{$question['key']}.remarks", data_get($answers, $question['key'].'.remarks')) }}</textarea>
                            </div>
                        @endforeach
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save and Continue</button>
                            <a href="{{ route('admin.plan.approval-wizard.show', $application) }}" class="btn btn-outline-secondary">Back to Wizard</a>
                        </div>
                    </form>
                </div>
            </div>
            <div class="mt-4">
                @include('admin.cad-approval.partials.faq-assistant')
            </div>
        </div>
    </div>
@endsection
