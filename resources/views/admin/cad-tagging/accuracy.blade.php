@extends('layouts.app')

@section('title', 'CAD Accuracy Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-start gap-3 mb-4"><div><h1 class="mb-1">CAD AI Accuracy</h1><div class="text-muted">Metrics are calculated only against expert-verified tags. Targets are not claims of achievement.</div></div><a class="btn btn-outline-primary" href="{{ route('admin.plan.cad-tagging.building-plans') }}">Building Plans</a></div>
@if(!$latest)<div class="alert alert-warning">No evaluation run exists yet. Open a drawing, verify tags, then select “Calculate Accuracy”.</div>@endif
<div class="row g-3 mb-4">
    @foreach([
        ['Tagged drawings',$counts['drawings']], ['Verified entities',$counts['verified_entities']],
        ['Micro F1',isset($summary['micro_f1']) ? number_format($summary['micro_f1']*100,1).'%' : 'Not measured'],
        ['Average polygon IoU',isset($summary['average_polygon_iou']) ? number_format($summary['average_polygon_iou'],3) : 'Not measured'],
        ['Within 5% area',isset($summary['area_within_5_percent']) ? number_format($summary['area_within_5_percent']*100,1).'%' : 'Not measured'],
        ['Low-confidence / unreviewed',$counts['unreviewed']],
    ] as [$label,$value])<div class="col-md-4 col-xl-2"><div class="card card-body h-100"><div class="small text-muted">{{ $label }}</div><div class="fs-4 fw-bold">{{ $value }}</div></div></div>@endforeach
</div>
<div class="row g-3">
    <div class="col-xl-8"><div class="card"><div class="card-header fw-semibold">Classification by entity</div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Entity</th><th>TP</th><th>FP</th><th>FN</th><th>Precision</th><th>Recall</th><th>F1</th><th>Support</th></tr></thead><tbody>
        @forelse($entityMetrics as $metric) @php($m=$metric->metrics)
        <tr><td>{{ str_replace('_',' ',ucwords($metric->entity_type,'_')) }}</td><td>{{ $m['tp'] }}</td><td>{{ $m['fp'] }}</td><td>{{ $m['fn'] }}</td><td>{{ number_format($m['precision']*100,1) }}%</td><td>{{ number_format($m['recall']*100,1) }}%</td><td>{{ number_format($m['f1']*100,1) }}%</td><td>{{ $m['support'] }}</td></tr>
        @empty<tr><td colspan="8" class="text-center text-muted py-4">No per-entity metrics available.</td></tr>@endforelse
    </tbody></table></div></div></div>
    <div class="col-xl-4"><div class="card mb-3"><div class="card-header fw-semibold">Acceptance targets</div><div class="card-body small">
        <div>Overall entity F1 ≥ 90%</div><div>Critical recall ≥ 95%</div><div>Average polygon IoU ≥ 0.85</div><div>Room area within 5% ≥ 95%</div><div>Compliance false-pass rate &lt; 1%</div><hr><strong>Status:</strong> {{ $latest && $latest->locked_ground_truth ? 'Eligible for formal comparison' : 'Not yet proven on a locked gold set' }}
    </div></div><div class="card"><div class="card-header fw-semibold">Review outcomes</div><div class="card-body small"><div>Accepted: {{ $counts['accepted'] }}</div><div>Corrected: {{ $counts['corrected'] }}</div><div>Rejected: {{ $counts['rejected'] }}</div><div>Rule false-pass rate: Not measured</div></div></div></div>
</div>
@if($runs->isNotEmpty())<div class="card mt-3"><div class="card-header fw-semibold">Model/evaluation history</div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Run</th><th>Date</th><th>Split</th><th>Locked gold</th><th>Micro F1</th><th>IoU</th></tr></thead><tbody>@foreach($runs as $run)<tr><td>{{ $run->name }}</td><td>{{ $run->created_at }}</td><td>{{ $run->dataset_split }}</td><td>{{ $run->locked_ground_truth ? 'Yes' : 'No' }}</td><td>{{ isset($run->summary['micro_f1']) ? number_format($run->summary['micro_f1']*100,1).'%' : '—' }}</td><td>{{ isset($run->summary['average_polygon_iou']) ? number_format($run->summary['average_polygon_iou'],3) : '—' }}</td></tr>@endforeach</tbody></table></div></div>@endif
@endsection
