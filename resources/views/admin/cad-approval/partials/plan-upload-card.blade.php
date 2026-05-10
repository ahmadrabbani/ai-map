<div class="card h-100">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <div>{{ $plan->label }}</div>
            <div class="text-muted small text-uppercase">{{ $plan->floor_type }}</div>
        </div>
        <div class="d-flex gap-2">
            <span class="badge {{ $plan->is_required ? 'text-bg-danger' : 'text-bg-secondary' }}">
                {{ $plan->is_required ? 'Required' : 'Optional' }}
            </span>
            <span class="badge text-bg-light border">{{ str_replace('_', ' ', $plan->status) }}</span>
        </div>
    </div>
    <div class="card-body">
        @if ($plan->floor_type === 'basement')
            <p class="small text-muted">
                Basement is optional for 5 Marla and 10 Marla, and required for Above 10 Marla.
            </p>
        @endif

        <div class="mb-3">
            <label class="form-label small fw-semibold">Upload or replace file</label>
            <input
                type="file"
                name="plans[{{ $plan->floor_type }}]"
                class="form-control"
                accept=".dwg,.dxf,.pdf"
            >
            <div class="form-text">Prefer CAD files with official guideline layers. PDF can still be uploaded for review and manual checking.</div>
        </div>

        <dl class="row small mb-3">
            <dt class="col-5">Upload status</dt>
            <dd class="col-7">{{ $plan->is_uploaded ? 'Uploaded' : 'Pending' }}</dd>
            <dt class="col-5">Processing status</dt>
            <dd class="col-7">{{ str_replace('_', ' ', $plan->status) }}</dd>
            @if (!empty($plan->layer_validation_json))
                <dt class="col-5">Layer validation</dt>
                <dd class="col-7">{{ str_replace('_', ' ', $plan->layer_validation_json['status'] ?? 'pending') }}</dd>
                <dt class="col-5">Confidence</dt>
                <dd class="col-7">{{ $plan->confidence_score ?? 'n/a' }}</dd>
            @endif
        </dl>

        <div class="d-flex flex-wrap gap-2 mb-3">
            @if ($plan->is_uploaded)
                <button type="submit" formaction="{{ route('admin.plan.approval-wizard.process-plan', [$application, $plan]) }}" class="btn btn-sm btn-primary">Process</button>
                <button type="submit" formaction="{{ route('admin.plan.approval-wizard.rerun-plan', [$application, $plan]) }}" class="btn btn-sm btn-outline-primary">Rerun</button>
            @endif

            @if ($plan->cad_submission_id)
                <a href="{{ route('admin.plan.cad-layer-viewer', $plan->cad_submission_id) }}" class="btn btn-sm btn-outline-secondary">
                    Open Viewer
                </a>
            @endif

            @if ($plan->cad_submission_id && Route::has('admin.plan.cad-expert-label.edit'))
                <a href="{{ route('admin.plan.cad-expert-label.edit', $plan->cad_submission_id) }}" class="btn btn-sm btn-outline-warning">
                    Expert Marking
                </a>
            @else
                <!-- TODO: link this button to existing expert marking route. -->
                <button type="button" class="btn btn-sm btn-outline-warning" disabled>Expert Marking</button>
            @endif
        </div>

        <div class="d-flex flex-wrap gap-2 small">
            @if ($plan->cad_submission_id && $plan->overlay_pdf_path)
                <a href="{{ route('admin.plan.cad-compliance.overlay', $plan->cad_submission_id) }}" class="btn btn-sm btn-outline-secondary">
                    Overlay PDF
                </a>
            @endif
            @if ($plan->cad_submission_id && $plan->drawing_pdf_path)
                <a href="{{ route('admin.plan.cad-compliance.drawing', $plan->cad_submission_id) }}" class="btn btn-sm btn-outline-secondary">
                    Drawing PDF
                </a>
            @endif
        </div>

        @if (!empty($plan->layer_validation_json))
            @if (($plan->layer_validation_json['status'] ?? null) === 'needs_expert_review')
                <div class="alert alert-warning small py-2">
                    The uploaded drawing did not fully match the official layer guideline. The system kept the result, but expert review is recommended before final submission.
                </div>
            @endif
            <details class="small">
                <summary class="text-primary">Layer validation summary</summary>
                <pre class="bg-light border rounded p-2 mt-2 mb-0" style="max-height: 220px; overflow:auto;">{{ json_encode($plan->layer_validation_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @endif
    </div>

    <div class="card-footer bg-white">
        @include('admin.cad-approval.partials.system-result-card', ['plan' => $plan])
    </div>
</div>
