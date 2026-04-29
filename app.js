const API_KEY_SET = window._API_KEY_SET || false;
let currentConvId = null;
let sending = false;
let isFocusMode = false;
let lastScrollPosition = 0;
let isDarkMode = false;

// ── Upload image ─────────────────────────────────────────────────────────────
let attachedImage = null; // { b64, mime, name }

function handleImageSelect(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { showNotification('Image trop grande (max 5 MB)', 'error'); return; }
    const reader = new FileReader();
    reader.onload = e => {
        const dataUrl = e.target.result;
        const mime    = file.type || 'image/png';
        const b64     = dataUrl.split(',')[1];
        attachedImage = { b64, mime, name: file.name };
        document.getElementById('imgPreview').src = dataUrl;
        document.getElementById('imgPreviewName').textContent = file.name + ' — Pixtral Large activé 🖼️';
        document.getElementById('imgPreviewBar').style.display = 'block';
    };
    reader.readAsDataURL(file);
    input.value = '';
}

function removeImage() {
    attachedImage = null;
    document.getElementById('imgPreviewBar').style.display = 'none';
    document.getElementById('imgPreview').src = '';
}

// ── Capture d'écran ──────────────────────────────────────────────────────────
function captureScreen() {
    const zone = document.getElementById('chatArea');
    if (!zone || zone.style.display === 'none') {
        showNotification('Ouvre une conversation avant de capturer !', 'error');
        return;
    }
    const btn = document.querySelector('[onclick="captureScreen()"]');
    if (btn) { btn.textContent = '⏳'; btn.disabled = true; }

    html2canvas(zone, {
        backgroundColor: getComputedStyle(document.body).backgroundColor || '#f5f4ef',
        scale: 2,
        useCORS: true,
        scrollY: 0,
        height: zone.scrollHeight,
        windowHeight: zone.scrollHeight
    }).then(canvas => {
        const title = document.getElementById('topbarTitle').textContent || 'conversation';
        const date  = new Date().toISOString().slice(0,10);
        const link  = document.createElement('a');
        link.download = `chat-${title.slice(0,30)}-${date}.png`.replace(/[^a-z0-9\-\.]/gi, '_');
        link.href = canvas.toDataURL('image/png');
        link.click();
        if (btn) { btn.textContent = '✅'; btn.disabled = false; setTimeout(() => btn.textContent = '📸', 2000); }
    }).catch(err => {
        console.error(err);
        showNotification('Erreur capture : ' + err.message, 'error');
        if (btn) { btn.textContent = '📸'; btn.disabled = false; }
    });
}

// ── Mode sombre ──────────────────────────────────────────────────────────────
function toggleDarkMode() {
    isDarkMode = !isDarkMode;
    document.documentElement.classList.toggle('dark-mode', isDarkMode);
    document.body.classList.toggle('dark-mode', isDarkMode);
    let styleTag = document.getElementById('dark-override');
    if (!styleTag) {
        styleTag = document.createElement('style');
        styleTag.id = 'dark-override';
        document.head.appendChild(styleTag);
    }
    if (isDarkMode) {
        styleTag.textContent = `
            html,body{background:#0f0f0f!important;color:#e5e5e5!important}
            .main{background:#0f0f0f!important}
            .topbar{background:#1a1a1a!important;border-color:#3a3a3a!important}
            .topbar-title,.msg-text,.msg-name{color:#e5e5e5!important}
            .model-select,.persona-select{background:#262626!important;color:#e5e5e5!important;border-color:#3a3a3a!important}
            .welcome{background:#0f0f0f!important}
            .welcome h1{color:#e5e5e5!important}
            .welcome p{color:#a3a3a3!important}
            .suggestion{background:#1a1a1a!important;border-color:#3a3a3a!important;color:#e5e5e5!important}
            .chat-area{background:#0f0f0f!important}
            .input-zone{background:#1a1a1a!important;border-color:#3a3a3a!important}
            .input-inner{background:#262626!important;border-color:#3a3a3a!important}
            .input-inner textarea{color:#e5e5e5!important;background:#262626!important}
            .msg-avatar.user{background:#3a3a3a!important}
        `;
    } else {
        styleTag.textContent = '';
    }
    localStorage.setItem('darkMode', isDarkMode);
    updateThemeIcon();
}

function updateThemeIcon() {
    const btn = document.getElementById('darkModeToggle');
    if (btn) {
        btn.textContent = isDarkMode ? '☀️' : '🌙';
        btn.title = isDarkMode ? 'Mode clair' : 'Mode sombre';
    }
}

function loadDarkModePreference() {
    const saved = localStorage.getItem('darkMode');
    if (saved === 'true') {
        isDarkMode = true;
        document.body.classList.add('dark-mode');
    }
    updateThemeIcon();
}

// ── Sidebar mobile ───────────────────────────────────────────────────────────
function toggleSidebar() {
    const sidebar  = document.querySelector('.sidebar');
    const overlay  = document.getElementById('sidebarOverlay');
    const isOpen   = sidebar.classList.contains('open');
    sidebar.classList.toggle('open', !isOpen);
    overlay.classList.toggle('open', !isOpen);
}

function toggleFocusMode() {
    document.body.classList.toggle('focus-mode');
    isFocusMode = document.body.classList.contains('focus-mode');
    if (isFocusMode) {
        lastScrollPosition = window.scrollY;
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
        window.scrollTo(0, lastScrollPosition);
    }
}

// ── Copier message ───────────────────────────────────────────────────────────
function copyMsg(btn) {
    const text = btn.getAttribute('data-text');
    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = '✅ Copié !';
        setTimeout(() => btn.textContent = '📋 Copier', 2000);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
        btn.textContent = '✅ Copié !';
        setTimeout(() => btn.textContent = '📋 Copier', 2000);
    });
}

// ── Compteur de caractères ────────────────────────────────────────────────────
function updateCharCount(el) {
    const len = el.value.length;
    const max = 4000;
    const cc  = document.getElementById('charCount');
    if (!cc) return;
    cc.textContent = len + ' / ' + max;
    cc.className   = 'char-count' + (len > max ? ' over' : len > max * 0.8 ? ' warn' : '');
}

// ── Notifications ────────────────────────────────────────────────────────────
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <span class="notification-icon">${type === 'error' ? '❌' : 'ℹ️'}</span>
            <span class="notification-message">${message}</span>
        </div>
        <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
    `;
    document.body.appendChild(notification);

    setTimeout(() => {
        notification.classList.add('show');
    }, 10);

    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => notification.remove(), 300);
    }, 5000);
}

// ── Popup clé API ────────────────────────────────────────────────────────────
function showKeyPopup() {
    document.getElementById('keyPopup').style.display='flex';
    document.getElementById('apiKeyInput').focus();
}
function hideKeyPopup() {
    document.getElementById('keyPopup').style.display='none';
}

// ── Nouvelle conversation ────────────────────────────────────────────────────
async function newConv() {
    const model   = document.getElementById('modelSelect').value;
    const persona = document.getElementById('personaSelect').value;
    const r = await fetch('?api=new_conv', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: `model=${encodeURIComponent(model)}&persona=${encodeURIComponent(persona)}`
    });
    const d = await r.json();
    if (d.success) {
        addConvToSidebar(d.id, 'Nouvelle conversation');
        await loadConv(d.id);
    }
}

function addConvToSidebar(id, title) {
    const list = document.getElementById('convList');
    const empty = list.querySelector('[style]');
    if (empty) empty.remove();
    const el = document.createElement('div');
    el.className = 'conv-item';
    el.id = 'ci-' + id;
    el.onclick = () => loadConv(id);
    el.innerHTML = `<span class="conv-icon">💬</span>
      <span class="conv-title">${esc(title)}</span>
      <span class="conv-time">À l'instant</span>
      <button class="conv-del" onclick="event.stopPropagation();delConv(${id})" title="Supprimer">✕</button>`;
    list.prepend(el);
}

// ── Charger une conversation ─────────────────────────────────────────────────
async function loadConv(id) {
    const r = await fetch(`?api=load&id=${id}`);
    const d = await r.json();
    if (!d.success) return;

    currentConvId = id;

    document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
    const ci = document.getElementById('ci-' + id);
    if (ci) ci.classList.add('active');

    document.getElementById('topbarTitle').textContent = d.conv.title;
    document.getElementById('modelSelect').value   = d.conv.model   || 'mistral-large-latest';
    document.getElementById('personaSelect').value = d.conv.persona || 'assistant';

    document.getElementById('welcomeScreen').style.display = 'none';
    const cs = document.getElementById('chatScreen');
    cs.style.display = 'flex';
    const container = document.getElementById('msgContainer');
    container.innerHTML = '';
    for (const m of d.messages) appendMessage(m.role, m.content, false);

    document.getElementById('convInfo').textContent = d.conv.model;
    document.getElementById('sendBtn').disabled = !window._API_KEY_SET;
    document.getElementById('msgInput').focus();

    scrollBottom();
}

// ── Supprimer une conversation ───────────────────────────────────────────────
async function delConv(id) {
    if (!confirm('Supprimer cette conversation ?')) return;
    await fetch('?api=del_conv', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`id=${id}`
    });
    const el = document.getElementById('ci-' + id);
    if (el) el.remove();
    if (currentConvId === id) {
        currentConvId = null;
        document.getElementById('chatScreen').style.display = 'none';
        document.getElementById('welcomeScreen').style.display = 'flex';
        document.getElementById('topbarTitle').textContent = 'ClaudeLocal';
        document.getElementById('sendBtn').disabled = true;
    }
}

// ── Envoyer un message ───────────────────────────────────────────────────────
async function sendMsg() {
    if (sending || !currentConvId) return;
    const input = document.getElementById('msgInput');
    const msg = input.value.trim();
    if (!msg && !attachedImage) return;

    sending = true;
    const imgSnapshot = attachedImage;
    input.value = '';
    input.style.height = 'auto';
    updateCharCount(input);
    document.getElementById('sendBtn').disabled = true;
    if (attachedImage) removeImage();

    if (msg) appendMessage('user', msg);

    const thinkId = 'think-' + Date.now();
    appendThinking(thinkId);
    scrollBottom();

    try {
        const fd = new FormData();
        fd.append('conv_id', currentConvId);
        if (msg) fd.append('content', msg);
        if (imgSnapshot) {
            fd.append('image_b64',  imgSnapshot.b64);
            fd.append('image_mime', imgSnapshot.mime);
        }
        const r = await fetch('?api=send', { method:'POST', body: fd });
        const d = await r.json();
        removeThinking(thinkId);

        if (d.success) {
            appendMessage('assistant', d.reply);
            const ci = document.getElementById('ci-' + currentConvId);
            if (ci) {
                const titleEl = ci.querySelector('.conv-title');
                if (titleEl && titleEl.textContent === 'Nouvelle conversation') {
                    titleEl.textContent = msg.length > 40 ? msg.slice(0,40)+'…' : msg;
                    document.getElementById('topbarTitle').textContent = titleEl.textContent;
                }
                const timeEl = ci.querySelector('.conv-time');
                if (timeEl) timeEl.textContent = 'À l\'instant';
            }
        } else {
            appendError(d.error || 'Erreur inconnue');
        }
    } catch(e) {
        removeThinking(thinkId);
        appendError('Erreur réseau : ' + e.message);
    }

    sending = false;
    document.getElementById('sendBtn').disabled = !window._API_KEY_SET;
    scrollBottom();
}

// ── Régénérer la dernière réponse ────────────────────────────────────────────
async function regenerate() {
    if (sending || !currentConvId) return;

    sending = true;
    document.getElementById('sendBtn').disabled = true;

    const thinkId = 'think-regenerate-' + Date.now();
    appendThinking(thinkId);
    scrollBottom();

    try {
        const r = await fetch('?api=regenerate', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`conv_id=${currentConvId}`
        });
        const d = await r.json();
        removeThinking(thinkId);

        if (d.success) {
            const lastAssistantMsg = document.querySelector('.msg:last-child .msg-avatar.ai')?.closest('.msg');
            if (lastAssistantMsg) lastAssistantMsg.remove();
            appendMessage('assistant', d.reply);
        } else {
            appendError(d.error || 'Erreur inconnue');
        }
    } catch(e) {
        removeThinking(thinkId);
        appendError('Erreur réseau : ' + e.message);
    }

    sending = false;
    document.getElementById('sendBtn').disabled = !window._API_KEY_SET;
    scrollBottom();
}

// ── Afficher un message ──────────────────────────────────────────────────────
function appendMessage(role, content, scroll=true) {
    const container = document.getElementById('msgContainer');
    const div = document.createElement('div');
    div.className = 'msg';
    const name   = role === 'user' ? 'Vous' : 'ClaudeLocal';
    const avatar = role === 'user' ? '👤' : 'C';
    const avClass = role === 'user' ? 'user' : 'ai';
    const rendered = role === 'user'
        ? `<p>${esc(content).replace(/\n/g,'<br>')}</p>`
        : renderMd(content);

    const now = new Date();
    const timeStr = now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

    div.innerHTML = `
      <div class="msg-avatar ${avClass}">${avatar}</div>
      <div class="msg-content">
        <div class="msg-name">${name}</div>
        <div class="msg-text">${rendered}</div>
        <div class="msg-time" style="color:#78716c;margin-top:.3rem;text-align:right">${timeStr}</div>
        <button class="msg-copy-btn" onclick="copyMsg(this)" data-text="${esc(content)}">📋 Copier</button>
        ${role === 'assistant' ? '<button class="msg-copy-btn" onclick="regenerate()" style="margin-left:.5rem">🔄 Régénérer</button>' : ''}
      </div>`;
    container.appendChild(div);

    if (scroll) {
        scrollBottom();
    }
}

function appendThinking(id) {
    const container = document.getElementById('msgContainer');
    const div = document.createElement('div');
    div.className = 'msg'; div.id = id;
    div.innerHTML = `<div class="msg-avatar ai">C</div>
      <div class="msg-content">
        <div class="msg-name">ClaudeLocal</div>
        <div class="msg-text"><div class="thinking-dots"><span></span><span></span><span></span></div></div>
      </div>`;
    container.appendChild(div);
}

function removeThinking(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
}

function appendError(msg) {
    const container = document.getElementById('msgContainer');
    const div = document.createElement('div');
    div.style.cssText = 'max-width:760px;margin:0 auto;padding:.5rem 1.5rem';
    div.innerHTML = `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.7rem 1rem;color:#dc2626;font-size:.85rem">❌ ${esc(msg)}</div>`;
    container.appendChild(div);
}

// ── Markdown renderer ────────────────────────────────────────────────────────
function renderMd(s) {
    s = s.replace(/```(\w*)\n?([\s\S]*?)```/gm, (_, lang, code) =>
        `<div class="code-block"><div class="code-header"><span class="code-lang">${esc(lang||'code')}</span><button class="copy-btn" onclick="copyCode(this)">📋 Copier</button></div><pre><code>${esc(code)}</code></pre></div>`);
    s = s.replace(/`([^`\n]+)`/g, '<code class="inline-code">$1</code>');
    s = s.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    s = s.replace(/^## (.+)$/gm,  '<h2>$1</h2>');
    s = s.replace(/^# (.+)$/gm,   '<h1>$1</h1>');
    s = s.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
    s = s.replace(/\*\*(.+?)\*\*/g,     '<strong>$1</strong>');
    s = s.replace(/\*(.+?)\*/g,         '<em>$1</em>');
    s = s.replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>');
    s = s.replace(/^[-*•] (.+)$/gm, '<li>$1</li>');
    s = s.replace(/(<li>.*?<\/li>\n?)+/g, m => '<ul>'+m+'</ul>');
    s = s.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
    s = s.replace(/^---$/gm, '<hr>');
    const blocks = s.split(/\n{2,}/);
    s = blocks.map(b => {
        b = b.trim();
        if (!b) return '';
        if (/^<(h[1-3]|ul|ol|blockquote|div|hr)/.test(b)) return b;
        return '<p>' + b.replace(/\n/g,'<br>') + '</p>';
    }).join('\n');
    return s;
}

function copyCode(btn) {
    const code = btn.closest('.code-block').querySelector('code').textContent;
    navigator.clipboard.writeText(code).then(() => {
        btn.textContent = '✅ Copié !';
        setTimeout(() => btn.textContent = '📋 Copier', 2000);
    });
}

// ── Quickstart ───────────────────────────────────────────────────────────────
async function quickStart(text) {
    await newConv();
    document.getElementById('msgInput').value = text;
    sendMsg();
}

// ── Mise à jour modèle/persona ────────────────────────────────────────────────
async function updateConvModel() {
    if (!currentConvId) return;
    const model = document.getElementById('modelSelect').value;
    await fetch('?api=rename', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`id=${currentConvId}&title=${encodeURIComponent(document.getElementById('topbarTitle').textContent)}`});
    document.getElementById('convInfo').textContent = model;
}
async function updateConvPersona() { /* persona stocké à la création */ }

// ── Helpers ──────────────────────────────────────────────────────────────────
function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s||'');
    return d.innerHTML;
}

function scrollBottom() {
    const ca = document.getElementById('chatArea');
    if (ca) {
        if (ca.scrollTop + ca.clientHeight >= ca.scrollHeight - 10) {
            setTimeout(() => ca.scrollTop = ca.scrollHeight, 50);
        }
    }
}

function handleKey(e) {
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        newConv();
    } else if (e.ctrlKey && e.key === 'k') {
        e.preventDefault();
        document.getElementById('msgInput').focus();
    } else if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        captureScreen();
    } else if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        toggleFocusMode();
    } else if (e.ctrlKey && e.key === 'd') {
        e.preventDefault();
        toggleDarkMode();
    } else if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMsg();
    } else if (e.key === 'Escape') {
        e.preventDefault();
        document.getElementById('msgInput').blur();
    }
}

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 200) + 'px';
}

// ── Init ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('msgInput').focus();
    loadDarkModePreference();
    const firstConv = document.querySelector('.conv-item');
    if (firstConv) {
        const id = parseInt(firstConv.id.replace('ci-',''));
        if (id) loadConv(id);
    }

    // Ajout d'un événement pour détecter les changements de taille de fenêtre
    window.addEventListener('resize', () => {
        if (isFocusMode) {
            document.body.style.overflow = 'hidden';
        }
    });
});
