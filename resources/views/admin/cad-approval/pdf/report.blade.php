<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>CAD Approval Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1, h2 { margin-bottom: 8px; }
        h1 { font-size: 20px; }
        h2 { font-size: 15px; margin-top: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 6px; text-align: left; vertical-align: top; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <h1>CAD Approval Report</h1>
    <div class="muted">Application #{{ $application->id }} • Generated {{ $report['generated_at'] ?? now()->toIso8601String() }}</div>

    <h2>Application Details</h2>
    <table>
        <tbody>
            @foreach (($report['application_details'] ?? []) as $label => $value)
                <tr>
                    <th>{{ ucwords(str_replace('_', ' ', $label)) }}</th>
                    <td>{{ is_scalar($value) || $value === null ? ($value ?: 'n/a') : json_encode($value) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Plan Checklist</h2>
    <table>
        <thead>
            <tr>
                <th>Plan</th>
                <th>Required</th>
                <th>Uploaded</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach (($report['required_optional_plan_checklist'] ?? []) as $item)
                <tr>
                    <td>{{ $item['label'] ?? $item['floor_type'] ?? 'Plan' }}</td>
                    <td>{{ !empty($item['required']) ? 'Yes' : 'No' }}</td>
                    <td>{{ !empty($item['uploaded']) ? 'Yes' : 'No' }}</td>
                    <td>{{ str_replace('_', ' ', $item['status'] ?? 'pending') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Final Recommendation</h2>
    <p>{{ $report['final_recommendation'] ?? 'Pending.' }}</p>

    <h2>Next Steps</h2>
    <ul>
        @foreach (($report['next_steps'] ?? []) as $step)
            <li>{{ $step }}</li>
        @endforeach
    </ul>
</body>
</html>
