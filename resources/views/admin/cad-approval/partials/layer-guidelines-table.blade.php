<div class="card">
    <div class="card-header">Layer Naming Guidelines</div>
    <div class="card-body p-0">
        <div class="px-3 pt-3 small text-muted">
            Official naming is based on <code>list.pdf</code> and the configured fallback file <code>storage/app/rules/layer_guidelines.json</code>.
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Layer</th>
                        <th>Purpose</th>
                        <th>Description</th>
                        <th>Aliases</th>
                        <th>Required</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($guidelineSummary as $item)
                        <tr>
                            <td><code>{{ $item['name'] }}</code></td>
                            <td>{{ str_replace('_', ' ', $item['purpose'] ?? '-') }}</td>
                            <td>{{ $item['description'] }}</td>
                            <td>{{ !empty($item['aliases']) ? implode(', ', $item['aliases']) : '-' }}</td>
                            <td>
                                <span class="badge {{ !empty($item['required']) ? 'text-bg-danger' : 'text-bg-secondary' }}">
                                    {{ !empty($item['required']) ? 'Required' : 'Optional' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
