@php
    $chatWidgetId = 'public-bp-chat-' . $application->id . '-' . substr(md5(request()->path()), 0, 8);
    $hour = (int) now(config('app.timezone'))->format('G');
    $isOfficeHours = ! now(config('app.timezone'))->isWeekend() && $hour >= 9 && $hour < 17;
@endphp

<div class="bp-chat-widget" id="{{ $chatWidgetId }}" data-chat-fetch-url="{{ route('public.bp.applications.chat.index', $application->id) }}" data-chat-post-url="{{ route('public.bp.applications.chat.store', $application->id) }}" data-chat-csrf="{{ csrf_token() }}" data-office-hours="{{ $isOfficeHours ? '1' : '0' }}">
    <button class="bp-chat-launcher" type="button" data-chat-open>
        <span class="bp-chat-launcher-icon">✦</span>
        <span>AI Help</span>
    </button>

    <div class="bp-chat-popup" data-chat-popup>
        <div class="bp-chat-header">
            <div>
                <div class="bp-chat-title">Building Plan Assistant</div>
                <div class="bp-chat-subtitle">AI + AD ePermit live chat</div>
            </div>
            <button class="bp-chat-close" type="button" data-chat-close>&times;</button>
        </div>

        <div class="bp-chat-tabs">
            <button type="button" class="bp-chat-tab is-active" data-channel="ai">AI Chat</button>
            <button type="button" class="bp-chat-tab" data-channel="ad_epermit">AD ePermit Live</button>
        </div>

        <div class="bp-chat-office-note" data-office-note style="display:none;">AD ePermit live chat is available in office hours only.</div>
        <div class="bp-chat-body" data-chat-body></div>

        <form class="bp-chat-form" data-chat-form>
            <input type="text" data-chat-input placeholder="Ask about your application..." autocomplete="off" />
            <button type="submit">Send</button>
            <div class="bp-chat-status" data-chat-status></div>
        </form>
    </div>
</div>

<style>
.bp-chat-widget{position:fixed;right:24px;bottom:24px;z-index:2000}
.bp-chat-launcher{display:flex;align-items:center;gap:8px;border:0;border-radius:999px;padding:12px 18px;background:linear-gradient(135deg,#0f766e,#113f67);color:#fff;font-weight:800;box-shadow:0 18px 40px rgba(17,63,103,.28)}
.bp-chat-launcher-icon{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.18)}
.bp-chat-popup{display:none;width:min(420px,calc(100vw - 24px));height:min(640px,calc(100vh - 44px));background:#fff;border-radius:18px;overflow:hidden;border:1px solid rgba(15,118,110,.18);box-shadow:0 24px 80px rgba(15,23,42,.28)}
.bp-chat-widget.is-open .bp-chat-popup{display:flex;flex-direction:column}
.bp-chat-widget.is-open .bp-chat-launcher{display:none}
.bp-chat-header{display:flex;align-items:center;justify-content:space-between;padding:14px;color:#fff;background:radial-gradient(circle at 10% 0%,#4fd1c5 0,#0f766e 35%,#102a43 100%)}
.bp-chat-title{font-size:16px;font-weight:800}
.bp-chat-subtitle{font-size:11px;opacity:.88}
.bp-chat-close{border:0;background:rgba(255,255,255,.16);color:#fff;width:30px;height:30px;border-radius:50%;font-size:22px;line-height:1}
.bp-chat-tabs{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:10px;border-bottom:1px solid #e2e8f0}
.bp-chat-tab{border:1px solid #cbd5e1;border-radius:8px;background:#fff;padding:8px;font-weight:700}
.bp-chat-tab.is-active{border-color:#0f766e;background:#e6fffa;color:#0f766e}
.bp-chat-office-note{font-size:12px;color:#9a3412;background:#fff7ed;border:1px solid #fdba74;margin:0 10px 8px;border-radius:8px;padding:8px}
.bp-chat-body{flex:1;overflow:auto;padding:14px;background:linear-gradient(180deg,#f7fbfa,#eef5f1)}
.bp-chat-message{margin-bottom:10px;max-width:88%}
.bp-chat-message.user{margin-left:auto;text-align:right}
.bp-chat-role{font-size:10px;color:#64748b;margin-bottom:2px;font-weight:800;text-transform:uppercase}
.bp-chat-bubble{display:inline-block;padding:10px 12px;border-radius:14px;line-height:1.4;white-space:pre-line}
.bp-chat-message.assistant .bp-chat-bubble{background:#fff;color:#1f2937}
.bp-chat-message.user .bp-chat-bubble{background:#0f766e;color:#fff}
.bp-chat-form{padding:10px;border-top:1px solid #e5e7eb;display:grid;grid-template-columns:1fr auto;gap:8px}
.bp-chat-form input{border:1px solid #d9e2ec;border-radius:999px;padding:10px 12px}
.bp-chat-form button{border:0;border-radius:999px;padding:0 14px;background:#0f766e;color:#fff;font-weight:800}
.bp-chat-status{grid-column:1/-1;font-size:11px;color:#0f766e;min-height:14px}
@media(max-width:640px){.bp-chat-widget{right:10px;bottom:10px}.bp-chat-popup{height:calc(100vh - 20px)}}
</style>

<script>
(() => {
  const root = document.getElementById(@json($chatWidgetId));
  if (!root) return;

  const openBtn = root.querySelector('[data-chat-open]');
  const closeBtn = root.querySelector('[data-chat-close]');
  const body = root.querySelector('[data-chat-body]');
  const form = root.querySelector('[data-chat-form]');
  const input = root.querySelector('[data-chat-input]');
  const status = root.querySelector('[data-chat-status]');
  const officeNote = root.querySelector('[data-office-note]');
  const tabs = [...root.querySelectorAll('.bp-chat-tab')];

  let activeChannel = 'ai';
  let lastMessageId = { ai: 0, ad_epermit: 0 };

  const roleLabel = (r) => {
    if (r === 'assistant') return 'AI Assistant';
    if (r === 'ad_epermit') return 'AD ePermit';
    return 'Applicant';
  };

  const allowedAdLive = () => root.dataset.officeHours === '1';

  const setChannel = (ch) => {
    activeChannel = ch;
    tabs.forEach(t => t.classList.toggle('is-active', t.dataset.channel === ch));
    const blocked = ch === 'ad_epermit' && !allowedAdLive();
    officeNote.style.display = blocked ? 'block' : 'none';
    input.placeholder = ch === 'ai' ? 'Ask the AI assistant...' : 'Message AD ePermit officer...';
    fetchMessages();
  };

  const renderMessages = (messages) => {
    if (!Array.isArray(messages)) return;
    const html = messages.map(m => {
      const role = String(m.role || '').toLowerCase();
      const safe = (m.message || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
      const userClass = activeChannel === 'ai' ? (role === 'assistant' ? 'assistant' : 'user') : (role === 'ad_epermit' ? 'assistant' : 'user');
      lastMessageId[activeChannel] = Math.max(lastMessageId[activeChannel] || 0, Number(m.id || 0));
      return `<div class="bp-chat-message ${userClass}"><div class="bp-chat-role">${roleLabel(role)}</div><div class="bp-chat-bubble">${safe.replace(/\n/g,'<br>')}</div></div>`;
    }).join('');
    body.innerHTML = html || '<div class="text-muted small">Start the conversation.</div>';
    body.scrollTop = body.scrollHeight;
  };

  const fetchMessages = async () => {
    try {
      const url = new URL(root.dataset.chatFetchUrl, window.location.origin);
      url.searchParams.set('channel', activeChannel);
      const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
      if (!res.ok) return;
      const data = await res.json();
      renderMessages(data.messages || []);
    } catch (e) { /* noop */ }
  };

  const poll = async () => {
    try {
      const since = lastMessageId[activeChannel] || 0;
      const url = new URL(root.dataset.chatFetchUrl, window.location.origin);
      url.searchParams.set('channel', activeChannel);
      if (since > 0) url.searchParams.set('since_id', String(since));
      const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
      if (!res.ok) return;
      const data = await res.json();
      const incoming = Array.isArray(data.messages) ? data.messages : [];
      if (incoming.length > 0) {
        const existing = body.querySelectorAll('.bp-chat-message').length;
        if (existing === 0 || since === 0) {
          fetchMessages();
        } else {
          const merged = [];
          body.querySelectorAll('.bp-chat-message').forEach(el => {
            merged.push({ role: el.classList.contains('assistant') ? (activeChannel === 'ai' ? 'assistant' : 'ad_epermit') : 'user', message: el.querySelector('.bp-chat-bubble')?.innerText || '' });
          });
          renderMessages([...merged, ...incoming]);
        }
      }
    } catch (e) { /* noop */ }
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = (input.value || '').trim();
    if (!message) return;
    if (activeChannel === 'ad_epermit' && !allowedAdLive()) {
      status.textContent = 'Live AD ePermit chat is currently offline (office hours only).';
      return;
    }
    status.textContent = 'Sending...';
    try {
      const res = await fetch(root.dataset.chatPostUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': root.dataset.chatCsrf,
        },
        body: JSON.stringify({ message, channel: activeChannel }),
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        status.textContent = data.message || 'Unable to send message.';
        return;
      }
      input.value = '';
      renderMessages(data.messages || []);
      status.textContent = 'Delivered';
      setTimeout(() => status.textContent = '', 1200);
    } catch (err) {
      status.textContent = 'Network error. Please retry.';
    }
  });

  tabs.forEach(tab => tab.addEventListener('click', () => setChannel(tab.dataset.channel)));
  openBtn.addEventListener('click', () => { root.classList.add('is-open'); fetchMessages(); });
  closeBtn.addEventListener('click', () => root.classList.remove('is-open'));

  setChannel('ai');
  setInterval(() => { if (root.classList.contains('is-open')) poll(); }, 6000);
})();
</script>
