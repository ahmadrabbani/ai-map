import React, { useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

function sourceBadge(source) {
  if (source === 'gemini') return { label: 'Gemini', live: true };
  if (source === 'openai') return { label: 'OpenAI', live: true };
  if (source === 'local_fallback') return { label: 'Local fallback', live: false };
  return null;
}

function roleLabel(role, activeChannel) {
  const normalized = String(role || '').toLowerCase();
  if (activeChannel === 'ad_epermit') {
    if (normalized === 'ad_epermit') return 'AD ePermit';
    return 'Applicant';
  }

  if (normalized === 'assistant') return 'AI Assistant';
  return 'You';
}

function messageChannel(msg) {
  return msg?.context_json?.channel || 'ai';
}

function ChatWidget({ config }) {
  const initialOpen = Array.isArray(config.initialMessages) && config.initialMessages.length > 0;
  const [isOpen, setIsOpen] = useState(initialOpen);
  const [activeChannel, setActiveChannel] = useState('ai');
  const [messages, setMessages] = useState(Array.isArray(config.initialMessages) ? config.initialMessages : []);
  const [input, setInput] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [status, setStatus] = useState('');
  const bodyRef = useRef(null);

  const isOfficeHours = Boolean(config.isOfficeHours);
  const isAdEpermitUser = Boolean(config.isAdEpermitUser);
  const adLiveEnabled = isOfficeHours || isAdEpermitUser;

  const channelMessages = useMemo(
    () => messages.filter((msg) => messageChannel(msg) === activeChannel),
    [messages, activeChannel]
  );

  const lastMessageId = useMemo(
    () => channelMessages.reduce((m, item) => Math.max(m, Number(item.id || 0)), 0),
    [channelMessages]
  );

  useEffect(() => {
    if (bodyRef.current) {
      bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
    }
  }, [channelMessages, activeChannel]);

  useEffect(() => {
    let active = true;

    const refreshMessages = async () => {
      try {
        const res = await fetch(`${config.fetchUrl}?channel=${activeChannel}&since_id=${lastMessageId}`, {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        if (!res.ok) return;
        const data = await res.json();
        const incoming = Array.isArray(data.messages) ? data.messages : [];
        if (!incoming.length || !active) return;

        setMessages((prev) => {
          const seen = new Set(prev.map((msg) => Number(msg.id || 0)));
          const merged = [...prev];
          incoming.forEach((msg) => {
            const id = Number(msg.id || 0);
            if (!seen.has(id)) merged.push(msg);
          });
          return merged;
        });
      } catch (e) {
        // polling retry on next interval
      }
    };

    refreshMessages();
    const poll = setInterval(refreshMessages, 4000);

    return () => {
      active = false;
      clearInterval(poll);
    };
  }, [config.fetchUrl, activeChannel, lastMessageId]);

  const handleSubmit = async (event) => {
    event.preventDefault();
    const question = input.trim();
    if (!question || isSubmitting) return;
    if (activeChannel === 'ad_epermit' && !adLiveEnabled) return;

    setIsSubmitting(true);
    setStatus('Sending...');

    try {
      const body = new URLSearchParams();
      body.set('_token', config.csrfToken);
      body.set('message', question);
      body.set('channel', activeChannel);

      const res = await fetch(config.postUrl, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        credentials: 'same-origin',
        body: body.toString(),
      });

      const data = await res.json();
      if (!res.ok || data?.ok === false) {
        throw new Error(data?.message || 'failed');
      }

      const fresh = Array.isArray(data.messages) ? data.messages : [];
      setMessages((prev) => {
        const keepOtherChannels = prev.filter((msg) => messageChannel(msg) !== activeChannel);
        return [...keepOtherChannels, ...fresh];
      });
      setInput('');
      setStatus('Message delivered.');
      setTimeout(() => setStatus(''), 1400);
    } catch (e) {
      setStatus(e?.message || 'Could not send. Please retry.');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className={`bp-chat-widget ${isOpen ? 'is-open' : ''}`}>
      {!isOpen ? (
        <button className="bp-chat-launcher" type="button" aria-label="Open AI chat" onClick={() => setIsOpen(true)}>
          <span className="bp-chat-launcher-icon">✦</span>
          <span className="bp-chat-launcher-text">{config.launcherText || 'AI Help'}</span>
        </button>
      ) : null}

      <div className="bp-chat-popup">
        <div className="bp-chat-header">
          <div>
            <div className="bp-chat-title">
              {activeChannel === 'ai' ? 'Map Approval Specialist (AI)' : 'AD ePermit Live Chat'}
            </div>
            <div className="bp-chat-subtitle">
              {activeChannel === 'ai' ? config.subtitle : 'Real-time human chat during office hours'}
            </div>
          </div>
          <button className="bp-chat-close" type="button" aria-label="Close chat" onClick={() => setIsOpen(false)}>
            &times;
          </button>
        </div>

        <div className="bp-chat-mode-switch">
          <button
            type="button"
            className={`bp-mode-btn ${activeChannel === 'ai' ? 'is-active' : ''}`}
            onClick={() => setActiveChannel('ai')}
          >
            AI Chat
          </button>
          <button
            type="button"
            className={`bp-mode-btn ${activeChannel === 'ad_epermit' ? 'is-active' : ''}`}
            onClick={() => setActiveChannel('ad_epermit')}
          >
            AD ePermit Live
          </button>
        </div>

        {!isOfficeHours ? (
          <div className="bp-office-hours-note">
            Office hours ended. AD ePermit live chat is unavailable right now. You can still chat with AI.
          </div>
        ) : null}

        <div className="bp-chat-body" ref={bodyRef}>
          {channelMessages.length ? (
            channelMessages.map((msg) => {
              const assistant = String(msg.role || '').toLowerCase() === 'assistant';
              const ad = String(msg.role || '').toLowerCase() === 'ad_epermit';
              const badge = assistant ? sourceBadge(msg?.context_json?.source) : null;
              const isUserBubble = activeChannel === 'ai' ? !assistant : !ad;
              return (
                <div key={`bp-chat-msg-${msg.id}`} className={`bp-chat-message ${isUserBubble ? 'is-user' : 'is-assistant'}`}>
                  <div className="bp-chat-role">
                    {roleLabel(msg.role, activeChannel)}
                    {badge ? <span className={`bp-chat-source ${badge.live ? 'is-live' : ''}`}>{badge.label}</span> : null}
                  </div>
                  <div className="bp-chat-bubble" style={{ whiteSpace: 'pre-line' }}>{msg.message}</div>
                </div>
              );
            })
          ) : (
            <div className="bp-chat-empty">
              {activeChannel === 'ai'
                ? config.emptyText
                : 'Start conversation with AD ePermit during office hours. AD ePermit can view this thread.'}
            </div>
          )}
        </div>

        <form className="bp-chat-form" onSubmit={handleSubmit}>
          <input
            type="text"
            name="message"
            placeholder={activeChannel === 'ai' ? 'Ask AI about your plan...' : 'Write to AD ePermit...'}
            required
            autoComplete="off"
            value={input}
            onChange={(e) => setInput(e.target.value)}
            disabled={isSubmitting || (activeChannel === 'ad_epermit' && !adLiveEnabled)}
          />
          <button type="submit" disabled={isSubmitting || (activeChannel === 'ad_epermit' && !adLiveEnabled)}>Send</button>
          <div className="bp-chat-status" aria-live="polite">{status}</div>
          <div className="bp-chat-note">
            {activeChannel === 'ai'
              ? config.noteText
              : 'AD ePermit live thread is visible in AD review and applicant portal.'}
          </div>
        </form>
      </div>
    </div>
  );
}

export function mountBuildingPlanChatWidgets() {
  const nodes = document.querySelectorAll('.bp-chat-react-root[data-chat-config]');
  if (!nodes.length) return;

  nodes.forEach((node) => {
    if (node.dataset.mounted === '1') return;
    node.dataset.mounted = '1';
    const fallback = node.parentElement?.querySelector('[data-bp-chat-fallback]');
    if (fallback) {
      fallback.style.display = 'none';
    }

    let config = {};
    try {
      config = JSON.parse(node.dataset.chatConfig || '{}');
    } catch (e) {
      config = {};
    }

    const root = createRoot(node);
    root.render(<ChatWidget config={config} />);
  });
}
