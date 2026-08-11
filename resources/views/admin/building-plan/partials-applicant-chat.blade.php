@php
    $chatWidgetId = 'bp-applicant-chat-' . $application->id . '-' . substr(md5(request()->path()), 0, 8);
@endphp

<div class="card mb-3 portal-enter">
    <div class="card-header">Applicant Chat</div>
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-md-6"><strong>Applicant:</strong> {{ $application->applicant_name }}</div>
            <div class="col-md-6"><strong>CNIC:</strong> {{ $application->applicant_cnic }}</div>
            <div class="col-md-6"><strong>Phone:</strong> {{ $application->applicant_phone }}</div>
            <div class="col-md-6"><strong>Email:</strong> {{ $application->applicant_email }}</div>
            <div class="col-md-6"><strong>Plot:</strong> {{ $application->scheme_name ?: $application->scheme }} / {{ $application->block_name ?: $application->block }} / {{ $application->plot_no ?: $application->plot_ref }}</div>
            <div class="col-md-6"><strong>Status:</strong> {{ $application->current_status ?: $application->status }}</div>
        </div>

        <div class="bp-chat-widget bp-chat-widget-inline" id="{{ $chatWidgetId }}"
             data-chat-fetch-url="{{ route('admin.plan.bp.ad.applicant-chat.index', $application) }}"
             data-chat-post-url="{{ route('admin.plan.bp.ad.applicant-chat.store', $application) }}"
             data-chat-csrf="{{ csrf_token() }}">
            <button class="bp-chat-launcher" type="button" data-chat-open>
                <span class="bp-chat-launcher-icon">✦</span>
                <span>Open Applicant Chat</span>
            </button>

            <div class="bp-chat-popup" data-chat-popup>
                <div class="bp-chat-header">
                    <div>
                        <div class="bp-chat-title">Applicant Thread</div>
                        <div class="bp-chat-subtitle">Direct chat with the applicant. Stored in database.</div>
                    </div>
                    <button class="bp-chat-close" type="button" data-chat-close>&times;</button>
                </div>

                <div class="bp-chat-case-summary">
                    <div><strong>Application:</strong> {{ $application->application_no }}</div>
                    <div><strong>Applicant:</strong> {{ $application->applicant_name }}</div>
                    <div><strong>Plot:</strong> {{ $application->scheme_name ?: $application->scheme }} / {{ $application->block_name ?: $application->block }} / {{ $application->plot_no ?: $application->plot_ref }}</div>
                </div>

                <div class="bp-chat-body" data-chat-body></div>

                <form class="bp-chat-form" data-chat-form>
                    <input type="text" data-chat-input placeholder="Message the applicant..." autocomplete="off" required />
                    <button type="submit" data-chat-send>Send</button>
                    <div class="bp-chat-status" data-chat-status></div>
                </form>
            </div>
        </div>
    </div>
</div>

@once
<style>
.bp-chat-widget-inline{position:relative;right:auto;bottom:auto;z-index:auto}
.bp-chat-widget-inline .bp-chat-launcher{position:relative}
.bp-chat-widget-inline.is-open .bp-chat-popup{display:flex}
.bp-chat-widget-inline .bp-chat-popup{margin-top:12px;width:100%;height:520px;display:none}
.bp-chat-case-summary{padding:12px 14px;border-bottom:1px solid #e5e7eb;background:#f8fbff;font-size:13px;color:#0f172a;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 12px}
.bp-chat-case-summary strong{color:#0f172a}
</style>
@endonce

@push('footer_scripts_inline')
<script>
(function() {
    const root = document.getElementById(@json($chatWidgetId));
    if (!root || root.dataset.bound === '1') return;
    root.dataset.bound = '1';

    const body = root.querySelector('[data-chat-body]');
    const form = root.querySelector('[data-chat-form]');
    const input = root.querySelector('[data-chat-input]');
    const send = root.querySelector('[data-chat-send]');
    const status = root.querySelector('[data-chat-status]');
    const openBtn = root.querySelector('[data-chat-open]');
    const closeBtn = root.querySelector('[data-chat-close]');

    let isLoading = false;
    let isSending = false;
    let lastMessageId = 0;

    function esc(text) {
        return String(text || '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
    }

    function roleLabel(role) {
        const normalized = String(role || '').toLowerCase();
        if (normalized === 'ad_epermit') return 'AD ePermit';
        return 'Applicant';
    }

    function setStatus(text, tone = '') {
        status.textContent = text || '';
        status.dataset.tone = tone;
    }

    function render(messages) {
        if (!Array.isArray(messages) || messages.length === 0) {
            body.innerHTML = '<div class="text-muted small">No applicant messages yet.</div>';
            return;
        }

        body.innerHTML = messages.map((msg) => {
            const role = String(msg.role || '').toLowerCase();
            const isAd = role === 'ad_epermit';
            const message = esc(msg.message || '').replace(/\n/g, '<br>');
            return `<div class="bp-chat-message ${isAd ? 'assistant' : 'user'}"><div class="bp-chat-role">${roleLabel(role)}</div><div class="bp-chat-bubble">${message}</div></div>`;
        }).join('');
        body.scrollTop = body.scrollHeight;
    }

    async function readJsonResponse(response) {
        const text = await response.text();
        if (!text) return {};
        try {
            return JSON.parse(text);
        } catch (e) {
            return { ok: false, message: text.slice(0, 250) || 'Unexpected response.' };
        }
    }

    async function refresh(force = false) {
        if (isLoading) return;
        isLoading = true;
        try {
            const since = force ? 0 : lastMessageId;
            const url = new URL(root.dataset.chatFetchUrl, window.location.origin);
            if (since > 0) url.searchParams.set('since_id', String(since));
            const response = await fetch(url.toString(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('Unable to load chat messages.');
            const data = await readJsonResponse(response);
            const messages = Array.isArray(data.messages) ? data.messages : [];
            if (messages.length) {
                lastMessageId = Math.max(lastMessageId, ...messages.map((msg) => Number(msg.id || 0)));
            }
            render(messages);
            setStatus('');
        } catch (e) {
            setStatus('Chat could not be loaded right now.', 'error');
        } finally {
            isLoading = false;
        }
    }

    async function submit(event) {
        event.preventDefault();
        const message = (input.value || '').trim();
        if (!message || isSending) return;

        isSending = true;
        send.disabled = true;
        setStatus('Sending...');

        try {
            const payload = new URLSearchParams();
            payload.set('_token', root.dataset.chatCsrf);
            payload.set('message', message);

            const response = await fetch(root.dataset.chatPostUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                credentials: 'same-origin',
                body: payload.toString(),
            });

            const data = await readJsonResponse(response);
            if (!response.ok || data.ok === false) {
                throw new Error(data.message || 'Could not send message.');
            }

            input.value = '';
            lastMessageId = 0;
            render(Array.isArray(data.messages) ? data.messages : []);
            setStatus('Message delivered.');
            window.setTimeout(() => setStatus(''), 1200);
        } catch (e) {
            setStatus(e?.message || 'Could not send message.', 'error');
        } finally {
            isSending = false;
            send.disabled = false;
        }
    }

    openBtn.addEventListener('click', () => {
        root.classList.add('is-open');
        refresh(true);
    });
    closeBtn.addEventListener('click', () => root.classList.remove('is-open'));
    form.addEventListener('submit', submit);

    refresh(true);
    setInterval(() => {
        if (root.classList.contains('is-open')) {
            refresh(false);
        }
    }, 5000);
})();
</script>
@endpush
