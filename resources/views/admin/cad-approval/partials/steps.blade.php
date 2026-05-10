@php
    $steps = [
        'details' => 'Details',
        'verification' => 'Verified Data',
        'upload_plans' => 'Upload Plans',
        'layer_validation' => 'Layer Check',
        'expert_review' => 'Expert Review',
        'summary' => 'Summary',
        'final_report' => 'Final Report',
    ];
    $currentStep = $currentStep ?? 'details';
    $activeIndex = array_search($currentStep, array_keys($steps), true);
    if ($activeIndex === false) {
        $activeIndex = 0;
    }
@endphp

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            @foreach ($steps as $key => $label)
                @php $index = array_search($key, array_keys($steps), true); @endphp
                <span class="badge rounded-pill {{ $index <= $activeIndex ? 'text-bg-primary' : 'text-bg-light border' }}">
                    {{ $loop->iteration }}. {{ $label }}
                </span>
            @endforeach
        </div>
    </div>
</div>
