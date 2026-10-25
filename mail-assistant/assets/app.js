// Фактури от пощата — интерфейс. Говори с api.php.
(function () {
    'use strict';

    const DEST = { invoices: 'Фактури', documents: 'Документи', staff: 'Персонал' };
    const TYPE = {
        invoice: 'Фактура', bills: 'Сметка', receipt: 'Касова бележка', taxes: 'Данъци',
        'revenue-invoice': 'Приходна фактура', other: 'Друго', permit: 'Разрешително',
        contract: 'Договор', trade: 'Търговски документ', statement: 'Извлечение',
        payroll: 'Ведомост', schedule: 'График', personal: 'Личен документ',
    };
    const AUTO_SCAN_AFTER = 10 * 60; // секунди от последната проверка

    const $ = (id) => document.getElementById(id);
    const state = {
        messages: [],
        lastScan: 0,
        scanDays: 30,
        tab: 'todo',
        picks: new Map(),   // „id|section“ → ръчно избрано / отказано
        queue: [],          // файлове, чакащи изпращане
        busy: new Set(),    // „id|section“, които се изпращат в момента
        sending: false,
        scanning: false,
    };

    // ---------- помощни ----------

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    async function api(action, body, params) {
        let url = 'api.php?action=' + encodeURIComponent(action);
        if (params) url += '&' + new URLSearchParams(params).toString();
        const opts = { credentials: 'same-origin' };
        if (body !== undefined) {
            opts.method = 'POST';
            opts.headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'mail-assistant' };
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(url, opts);
        let data;
        try { data = await res.json(); } catch (e) { data = { error: 'Сървърна грешка (HTTP ' + res.status + ')' }; }
        if (res.status === 401 && action !== 'login') {
            showLogin();
            throw new Error('Изтекла сесия — влезте отново.');
        }
        if (!res.ok) throw new Error(data.error || 'HTTP ' + res.status);
        return data;
    }

    function fmtDate(ts) {
        const d = new Date(ts * 1000);
        const now = new Date();
        const time = d.toLocaleTimeString('bg-BG', { hour: '2-digit', minute: '2-digit' });
        if (d.toDateString() === now.toDateString()) return 'днес, ' + time;
        return d.toLocaleDateString('bg-BG', { day: 'numeric', month: 'short' }) + ', ' + time;
    }

    function fmtSize(b) {
        if (b >= 1024 * 1024) return (b / 1024 / 1024).toFixed(1).replace('.', ',') + ' MB';
        return Math.max(1, Math.round(b / 1024)) + ' KB';
    }

    function banner(text, kind) {
        const el = $('banner');
        el.textContent = text || '';
        el.className = 'banner' + (kind ? ' ' + kind : '');
        el.hidden = !text;
    }

    const key = (m, a) => m.id + '|' + a.section;
    const isSent = (a) => a.send && a.send.status === 'sent';
    const canSend = (a) => a.supported && !isSent(a);

    function isPicked(m, a) {
        if (!canSend(a)) return false;
        const k = key(m, a);
        if (state.picks.has(k)) return state.picks.get(k);
        return !m.dismissed && !m.ignored && (m.likely || m.auto);
    }

    function inTab(m, tab) {
        const hidden = m.dismissed || m.ignored;
        if (tab === 'hidden') return hidden;
        if (tab === 'sent') return m.attachments.some(isSent);
        if (hidden) return false;
        if (tab === 'all') return true;
        return (m.likely || m.auto) && m.attachments.some(canSend);
    }

    function replaceMessage(msg) {
        if (!msg) return;
        const i = state.messages.findIndex((m) => m.id === msg.id);
        if (i >= 0) state.messages[i] = msg;
    }

    // ---------- изобразяване ----------

    function statusHtml(m, a) {
        if (state.busy.has(key(m, a))) return '<span class="status busy"><i class="spin"></i>Анализира се…</span>';
        if (state.queue.some((q) => q.id === m.id && q.section === a.section)) return '<span class="status muted">На опашка</span>';
        if (!a.supported) return '<span class="status muted">Неподдържан формат</span>';
        const s = a.send;
        if (!s) return '';
        if (s.status === 'sent') {
            const where = [DEST[s.dest] || s.dest, TYPE[s.savedType] || s.savedType].filter(Boolean).join(' · ');
            return '<span class="status ok" title="' + esc(new Date(s.at * 1000).toLocaleString('bg-BG')) + '">✓ '
                + esc(where || 'Изпратено') + (s.auto ? ' <em>(авт.)</em>' : '') + '</span>'
                + '<button class="link resend" type="button" data-resend="' + esc(key(m, a)) + '">Изпрати пак</button>';
        }
        if (s.status === 'error') return '<span class="status err">' + esc(s.error || 'Грешка') + '</span>';
        return '';
    }

    function cardHtml(m) {
        const atts = m.attachments.map((a) => {
            const k = key(m, a);
            const href = 'api.php?' + new URLSearchParams({ action: 'file', id: m.id, section: a.section });
            return '<li class="att' + (a.supported ? '' : ' unsupported') + '">'
                + '<label><input type="checkbox" data-pick="' + esc(k) + '"'
                + (isPicked(m, a) ? ' checked' : '') + (canSend(a) && !state.busy.has(k) ? '' : ' disabled') + '>'
                + '<span class="ext ext-' + esc(a.ext) + '">' + esc(a.ext.toUpperCase()) + '</span>'
                + '<span class="fname">' + esc(a.filename) + '</span></label>'
                + '<span class="size muted">' + fmtSize(a.size) + '</span>'
                + '<a class="link" href="' + esc(href) + '" target="_blank" rel="noopener">Отвори</a>'
                + '<div class="att-status">' + statusHtml(m, a) + '</div>'
                + '</li>';
        }).join('');
        const chips = m.reasons.map((r) => '<span class="chip">' + esc(r) + '</span>').join('');
        const picked = m.attachments.filter((a) => isPicked(m, a)).length;
        return '<article class="card msg' + (m.likely || m.auto ? ' likely' : '') + '" data-id="' + m.id + '">'
            + '<div class="msg-head"><div class="from"><strong>' + esc(m.fromName || m.fromEmail) + '</strong>'
            + (m.fromName ? ' <span class="muted">' + esc(m.fromEmail) + '</span>' : '') + '</div>'
            + '<time class="muted">' + esc(fmtDate(m.date)) + '</time></div>'
            + '<div class="subject">' + esc(m.subject || '(без тема)') + '</div>'
            + (chips ? '<div class="chips">' + chips + '</div>' : '')
            + '<ul class="atts">' + atts + '</ul>'
            + '<div class="msg-actions">'
            + '<button class="btn small ghost" type="button" data-preview="' + m.id + '">Виж писмото</button>'
            + (m.ignored ? '' : '<button class="btn small ghost" type="button" data-dismiss="' + m.id + '">'
                + (m.dismissed ? 'Върни' : 'Скрий') + '</button>')
            + '<span class="spacer"></span>'
            + (picked ? '<button class="btn small primary" type="button" data-send="' + m.id + '">Изпрати (' + picked + ')</button>' : '')
            + '</div></article>';
    }

    function render() {
        const tabs = $('tabs').querySelectorAll('button');
        tabs.forEach((b) => {
            const t = b.dataset.tab;
            b.classList.toggle('active', t === state.tab);
            const n = state.messages.filter((m) => inTab(m, t)).length;
            b.querySelector('.count').textContent = n ? n : '';
        });

        const items = state.messages.filter((m) => inTab(m, state.tab));
        if (!items.length) {
            const empty = {
                todo: 'Няма нови писма с фактури или плащания за изпращане.',
                all: 'Няма писма с прикачени PDF файлове или снимки за последните ' + state.scanDays + ' дни.',
                sent: 'Още няма изпратени файлове.',
                hidden: 'Няма скрити писма.',
            }[state.tab];
            $('list').innerHTML = '<p class="empty">' + esc(state.scanning && !state.lastScan ? 'Проверка на пощата…' : empty) + '</p>';
        } else {
            $('list').innerHTML = items.map(cardHtml).join('');
        }

        const ago = state.lastScan ? Math.round((Date.now() / 1000 - state.lastScan) / 60) : null;
        $('scan-info').textContent = state.scanning ? 'Проверка на пощата…'
            : ago === null ? 'Пощата още не е проверявана'
            : 'Проверено ' + (ago < 1 ? 'току-що' : 'преди ' + ago + ' мин.');
        $('scan-btn').disabled = state.scanning;

        const picked = [];
        state.messages.forEach((m) => {
            if (!inTab(m, state.tab)) return;
            m.attachments.forEach((a) => { if (isPicked(m, a)) picked.push(a); });
        });
        const pending = state.queue.length + state.busy.size;
        $('sendbar').hidden = !picked.length && !pending;
        $('sendbar-text').textContent = pending
            ? 'Изпращане… остават ' + pending
            : 'Избрани: ' + picked.length + (picked.length === 1 ? ' файл' : ' файла');
        $('sendbar-btn').hidden = !picked.length;
    }

    // ---------- действия ----------

    async function loadList() {
        const d = await api('list');
        state.messages = d.messages;
        state.lastScan = d.lastScan;
        state.scanDays = d.scanDays;
        render();
    }

    async function scan() {
        if (state.scanning) return;
        state.scanning = true;
        render();
        try {
            const d = await api('scan', {});
            state.messages = d.messages;
            state.lastScan = d.lastScan;
            state.scanDays = d.scanDays;
            banner(d.stats.found ? 'Нови писма с прикачени файлове: ' + d.stats.found : '', 'info');
        } catch (e) {
            banner('Проверката на пощата не успя: ' + e.message, 'err');
        } finally {
            state.scanning = false;
            render();
        }
    }

    function enqueue(list) {
        list.forEach((item) => {
            const k = item.id + '|' + item.section;
            if (state.busy.has(k) || state.queue.some((q) => q.id === item.id && q.section === item.section)) return;
            state.queue.push(item);
            state.picks.delete(k);
        });
        render();
        pump();
    }

    // Изпраща по един файл — всеки анализ отнема до минута-две.
    async function pump() {
        if (state.sending) return;
        state.sending = true;
        const failed = [];
        while (state.queue.length) {
            const item = state.queue.shift();
            const k = item.id + '|' + item.section;
            state.busy.add(k);
            render();
            try {
                const d = await api('send', item);
                replaceMessage(d.message);
                if (!d.ok) failed.push(d.error);
            } catch (e) {
                failed.push(e.message);
            }
            state.busy.delete(k);
            render();
        }
        state.sending = false;
        if (failed.length) banner('Неуспешни: ' + failed.length + ' — ' + failed[failed.length - 1], 'err');
        else banner('Файловете са изпратени. Ще се появят в docs.rtdalia.com при следващото му отваряне.', 'ok');
        render();
    }

    function pickedOf(m) {
        return m.attachments.filter((a) => isPicked(m, a)).map((a) => ({ id: m.id, section: a.section }));
    }

    $('list').addEventListener('change', (e) => {
        const k = e.target.dataset.pick;
        if (!k) return;
        state.picks.set(k, e.target.checked);
        render();
    });

    $('list').addEventListener('click', async (e) => {
        const t = e.target.closest('button');
        if (!t) return;
        if (t.dataset.send) {
            const m = state.messages.find((x) => x.id === +t.dataset.send);
            if (m) enqueue(pickedOf(m));
        } else if (t.dataset.resend) {
            const [id, section] = t.dataset.resend.split('|');
            if (confirm('Файлът вече е изпратен. Да се анализира ли отново? Ще се създаде нов документ в docs.')) {
                const item = { id: +id, section: section, force: true };
                enqueue([item]);
            }
        } else if (t.dataset.dismiss) {
            const m = state.messages.find((x) => x.id === +t.dataset.dismiss);
            try {
                const d = await api('dismiss', { id: m.id, value: !m.dismissed });
                replaceMessage(d.message);
                render();
            } catch (err) { banner(err.message, 'err'); }
        } else if (t.dataset.preview) {
            const m = state.messages.find((x) => x.id === +t.dataset.preview);
            $('preview-title').textContent = m.subject || '(без тема)';
            $('preview-from').textContent = (m.fromName ? m.fromName + ' <' + m.fromEmail + '>' : m.fromEmail) + ' · ' + fmtDate(m.date);
            $('preview-text').textContent = 'Зареждане…';
            $('preview').showModal();
            try {
                const d = await api('preview', undefined, { id: m.id });
                $('preview-text').textContent = d.text || '(писмото няма текст)';
            } catch (err) { $('preview-text').textContent = err.message; }
        }
    });

    $('sendbar-btn').addEventListener('click', () => {
        const items = [];
        state.messages.forEach((m) => { if (inTab(m, state.tab)) items.push(...pickedOf(m)); });
        enqueue(items);
    });

    $('tabs').addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (!b) return;
        state.tab = b.dataset.tab;
        render();
    });

    $('scan-btn').addEventListener('click', scan);

    window.addEventListener('beforeunload', (e) => {
        if (state.queue.length) { e.preventDefault(); e.returnValue = ''; }
    });

    // ---------- настройки ----------

    const form = $('settings-form');

    function fillSettings(s) {
        form.keywords.value = s.keywords;
        form.autoSenders.value = s.autoSenders;
        form.ignoreSenders.value = s.ignoreSenders;
        form.scanDays.value = s.scanDays;
        $('settings-info').innerHTML = '<div><span class="muted">Поща:</span> ' + esc(s.imapUser) + ' <span class="muted">('
            + esc(s.imapHost) + ', ' + esc(s.imapFolder) + ')</span></div>'
            + '<div><span class="muted">Документи:</span> ' + esc(s.transcriberUrl) + '</div>';
        $('cron-info').innerHTML = 'За автоматично изпращане добавете в cPanel » Cron Jobs (на всеки 15 минути):<br><code>'
            + esc(s.cronCommand) + '</code>';
    }

    $('settings-btn').addEventListener('click', async () => {
        $('test-result').innerHTML = '';
        try {
            fillSettings((await api('settings')).settings);
            $('settings').showModal();
        } catch (e) { banner(e.message, 'err'); }
    });

    $('settings-save').addEventListener('click', async () => {
        try {
            const d = await api('settings-save', {
                keywords: form.keywords.value,
                autoSenders: form.autoSenders.value,
                ignoreSenders: form.ignoreSenders.value,
                scanDays: +form.scanDays.value,
            });
            state.messages = d.messages;
            state.scanDays = d.scanDays;
            state.picks.clear();
            $('settings').close();
            render();
        } catch (e) { alert(e.message); }
    });

    $('test-btn').addEventListener('click', async () => {
        const out = $('test-result');
        out.textContent = 'Проверка…';
        try {
            const d = await api('test');
            const line = (label, r, extra) => '<div class="' + (r.ok ? 'ok' : 'err') + '">' + (r.ok ? '✓ ' : '✗ ')
                + label + (r.ok ? (extra || '') : ': ' + esc(r.error)) + '</div>';
            out.innerHTML = line('Поща', d.imap, d.imap.gmail ? ' (Gmail)' : '') + line('docs.rtdalia.com', d.docs);
        } catch (e) { out.textContent = e.message; }
    });

    $('logout-btn').addEventListener('click', async () => {
        try { await api('logout', {}); } catch (e) { /* без значение */ }
        $('settings').close();
        showLogin();
    });

    // ---------- вход ----------

    function showLogin() {
        $('screen-login').hidden = false;
        $('screen-list').hidden = true;
        $('top-actions').hidden = true;
        $('sendbar').hidden = true;
        $('scan-info').textContent = '';
        $('login-password').focus();
    }

    async function showApp() {
        $('screen-login').hidden = true;
        $('screen-list').hidden = false;
        $('top-actions').hidden = false;
        try {
            await loadList();
            if (Date.now() / 1000 - state.lastScan > AUTO_SCAN_AFTER) scan();
        } catch (e) { banner(e.message, 'err'); }
    }

    $('login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const err = $('login-error');
        err.hidden = true;
        try {
            await api('login', { password: $('login-password').value });
            $('login-password').value = '';
            showApp();
        } catch (ex) {
            err.textContent = ex.message;
            err.hidden = false;
        }
    });

    // Обновява „Проверено преди N мин.“ всяка минута.
    setInterval(() => { if (!$('screen-list').hidden) render(); }, 60000);

    if (document.body.dataset.authed === '1') showApp(); else showLogin();
})();
