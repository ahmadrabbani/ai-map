@php
    $chatWidgetId = 'bp-chat-' . $application->id . '-' . substr(md5(request()->path()), 0, 8);
    $isAdEpermitUser = (bool) data_get(auth()->user(), 'is_ad_epermit', false)
        || in_array(strtolower((string) data_get(auth()->user(), 'role', '')), ['ad_epermit', 'admin'], true);
    $now = now(config('app.timezone'));
    $isOfficeHours = ! $now->isWeekend() && ((int) $now->format('G') >= 9) && ((int) $now->format('G') < 17);
@endphp

<div class="bp-chat-widget" id="{{ $chatWidgetId }}" data-chat-fetch-url="{{ route('admin.plan.bp.chat.index', $application) }}" data-chat-post-url="{{ route('admin.plan.bp.chat.store', $application) }}" data-chat-csrf="{{ csrf_token() }}" data-office-hours="{{ $isOfficeHours ? '1' : '0' }}" data-is-ad-user="{{ $isAdEpermitUser ? '1' : '0' }}">
    <button class="bp-chat-launcher" type="button" data-chat-open>
        <span class="bp-chat-launcher-icon">✦</span>
        <span>AI Help</span>
    </button>

    <div class="bp-chat-popup" data-chat-popup>
        <div class="bp-chat-header">
            <div>
                <div class="bp-chat-title">Map Approval Specialist</div>
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
            <input type="text" data-chat-input placeholder="Ask AI about your plan..." required autocomplete="off">
            <button type="submit" data-chat-send>Send</button>
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
  if (!root || root.dataset.bound === '1') return;
  root.dataset.bound = '1';

  const fetchUrl = root.dataset.chatFetchUrl;
  const postUrl = root.dataset.chatPostUrl;
  const csrf = root.dataset.chatCsrf;
  const isOfficeHours = root.dataset.officeHours === '1';
  const isAdUser = root.dataset.isAdUser === '1';
  const officeNote = root.querySelector('[data-office-note]');

  const popup = root.querySelector('[data-chat-popup]');
  const body = root.querySelector('[data-chat-body]');
  const form = root.querySelector('[data-chat-form]');
  const input = root.querySelector('[data-chat-input]');
  const send = root.querySelector('[data-chat-send]');
  const status = root.querySelector('[data-chat-status]');
  const tabs = [...root.querySelectorAll('.bp-chat-tab')];

  let activeChannel = 'ai';
  let lastMessageId = { ai: 0, ad_epermit: 0 };
  let isSending = false;
  let isLoading = false;

  function roleLabel(role) {
    const r = String(role || '').toLowerCase();
    if (activeChannel === 'ai') return r === 'assistant' ? 'AI Assistant' : 'You';
    return r === 'ad_epermit' ? 'AD ePermit' : 'Applicant';
  }

  function allowedAdLive() { return isOfficeHours || isAdUser; }

  function setChannel(ch) {
    activeChannel = ch;
    tabs.forEach(btn => btn.classList.toggle('is-active', btn.dataset.channel === ch));
    input.placeholder = ch === 'ai' ? 'Ask AI about your plan...' : 'Write to AD ePermit...';
    const blocked = ch === 'ad_epermit' && !allowedAdLive();
    input.disabled = blocked;
    send.disabled = blocked;
    officeNote.style.display = blocked ? 'block' : 'none';
    render([]);
    refresh(true);
  }

  function render(msgs) {
    if (!Array.isArray(msgs) || !msgs.length) {
      body.innerHTML = '<div class="text-muted">No messages yet.</div>';
      return;
    }
    body.innerHTML = msgs.map(m => {
      const role = String(m.role || '').toLowerCase();
      const userClass = activeChannel === 'ai' ? (role === 'assistant' ? 'assistant' : 'user') : (role === 'ad_epermit' ? 'assistant' : 'user');
      const safe = String(m.message || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
      return `<div class="bp-chat-message ${userClass}"><div class="bp-chat-role">${roleLabel(m.role)}</div><div class="bp-chat-bubble">${safe.replace(/\n/g,'<br>')}</div></div>`;
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

  async function refresh(force) {
    if (isLoading) return;
    isLoading = true;
    try {
      const since = force ? 0 : (lastMessageId[activeChannel] || 0);
      const r = await fetch(`${fetchUrl}?channel=${activeChannel}&since_id=${since}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      if (!r.ok) throw new Error('Unable to load chat messages.');
      const data = await readJsonResponse(r);
      const msgs = Array.isArray(data.messages) ? data.messages : [];
      if (msgs.length) {
        lastMessageId[activeChannel] = Math.max(...msgs.map(x => Number(x.id || 0)), lastMessageId[activeChannel] || 0);
      }
      render(msgs);
      status.textContent = '';
    } catch (_) {
      body.innerHTML = '<div class="text-muted small">Chat could not be loaded right now.</div>';
    } finally {
      isLoading = false;
    }
  }

  root.querySelector('[data-chat-open]').addEventListener('click', () => { root.classList.add('is-open'); refresh(true); });
  root.querySelector('[data-chat-close]').addEventListener('click', () => root.classList.remove('is-open'));
  tabs.forEach(btn => btn.addEventListener('click', () => setChannel(btn.dataset.channel)));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (isSending) return;
    const message = input.value.trim();
    if (!message) return;
    status.textContent = 'Sending...';
    send.disabled = true;
    isSending = true;
    try {
      const p = new URLSearchParams();
      p.set('_token', csrf);
      p.set('message', message);
      p.set('channel', activeChannel);
      const r = await fetch(postUrl, { method: 'POST', headers: { Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8' }, credentials:'same-origin', body: p.toString() });
      const data = await readJsonResponse(r);
      if (!r.ok || data.ok === false) throw new Error(data.message || 'Failed');
      input.value = '';
      status.textContent = 'Message sent.';
      render(Array.isArray(data.messages) ? data.messages : []);
      setTimeout(() => status.textContent = '', 1200);
    } catch (err) {
      status.textContent = err.message || 'Could not send.';
    } finally {
      send.disabled = false;
      isSending = false;
    }
  });

  setChannel('ai');
  setInterval(() => { if (root.classList.contains('is-open')) refresh(false); }, 4000);
})();
</script>
