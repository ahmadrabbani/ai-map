@php
    $analysis = $plan->analysis_result ?? [];
    $polygonDiscovery = $analysis['polygon_discovery'] ?? [];
    $autoHandles = $polygonDiscovery['auto_handles'] ?? [];
    $samplePolygons = $polygonDiscovery['sample_polygons'] ?? [];
@endphp

<div class="card border-0 bg-light">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h6 class="mb-1">System Result</h6>
                <div class="text-muted small">{{ $plan->label }}</div>
            </div>
            <span class="badge text-bg-{{ ($analysis['status'] ?? null) === 'ok' ? 'success' : (($analysis['status'] ?? null) === 'needs_expert_review' ? 'warning' : 'secondary') }}">
                {{ str_replace('_', ' ', $analysis['status'] ?? 'not_processed') }}
            </span>
        </div>

        @if (! empty($analysis))
            @if (! empty($analysis['message']))
                <div class="alert alert-info py-2 mb-3">{{ $analysis['message'] }}</div>
            @endif

            @if (($analysis['status'] ?? null) === 'needs_expert_review')
                <dl class="row small mb-3">
                    <dt class="col-sm-4">Error Code</dt>
                    <dd class="col-sm-8">{{ $analysis['error_code'] ?? 'n/a' }}</dd>
                    <dt class="col-sm-4">Polygon Count</dt>
                    <dd class="col-sm-8">{{ $polygonDiscovery['count'] ?? $polygonDiscovery['polygon_count'] ?? 'n/a' }}</dd>
                    <dt class="col-sm-4">Auto Handles</dt>
                    <dd class="col-sm-8">
                        <code>{{ json_encode($autoHandles, JSON_UNESCAPED_SLASHES) }}</code>
                    </dd>
                    <dt class="col-sm-4">Recommended Next Step</dt>
                    <dd class="col-sm-8">
                        {{ data_get($analysis, 'recommended_next_step.instructions', 'Review the CAD drawing manually.') }}
                    </dd>
                </dl>

                @if (! empty($samplePolygons))
                    <details>
                        <summary class="small text-primary">Debug polygon sample</summary>
                        <pre class="small bg-white border rounded p-2 mt-2 mb-0" style="max-height: 240px; overflow:auto;">{{ json_encode(array_slice($samplePolygons, 0, 5), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif
            @elseif (! empty($analysis['rules']) && is_array($analysis['rules']))
                <div class="small text-muted mb-2">Rule evaluation summary</div>
                <ul class="list-group list-group-flush">
                    @foreach (array_slice($analysis['rules'], 0, 5) as $rule)
                        <li class="list-group-item px-0 d-flex justify-content-between">
                            <span>{{ $rule['title'] ?? $rule['id'] ?? 'Rule' }}</span>
                            <span class="badge text-bg-{{ ($rule['pass'] ?? null) === false ? 'danger' : 'success' }}">
                                {{ ($rule['pass'] ?? null) === false ? 'Failed' : 'Passed' }}
                            </span>
                        </li>
                    @endforeach
                </ul>

                @if (! empty($analysis['areas']) || ! empty($analysis['setbacks_ft']) || ! empty($analysis['dimensions']))
                    <details class="mt-3">
                        <summary class="small text-primary">Textual and measurable records</summary>
                        <pre class="small bg-white border rounded p-2 mt-2 mb-0" style="max-height: 240px; overflow:auto;">{{ json_encode([
                            'areas' => $analysis['areas'] ?? [],
                            'setbacks_ft' => $analysis['setbacks_ft'] ?? [],
                            'dimensions' => $analysis['dimensions'] ?? [],
                            'warnings' => $analysis['warnings'] ?? [],
                            'resolver' => $analysis['resolver'] ?? [],
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif
            @else
                <div class="text-muted small">Analysis result saved, but there are no rule rows to display.</div>
            @endif
        @else
            <div class="text-muted small">No system results yet.</div>
        @endif
    </div>
</div>
