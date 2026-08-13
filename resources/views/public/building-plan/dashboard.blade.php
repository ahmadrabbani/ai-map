@extends('public.building-plan.layout')
@section('title', 'Applicant Dashboard')
@section('content')
<div class="card mb-3">
    <div class="card-body d-flex justify-content-between flex-wrap gap-3 align-items-center">
        <div>
            <h5 class="mb-1">Welcome, {{ $applicant->name }}</h5>
            <div class="text-muted">CNIC: {{ $applicant->cnic }}</div>
        </div>
        <a class="btn btn-success" href="{{ route('public.bp.applications.create') }}">Submit New Building Plan</a>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold">My Building Plan Applications</div>
    <div class="card-body table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr data-ad-message-row data-chat-peek-url="">
                    <th>Application No</th>
                    <th>Plot Reference</th>
                    <th>Submitted Date</th>
                    <th>AI Status</th>
                    <th>Current Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            @forelse($applications as $app)
                @php($unreadAdMessages = (int) ($app->unread_ad_epermit_messages_count ?? 0))
                <tr data-ad-message-row data-chat-peek-url="{{ route('public.bp.applications.chat.index', $app->id) }}">
                    <td>{{ $app->application_no ?: 'Draft-' . $app->id }}</td>
                    <td>{{ $app->plot_ref ?: '-' }}</td>
                    <td>{{ $app->submitted_at?->format('d M Y H:i') ?: '-' }}</td>
                    <td>{{ $app->ai_status }}</td>
                    <td>
                        <div>{{ $app->status }}</div>
                        <span
                            class="badge text-bg-danger mt-1"
                            data-ad-message-badge
                            style="{{ $unreadAdMessages > 0 ? '' : 'display:none;' }}"
                        >{{ $unreadAdMessages }} new AD ePermit {{ \Illuminate\Support\Str::plural('message', $unreadAdMessages) }}</span>
                    </td>
                    <td class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-sm {{ $unreadAdMessages > 0 ? 'btn-danger' : 'btn-outline-primary' }}" href="{{ route('public.bp.applications.show', $app->id) }}" data-ad-message-action>
                            {{ $unreadAdMessages > 0 ? 'View / Reply' : 'View' }}
                        </a>
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('public.bp.applications.download-report', $app->id) }}">Download AI Report</a>
                        @if($app->status === 'Draft')
                            <a class="btn btn-sm btn-outline-warning" href="{{ route('public.bp.applications.edit', $app->id) }}">Continue/Edit</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted">No applications found.</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $applications->links() }}
    </div>
</div>
@endsection

@section('scripts')
<script>
(() => {
  const rows = [...document.querySelectorAll('[data-ad-message-row]')];
  if (!rows.length) return;

  const plural = (word, count) => Number(count) === 1 ? word : `${word}s`;
  const updateRow = (row, count) => {
    const unread = Math.max(0, Number(count || 0));
    const badge = row.querySelector('[data-ad-message-badge]');
    const action = row.querySelector('[data-ad-message-action]');
    if (badge) {
      badge.textContent = `${unread} new AD ePermit ${plural('message', unread)}`;
      badge.style.display = unread > 0 ? '' : 'none';
    }
    if (action) {
      action.textContent = unread > 0 ? 'View / Reply' : 'View';
      action.classList.toggle('btn-danger', unread > 0);
      action.classList.toggle('btn-outline-primary', unread === 0);
    }
  };

  const poll = async () => {
    await Promise.all(rows.map(async (row) => {
      const endpoint = row.dataset.chatPeekUrl;
      if (!endpoint) return;
      try {
        const url = new URL(endpoint, window.location.origin);
        url.searchParams.set('channel', 'ad_epermit');
        url.searchParams.set('peek', '1');
        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const payload = await response.json();
        if (Number.isFinite(Number(payload.unread_ad_epermit_messages_count))) {
          updateRow(row, payload.unread_ad_epermit_messages_count);
        }
      } catch (e) { /* keep dashboard usable if polling fails */ }
    }));
  };

  setInterval(poll, 15000);
})();
</script>
@endsection
