/* ══════════════════════════════════════════════════════════════
   AI CHAT WIDGET — CoraVergel Resort
   Floating launcher + panel. Talks to /api/ai-chat.php, which
   calls the Claude API server-side (never expose your API key
   in this file or any other client-side code).
══════════════════════════════════════════════════════════════ */
(function () {
    const ENDPOINT = '/api/ai-chat.php'; // adjust if your api/ folder lives elsewhere

    const QUICK_REPLIES = [
        'What rooms are available?',
        'Show me current deals',
        'What time is check-in?',
        'How do I book?'
    ];

    let history = []; // { role: 'user'|'assistant', content: string }
    let sending = false;

    function el(tag, cls, html) {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html !== undefined) e.innerHTML = html;
        return e;
    }

    // Very small, safe markdown-ish renderer: escapes HTML, then
    // re-enables **bold** and line breaks only.
    function renderText(raw) {
        const escaped = raw
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
        return escaped
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    }

    function buildWidget() {
        const launcher = el('button', 'cvchat-launcher', `
            <i class="fa-solid fa-comment-dots"></i>
            <i class="fa-solid fa-xmark"></i>
        `);
        launcher.setAttribute('aria-label', 'Chat with CoraVergel Resort');

        const panel = el('div', 'cvchat-panel');
        panel.innerHTML = `
            <div class="cvchat-head">
                <div class="cvchat-head-avatar"><i class="fa-solid fa-umbrella-beach"></i></div>
                <div class="cvchat-head-text">
                    <div class="cvchat-head-title">CoraVergel Assistant</div>
                    <div class="cvchat-head-sub"><i class="fa-solid fa-circle"></i> Online now</div>
                </div>
                <button class="cvchat-head-close" aria-label="Close chat"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="cvchat-body" id="cvchatBody"></div>
            <div class="cvchat-quick" id="cvchatQuick"></div>
            <div class="cvchat-input-row">
                <textarea id="cvchatInput" rows="1" placeholder="Ask about rooms, deals, check-in..."></textarea>
                <button class="cvchat-send" id="cvchatSend" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
            </div>
            <div class="cvchat-foot">AI-generated replies — please confirm bookings with our staff.</div>
        `;

        document.body.appendChild(launcher);
        document.body.appendChild(panel);

        const body = panel.querySelector('#cvchatBody');
        const quick = panel.querySelector('#cvchatQuick');
        const input = panel.querySelector('#cvchatInput');
        const sendBtn = panel.querySelector('#cvchatSend');
        const closeBtn = panel.querySelector('.cvchat-head-close');

        QUICK_REPLIES.forEach(q => {
            const b = el('button', '', q);
            b.addEventListener('click', () => { input.value = q; sendMessage(); });
            quick.appendChild(b);
        });

        addBotMessage("Hi! I'm the CoraVergel Resort assistant. I can help with room availability, pricing, current deals, and resort policies. What can I help with today?");

        function toggle(open) {
            const isOpen = open !== undefined ? open : !panel.classList.contains('open');
            panel.classList.toggle('open', isOpen);
            launcher.classList.toggle('open', isOpen);
            if (isOpen) input.focus();
        }
        launcher.addEventListener('click', () => toggle());
        closeBtn.addEventListener('click', () => toggle(false));

        input.addEventListener('input', () => {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 90) + 'px';
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
        sendBtn.addEventListener('click', sendMessage);

        function addBotMessage(text) {
            const msg = el('div', 'cvchat-msg cvchat-msg--bot');
            msg.innerHTML = `
                <div class="cvchat-avatar-sm"><i class="fa-solid fa-umbrella-beach"></i></div>
                <div class="cvchat-bubble">${renderText(text)}</div>
            `;
            body.appendChild(msg);
            body.scrollTop = body.scrollHeight;
        }

        function addUserMessage(text) {
            const msg = el('div', 'cvchat-msg cvchat-msg--user');
            msg.innerHTML = `
                <div class="cvchat-avatar-sm"><i class="fa-solid fa-user"></i></div>
                <div class="cvchat-bubble">${renderText(text)}</div>
            `;
            body.appendChild(msg);
            body.scrollTop = body.scrollHeight;
        }

        function showTyping() {
            const t = el('div', 'cvchat-msg cvchat-msg--bot', `
                <div class="cvchat-avatar-sm"><i class="fa-solid fa-umbrella-beach"></i></div>
                <div class="cvchat-typing"><span></span><span></span><span></span></div>
            `);
            t.id = 'cvchatTypingRow';
            body.appendChild(t);
            body.scrollTop = body.scrollHeight;
        }
        function hideTyping() {
            const t = document.getElementById('cvchatTypingRow');
            if (t) t.remove();
        }

        async function sendMessage() {
            const text = input.value.trim();
            if (!text || sending) return;

            sending = true;
            sendBtn.disabled = true;
            input.value = '';
            input.style.height = 'auto';
            quick.style.display = 'none';

            addUserMessage(text);
            history.push({ role: 'user', content: text });
            showTyping();

            try {
                const res = await fetch(ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ messages: history })
                });
                const data = await res.json();
                hideTyping();

                if (!res.ok || data.error) {
                    addBotMessage("Sorry, I'm having trouble responding right now. Please try again in a moment, or reach us directly at coravergelresort@gmail.com.");
                } else {
                    addBotMessage(data.reply);
                    history.push({ role: 'assistant', content: data.reply });
                }
            } catch (err) {
                hideTyping();
                addBotMessage("I couldn't connect just now. Please check your internet connection and try again.");
            } finally {
                sending = false;
                sendBtn.disabled = false;
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', buildWidget);
    } else {
        buildWidget();
    }
})();