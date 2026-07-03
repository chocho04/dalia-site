'use strict';

const view = document.getElementById('view');
const modalBackdrop = document.getElementById('modal-backdrop');
const modalEl = document.getElementById('modal');

const MONTHS = ['януари', 'февруари', 'март', 'април', 'май', 'юни',
    'юли', 'август', 'септември', 'октомври', 'ноември', 'декември'];
const DOW = ['нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
const UNITS = { hour: 'на час', day: 'на ден', month: 'на месец' };

const CURRENCY = '€';

// ---------- Помощни ----------

async function api(action, data, params) {
    let url = 'api.php?action=' + action;
    if (params) url += '&' + new URLSearchParams(params);
    const opts = data
        ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }
        : {};
    const res = await fetch(url, opts);
    if (res.status === 401) { location.reload(); throw new Error('Сесията е изтекла.'); }
    const json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Грешка в сървъра.');
    return json;
}

function esc(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
}

// Кръгъл аватар от първото селфи; иначе инициали в тониран кръг
function avatarHtml(p, cls) {
    cls = cls ? ' ' + cls : '';
    if (p.has_avatar == 1) {
        return `<img class="tbl-avatar${cls}" src="selfie.php?avatar=${p.id}" alt="" loading="lazy">`;
    }
    const initials = String(p.name).split(/\s+/).map((w) => w[0]).slice(0, 2).join('');
    return `<span class="tbl-avatar initials c${p.id % 4}${cls}">${esc(initials)}</span>`;
}

function pad(n) { return String(n).padStart(2, '0'); }
function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
function lastDay(year, month) { return new Date(year, month + 1, 0).getDate(); }
function timeOf(dt) { return dt ? dt.slice(11, 16) : ''; }
function timeOf12(dt) {
    if (!dt) return '';
    const [h, m] = dt.slice(11, 16).split(':').map(Number);
    const ap = h < 12 ? 'AM' : 'PM';
    return `${(h % 12) || 12}:${pad(m)} ${ap}`;
}
function toInputDt(dt) { return dt ? dt.slice(0, 16).replace(' ', 'T') : ''; }

function fmtHours(seconds) {
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);
    return h + ':' + pad(m) + ' ч';
}

function fmtMoney(v) {
    return Number(v).toFixed(2) + ' ' + CURRENCY;
}

function modalKeydown(e) {
    if (e.key === 'Escape') {
        e.preventDefault();
        if (!tpEl.hidden) { closeTimePicker(); return; } // първо затваряме избора на час
        closeModal();
    } else if (e.key === 'Enter') {
        const t = e.target;
        if (t.tagName === 'BUTTON' || t.tagName === 'TEXTAREA') return;
        const primary = modalEl.querySelector('.btn.primary, button[type="submit"]');
        if (primary) { e.preventDefault(); primary.click(); }
    }
}
function openModal(html, extraClass) {
    modalEl.className = 'modal' + (extraClass ? ' ' + extraClass : '');
    modalEl.innerHTML = html;
    modalBackdrop.hidden = false;
    document.addEventListener('keydown', modalKeydown);
    const firstInput = modalEl.querySelector('input, select');
    if (firstInput) firstInput.focus();
}
function closeModal() {
    modalBackdrop.hidden = true;
    modalEl.innerHTML = '';
    document.removeEventListener('keydown', modalKeydown);
}
// затваря само ако и натискането, и отпускането са върху фона —
// иначе влачене от модала навън би го затворило
let backdropPressed = false;
modalBackdrop.addEventListener('pointerdown', (e) => { backdropPressed = e.target === modalBackdrop; });
modalBackdrop.addEventListener('pointerup', (e) => {
    if (backdropPressed && e.target === modalBackdrop) closeModal();
    backdropPressed = false;
});

// ---------- Избор на час ----------
// Собствен панел с колони Часове/Минути вместо вградения на браузъра.
// Отваря се при клик върху поле за час; писането в полето продължава да работи.

const tpEl = document.createElement('div');
tpEl.className = 'time-picker';
tpEl.hidden = true;
document.body.appendChild(tpEl);
let tpInput = null;

function closeTimePicker() {
    tpEl.hidden = true;
    tpInput = null;
}

function tpSet(h, m, done) {
    if (!tpInput) return;
    tpInput.value = pad(h) + ':' + pad(m);
    tpInput.dispatchEvent(new Event('change', { bubbles: true }));
    if (done) closeTimePicker();
}

function openTimePicker(input) {
    tpInput = input;
    const [h, m] = /^\d{1,2}:\d{2}/.test(input.value)
        ? input.value.split(':').map(Number) : [12, 0];
    let html = '<div class="tp-col"><div class="tp-head">Час</div>';
    for (let i = 0; i < 24; i++) {
        html += `<button type="button" class="tp-item${i === h ? ' active' : ''}" data-h="${i}">${pad(i)}</button>`;
    }
    html += '</div><div class="tp-col"><div class="tp-head">Мин</div>';
    for (let i = 0; i < 60; i += 5) {
        html += `<button type="button" class="tp-item${i === m ? ' active' : ''}" data-m="${i}">${pad(i)}</button>`;
    }
    html += '</div>';
    tpEl.innerHTML = html;
    const r = input.getBoundingClientRect();
    tpEl.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 160)) + 'px';
    tpEl.style.top = Math.min(r.bottom + 6, window.innerHeight - 280) + 'px';
    tpEl.hidden = false;
    tpEl.querySelectorAll('.tp-item.active').forEach((el) => el.scrollIntoView({ block: 'center' }));

    tpEl.querySelectorAll('[data-h]').forEach((b) =>
        b.addEventListener('click', () => {
            tpEl.querySelectorAll('[data-h]').forEach((x) => x.classList.toggle('active', x === b));
            const mm = tpEl.querySelector('[data-m].active');
            tpSet(Number(b.dataset.h), mm ? Number(mm.dataset.m) : 0, false);
        }));
    tpEl.querySelectorAll('[data-m]').forEach((b) =>
        b.addEventListener('click', () => {
            const hh = tpEl.querySelector('[data-h].active');
            tpSet(hh ? Number(hh.dataset.h) : 12, Number(b.dataset.m), true);
        }));
}

document.addEventListener('click', (e) => {
    const t = e.target;
    if (t instanceof HTMLInputElement && t.type === 'time' && !t.disabled) {
        openTimePicker(t);
        return;
    }
    if (!tpEl.contains(t)) closeTimePicker();
});
window.addEventListener('scroll', closeTimePicker, true);

// ---------- Табове ----------

const tabs = { people: renderPeople, timesheets: renderTimesheets, schedules: renderSchedules,
    reports: renderReports, settings: renderSettings };

document.querySelectorAll('.tab').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach((b) => b.classList.toggle('active', b === btn));
        tabs[btn.dataset.tab]();
    });
});

document.getElementById('logout-btn').addEventListener('click', async () => {
    await api('logout', {});
    location.reload();
});

// ---------- Хора ----------

async function renderPeople() {
    view.innerHTML = '<p class="muted">Зареждане…</p>';
    const people = await api('people');

    const rowHtml = (p) => `
        <tr class="clickable ${p.rate_unit === 'hour' ? '' : 'per-day-row'}${p.active == 1 ? '' : ' muted'}" data-person="${p.id}">
            <td class="drag-col"><span class="drag-handle" title="Влачете за пренареждане">⠿</span></td>
            <td><div class="emp-cell">${avatarHtml(p)}
                <span>${esc(p.name)}${p.active == 1 ? '' : ' <em>(неактивен)</em>'}</span></div></td>
            <td>${esc(p.position)}</td>
            <td class="num">${fmtMoney(p.rate_amount)}${p.rate_unit === 'hour' ? '' : ' ' + (UNITS[p.rate_unit] || '')}</td>
            <td><div class="row-actions">
                <button class="btn edit" data-edit="${p.id}">Редакция</button>
                <button class="btn danger" data-del="${p.id}">Изтрий</button>
            </div></td>
        </tr>`;

    const tableFor = (list, title) => list.length ? `
        <h3 class="ts-section">${title}</h3>
        <table class="data">
            <thead><tr><th class="drag-col"></th><th>Име</th><th>Длъжност</th><th class="num">Ставка</th><th></th></tr></thead>
            <tbody>${list.map(rowHtml).join('')}</tbody>
        </table>` : '';

    const hourly = people.filter((p) => p.rate_unit === 'hour');
    const salaried = people.filter((p) => p.rate_unit !== 'hour');

    const tablesHtml = people.length
        ? tableFor(hourly, 'Почасова ставка') + tableFor(salaried, 'Дневна / месечна ставка')
        : `<table class="data"><thead><tr><th></th><th>Име</th><th>Длъжност</th><th class="num">Ставка</th><th></th></tr></thead>
           <tbody><tr><td colspan="5" class="muted">Няма служители.</td></tr></tbody></table>`;

    view.innerHTML = `
        <div class="toolbar">
            <h2 style="margin:0;font-size:1.1rem">Хора</h2>
            <div class="spacer"></div>
            <button class="btn primary" id="add-person">+ Нов служител</button>
        </div>
        ${tablesHtml}`;

    document.getElementById('add-person').addEventListener('click', () => personModal(null));
    view.querySelectorAll('tr[data-person]').forEach((tr) =>
        tr.addEventListener('click', (e) => {
            // бутоните Редакция/Изтрий и дръжката за влачене си имат свои действия
            if (e.target.closest('button') || e.target.closest('.drag-handle')) return;
            personStatsModal(Number(tr.dataset.person));
        }));

    // пренареждане с влачене; редът се пази глобално и важи за всички списъци
    let dragRow = null;
    view.querySelectorAll('.drag-handle').forEach((h) =>
        h.addEventListener('mousedown', () => { h.closest('tr').draggable = true; }));
    view.querySelectorAll('tr[data-person]').forEach((tr) => {
        tr.addEventListener('dragstart', (e) => {
            dragRow = tr;
            tr.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        tr.addEventListener('dragend', async () => {
            tr.classList.remove('dragging');
            tr.draggable = false;
            dragRow = null;
            const ids = [...view.querySelectorAll('tr[data-person]')].map((r) => Number(r.dataset.person));
            try { await api('people_reorder', { ids }); }
            catch (err) { alert(err.message); renderPeople(); }
        });
    });
    view.querySelectorAll('table.data tbody').forEach((tbody) =>
        tbody.addEventListener('dragover', (e) => {
            // само в рамките на същата таблица (групите са по вид ставка)
            if (!dragRow || dragRow.parentElement !== tbody) return;
            e.preventDefault();
            const rows = [...tbody.querySelectorAll('tr[data-person]:not(.dragging)')];
            const after = rows.find((r) => e.clientY < r.getBoundingClientRect().top + r.offsetHeight / 2);
            if (after) tbody.insertBefore(dragRow, after);
            else tbody.appendChild(dragRow);
        }));
    view.querySelectorAll('[data-edit]').forEach((b) =>
        b.addEventListener('click', () => personModal(people.find((p) => p.id == b.dataset.edit))));
    view.querySelectorAll('[data-del]').forEach((b) =>
        b.addEventListener('click', async () => {
            const p = people.find((x) => x.id == b.dataset.del);
            if (!confirm('Изтриване на „' + p.name + '“?')) return;
            const res = await api('person_delete', { id: p.id });
            if (res.deactivated) alert('Служителят има записи и е деактивиран вместо изтрит.');
            renderPeople();
        }));
}

async function personModal(person) {
    // списъкът с длъжности идва от настройките; смените — за подразбиране в графика
    let positions = [];
    let shiftTypes = [];
    try {
        [positions, shiftTypes] = await Promise.all([
            api('get_settings').then((s) => s.positions || []),
            api('shift_types'),
        ]);
    } catch (err) { /* празни списъци */ }
    const current = person ? person.position : '';
    if (current && !positions.includes(current)) positions.unshift(current);
    const defShift = person ? Number(person.default_shift_id || 0) : 0;
    const defDays = person && person.default_days
        ? String(person.default_days).split(',').map(Number) : [];
    const DAY_NAMES = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];
    openModal(`
        <h2>${person ? 'Редакция на служител' : 'Нов служител'}</h2>
        <form id="person-form">
            <label>Име
                <input type="text" id="pf-name" required value="${person ? esc(person.name) : ''}">
            </label>
            <label>Длъжност
                <select id="pf-position">
                    ${positions.map((p) =>
                        `<option value="${esc(p)}"${p === current ? ' selected' : ''}>${esc(p)}</option>`).join('')}
                </select>
            </label>
            <label>Ставка
                <div class="rate-row">
                    <input type="number" id="pf-rate" min="0" step="0.01" required
                           value="${person ? esc(person.rate_amount) : ''}">
                    <select id="pf-unit">
                        <option value="hour">на час</option>
                        <option value="day">на ден</option>
                        <option value="month">на месец</option>
                    </select>
                </div>
            </label>
            <label>Смяна по подразбиране <span class="muted">(за автоматичен график)</span>
                <select id="pf-defshift">
                    <option value="0">— без —</option>
                    ${shiftTypes.map((t) =>
                        `<option value="${t.id}"${t.id == defShift ? ' selected' : ''}>${esc(t.name)} (${t.start_time}–${t.end_time})</option>`).join('')}
                </select>
            </label>
            <label>Работни дни по подразбиране</label>
            <div class="days-row" id="pf-days">
                ${DAY_NAMES.map((d, i) => `
                    <label class="day-chip">
                        <input type="checkbox" value="${i + 1}" ${defDays.includes(i + 1) ? 'checked' : ''}>
                        <span>${d}</span>
                    </label>`).join('')}
            </div>
            ${person ? `<label class="check-row">
                <input type="checkbox" id="pf-active" ${person.active == 1 ? 'checked' : ''}> Активен
            </label>` : ''}
            <div class="actions">
                <div class="spacer"></div>
                <button type="button" class="btn" id="pf-cancel">Отказ</button>
                <button type="submit" class="btn primary">Запази</button>
            </div>
        </form>`);
    if (person) document.getElementById('pf-unit').value = person.rate_unit;
    document.getElementById('pf-cancel').addEventListener('click', closeModal);
    document.getElementById('person-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api('person_save', {
                id: person ? person.id : 0,
                name: document.getElementById('pf-name').value,
                position: document.getElementById('pf-position').value,
                rate_amount: document.getElementById('pf-rate').value,
                rate_unit: document.getElementById('pf-unit').value,
                active: person ? document.getElementById('pf-active').checked : true,
                default_shift_id: Number(document.getElementById('pf-defshift').value),
                default_days: [...document.querySelectorAll('#pf-days input:checked')]
                    .map((c) => Number(c.value)),
            });
            closeModal();
            renderPeople();
        } catch (err) { alert(err.message); }
    });
}

// Профил на служител: данни, първото селфи и статистика
async function personStatsModal(id) {
    let data;
    try { data = await api('person_stats', null, { id }); } catch (err) { alert(err.message); return; }
    const p = data.employee;
    const s = data.stats;
    const hourly = p.rate_unit === 'hour';
    const fmtDay = (d) => d ? `${Number(d.slice(8, 10))} ${MONTHS[Number(d.slice(5, 7)) - 1]} ${d.slice(0, 4)}` : '—';
    const tile = (val, lbl) => `<div class="stat-tile"><div class="val">${val}</div><div class="lbl">${lbl}</div></div>`;

    openModal(`
        <div class="stats-head">
            <div class="stats-id">
                <h3>${esc(p.name)}${p.active == 1 ? '' : ' <em>(неактивен)</em>'}</h3>
                <p class="muted">${esc(p.position)}</p>
                <p class="rate">${fmtMoney(p.rate_amount)} ${UNITS[p.rate_unit] || ''}</p>
            </div>
            ${avatarHtml(p, 'xl')}
        </div>
        <div class="stats-grid">
            ${hourly
                ? tile(fmtHours(s.month_seconds), 'Часове този месец') + tile(fmtHours(s.total_seconds), 'Часове общо')
                : tile(s.month_days, 'Дни този месец') + tile(s.total_days, 'Дни общо')}
            ${tile(s.total_shifts, 'Смени общо')}
            ${tile(fmtDay(s.last_day), 'Последна смяна')}
        </div>
        <p class="muted" style="font-size:.8rem;margin:12px 0 0">Първа смяна: ${fmtDay(s.first_day)}</p>
        <div class="actions">
            <span class="spacer"></span>
            <button type="button" class="btn" id="st-close">Затвори</button>
        </div>`);
    document.getElementById('st-close').addEventListener('click', closeModal);
}

// ---------- Присъствия ----------

const tsState = (() => {
    const now = new Date();
    return { year: now.getFullYear(), month: now.getMonth(), half: now.getDate() <= 15 ? 1 : 2 };
})();

function tsShift(dir) {
    tsState.half += dir;
    if (tsState.half > 2) { tsState.half = 1; tsState.month++; }
    if (tsState.half < 1) { tsState.half = 2; tsState.month--; }
    if (tsState.month > 11) { tsState.month = 0; tsState.year++; }
    if (tsState.month < 0) { tsState.month = 11; tsState.year--; }
}

function tsRange() {
    const last = lastDay(tsState.year, tsState.month);
    const fromDay = tsState.half === 1 ? 1 : 16;
    const toDay = tsState.half === 1 ? 15 : last;
    const m = pad(tsState.month + 1);
    return {
        from: `${tsState.year}-${m}-${pad(fromDay)}`,
        to: `${tsState.year}-${m}-${pad(toDay)}`,
        fromDay, toDay, last,
    };
}

async function renderTimesheets() {
    view.innerHTML = '<p class="muted">Зареждане…</p>';
    const r = tsRange();
    const data = await api('timesheet', null, { from: r.from, to: r.to });

    // групиране на записите по служител и ден
    const byEmpDay = {};
    for (const e of data.entries) {
        const key = e.employee_id + '|' + e.clock_in.slice(0, 10);
        (byEmpDay[key] = byEmpDay[key] || []).push(e);
    }

    const days = [];
    for (let d = r.fromDay; d <= r.toDay; d++) days.push(d);
    const todayStr = ymd(new Date());

    let head = '<tr><th class="emp-col">Служител</th>';
    for (const d of days) {
        const date = new Date(tsState.year, tsState.month, d);
        const wd = date.getDay();
        head += `<th class="day-col${wd === 0 || wd === 6 ? ' weekend' : ''}">${d}<br><span class="muted">${DOW[wd]}</span></th>`;
    }
    head += '</tr>';

    const buildBody = (emps) => {
        let body = '';
        for (const emp of emps) {
            const rowCls = emp.rate_unit === 'hour' ? '' : ' class="per-day-row"';
            body += `<tr${rowCls}><td class="emp-col"><div class="emp-cell">${avatarHtml(emp, 'sm')}
                <span class="emp-cell-text">${esc(emp.name)}<span class="pos">${esc(emp.position)}</span></span></div></td>`;
            for (const d of days) {
                const dateStr = `${tsState.year}-${pad(tsState.month + 1)}-${pad(d)}`;
                const entries = byEmpDay[emp.id + '|' + dateStr] || [];
                const wd = new Date(tsState.year, tsState.month, d).getDay();
                // при посочване с мишката: начален – краен час на смените
                const times = entries.map((e) =>
                    timeOf(e.clock_in) + ' – ' + (e.clock_out ? timeOf(e.clock_out) : 'на смяна')).join(', ');
                body += `<td class="day-cell${wd === 0 || wd === 6 ? ' weekend' : ''}${entries.length ? ' has-entries' : ''}"
                             data-emp="${emp.id}" data-date="${dateStr}"${times ? ` title="${times}"` : ''}>${dayCellHtml(emp, entries, dateStr, todayStr)}</td>`;
            }
            body += '</tr>';
        }
        return body;
    };

    const tableFor = (emps, title) => emps.length ? `
        <h3 class="ts-section">${title}</h3>
        <div class="ts-scroll">
            <table class="timesheet"><thead>${head}</thead><tbody>${buildBody(emps)}</tbody></table>
        </div>` : '';

    const hourly = data.employees.filter((e) => e.rate_unit === 'hour');
    const salaried = data.employees.filter((e) => e.rate_unit !== 'hour');

    const tablesHtml = data.employees.length
        ? tableFor(hourly, 'Почасова ставка') + tableFor(salaried, 'Дневна / месечна ставка')
        : `<div class="ts-scroll"><table class="timesheet"><thead>${head}</thead>
             <tbody><tr><td class="emp-col muted" colspan="${days.length + 1}">Няма активни служители.</td></tr></tbody></table></div>`;

    const monthName = MONTHS[tsState.month] + ' ' + tsState.year;
    view.innerHTML = `
        <div class="toolbar">
            <button class="btn arrow" id="ts-prev">‹</button>
            <button class="btn ${tsState.half === 1 ? 'active' : ''}" id="ts-h1">1 – 15</button>
            <button class="btn ${tsState.half === 2 ? 'active' : ''}" id="ts-h2">16 – ${r.last}</button>
            <span class="period-label">${monthName}</span>
            <button class="btn arrow" id="ts-next">›</button>
        </div>
        ${tablesHtml}
        <p class="muted" style="font-size:.85rem;padding-top:20px">
            Кликнете върху клетка за преглед на снимките и редакция на часовете.
            Записите, отбелязани с <span class="edited-mark">•</span>, са коригирани от администратор.
        </p>`;

    document.getElementById('ts-prev').addEventListener('click', () => { tsShift(-1); renderTimesheets(); });
    document.getElementById('ts-next').addEventListener('click', () => { tsShift(1); renderTimesheets(); });
    document.getElementById('ts-h1').addEventListener('click', () => { tsState.half = 1; renderTimesheets(); });
    document.getElementById('ts-h2').addEventListener('click', () => { tsState.half = 2; renderTimesheets(); });

    view.querySelectorAll('td.day-cell').forEach((td) =>
        td.addEventListener('click', () => {
            const emp = data.employees.find((x) => x.id == td.dataset.emp);
            const entries = byEmpDay[td.dataset.emp + '|' + td.dataset.date] || [];
            dayModal(emp, td.dataset.date, entries, renderTimesheets);
        }));
}

function dayCellHtml(emp, entries, dateStr, todayStr) {
    if (!entries.length) return '';
    const hourly = emp.rate_unit === 'hour';
    let totalSec = 0;
    let open = false;
    let edited = false;
    let autoClosed = false;
    for (const e of entries) {
        if (e.admin_edited == 1) edited = true;
        if (e.auto_closed == 1) autoClosed = true;
        if (e.clock_out) {
            totalSec += (new Date(e.clock_out.replace(' ', 'T')) - new Date(e.clock_in.replace(' ', 'T'))) / 1000;
        } else {
            open = true;
            // текуща смяна: добавяме натрупаното време до момента
            const elapsed = (Date.now() - new Date(e.clock_in.replace(' ', 'T')).getTime()) / 1000;
            if (elapsed > 0) totalSec += elapsed;
        }
    }
    // числото (часове при почасова / час на вход с AM/PM при на ден/месец);
    // тире след числото = смяната още тече
    const num = hourly
        ? (totalSec > 0
            ? fmtHours(totalSec) + (open ? '<span class="run-dash" title="Смяната тече">–</span>' : '')
            : '')
        : timeOf12(entries[0].clock_in);

    // всички точки и икони — в отделен ред под числото
    let marks = '';
    if (open) marks += '<span class="live-dot" title="На смяна"></span>';
    // при почасова ставка отбелязваме автоматично затворените смени за проверка
    if (hourly && autoClosed) {
        marks += '<span class="auto-mark" title="Автоматично затворена смяна — проверете часа на изход">⚠</span>';
    }
    if (edited) marks += '<span class="edited-mark" title="Коригирано от администратор">•</span>';

    let html = `<div class="${hourly ? 'cell-hours' : 'cell-time'}">${num}</div>`;
    if (marks) html += `<div class="cell-marks">${marks}</div>`;
    return html;
}

function dayThumb(entry, which) {
    const has = entry && ((which === 'in' && entry.has_selfie_in == 1) || (which === 'out' && entry.has_selfie_out == 1));
    if (has) {
        return `<img class="thumb round ${which}" src="selfie.php?id=${entry.id}&which=${which}"
                    alt="${which === 'in' ? 'вход' : 'изход'}" title="Кликнете за увеличение">`;
    }
    return `<span class="thumb round ${which} placeholder" title="Без снимка">—</span>`;
}

function dayModal(emp, dateStr, entries, refresh) {
    const [y, m, d] = dateStr.split('-').map(Number);
    const dateLabel = `${d} ${MONTHS[m - 1]} ${y} г.`;
    const hourly = emp.rate_unit === 'hour';

    const rowHtml = (e) => `
        <div class="day-row" data-id="${e ? e.id : ''}">
            <span class="row-part">${dayThumb(e, 'in')}
                <input type="time" class="row-in" value="${e ? timeOf(e.clock_in) : ''}" title="Вход"></span>
            ${hourly ? `<span class="row-sep">→</span>
            <span class="row-part">${dayThumb(e, 'out')}
                <input type="time" class="row-out" value="${e && e.clock_out ? timeOf(e.clock_out) : ''}"
                       title="Изход (празно = още на смяна)"></span>` : ''}
            <button type="button" class="btn danger row-del" title="Изтрий записа">✕</button>
        </div>
        ${hourly && e && e.auto_closed == 1
            ? '<div class="day-note">⚠ Изходът е зададен автоматично. Коригирайте часа, ако е нужно.</div>'
            : ''}`;

    openModal(`
        <h2>${esc(emp.name)} — ${dateLabel}</h2>
        <div id="day-rows">
            ${entries.map((e) => rowHtml(e)).join('')}
            ${entries.length ? '' : rowHtml(null)}
        </div>
        <div class="actions">
            <button type="button" class="btn" id="day-add">+ Добави</button>
            <div class="spacer"></div>
            <button type="button" class="btn" id="day-cancel">Отказ</button>
            <button type="button" class="btn primary" id="day-save">Запази</button>
        </div>`, hourly ? 'wide' : '');

    const rowsEl = document.getElementById('day-rows');
    const original = {};
    for (const e of entries) original[e.id] = e;

    // клик върху снимка — голям преглед под реда
    rowsEl.addEventListener('click', (ev) => {
        const img = ev.target.closest('img.thumb');
        if (!img) return;
        const row = img.closest('.day-row');
        const existing = row.nextElementSibling;
        if (existing && existing.classList.contains('selfie-preview') && existing.dataset.src === img.src) {
            existing.remove();
            return;
        }
        if (existing && existing.classList.contains('selfie-preview')) existing.remove();
        const big = document.createElement('img');
        big.className = 'selfie-full selfie-preview';
        big.dataset.src = img.src;
        big.src = img.src;
        row.insertAdjacentElement('afterend', big);
    });

    rowsEl.addEventListener('click', async (ev) => {
        const del = ev.target.closest('.row-del');
        if (!del) return;
        const row = del.closest('.day-row');
        const id = row.dataset.id;
        if (id) {
            if (!confirm('Изтриване на записа?')) return;
            try { await api('entry_delete', { id: Number(id) }); }
            catch (err) { alert(err.message); return; }
        }
        const preview = row.nextElementSibling;
        if (preview && preview.classList.contains('selfie-preview')) preview.remove();
        row.remove();
    });

    document.getElementById('day-add').addEventListener('click', () => {
        rowsEl.insertAdjacentHTML('beforeend', rowHtml(null));
    });

    document.getElementById('day-cancel').addEventListener('click', closeModal);

    document.getElementById('day-save').addEventListener('click', async () => {
        const toDt = (time) => dateStr + ' ' + time + ':00';
        // изход преди входа = смяна през полунощ (следващ ден)
        const outDt = (inTime, outTime) => {
            if (outTime > inTime) return toDt(outTime);
            const next = new Date(y, m - 1, d + 1);
            return ymd(next) + ' ' + outTime + ':00';
        };
        try {
            for (const row of rowsEl.querySelectorAll('.day-row')) {
                const inTime = row.querySelector('.row-in').value;
                const outEl = row.querySelector('.row-out');
                const outTime = outEl ? outEl.value : '';
                const id = row.dataset.id;
                if (!inTime) {
                    if (id) throw new Error('Часът на вход е задължителен.');
                    continue; // празен нов ред — пропуска се
                }
                if (id) {
                    const orig = original[id];
                    const changed = timeOf(orig.clock_in) !== inTime ||
                        (orig.clock_out ? timeOf(orig.clock_out) : '') !== outTime;
                    if (!changed) continue;
                    await api('entry_save', {
                        id: Number(id),
                        clock_in: toDt(inTime),
                        clock_out: outTime ? outDt(inTime, outTime) : null,
                    });
                } else {
                    await api('entry_save', {
                        id: 0,
                        employee_id: emp.id,
                        clock_in: toDt(inTime),
                        clock_out: outTime ? outDt(inTime, outTime) : null,
                    });
                }
            }
            closeModal();
            refresh();
        } catch (err) { alert(err.message); }
    });
}

// ---------- Графици ----------

function mondayOf(d) {
    const x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
    return x;
}
function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }

const schedState = { monday: mondayOf(new Date()) };

async function renderSchedules() {
    view.innerHTML = '<p class="muted">Зареждане…</p>';
    const from = ymd(schedState.monday);
    const to = ymd(addDays(schedState.monday, 6));
    const data = await api('schedule', null, { from, to });

    const byEmpDay = {};
    for (const e of data.entries) byEmpDay[e.employee_id + '|' + e.day] = e;

    const days = [];
    for (let i = 0; i < 7; i++) days.push(addDays(schedState.monday, i));
    const todayStr = ymd(new Date());

    // на тесен екран опциите показват съкращението на смяната
    const shiftOptLabel = (t) => (window.innerWidth < 700 && t.abbr) ? t.abbr : t.name;
    const cellHtml = (empId, dateStr, entry) => `
        <div class="sched-cell-inner">
            <select class="sched-select${entry && entry.shift_type_id != 0 ? '' : ' empty'}"
                    data-emp="${empId}" data-day="${dateStr}">
                <option value="0">—</option>
                ${data.shift_types.map((t) =>
                    `<option value="${t.id}"${entry && t.id == entry.shift_type_id ? ' selected' : ''}>${esc(shiftOptLabel(t))}</option>`).join('')}
            </select>
            <input type="text" class="sched-note" data-emp="${empId}" data-day="${dateStr}"
                   value="${entry ? esc(entry.note || '') : ''}" maxlength="200">
        </div>`;

    let head = '<tr><th class="emp-col">Служител</th>';
    for (const d of days) {
        const wd = d.getDay();
        head += `<th class="day-col${wd === 0 || wd === 6 ? ' weekend' : ''}${ymd(d) === todayStr ? ' today' : ''}">
            <span class="sched-date">${d.getDate()}.${d.getMonth() + 1}</span><br><span class="muted">${DOW[wd]}</span></th>`;
    }
    head += '</tr>';

    const bodyFor = (emps) => {
        let body = '';
        for (const emp of emps) {
            body += `<tr><td class="emp-col"><div class="emp-cell">${avatarHtml(emp, 'sm')}
                <span class="emp-cell-text">${esc(emp.name)}<span class="pos">${esc(emp.position)}</span></span></div></td>`;
            for (const d of days) {
                const dateStr = ymd(d);
                const wd = d.getDay();
                body += `<td class="sched-cell${wd === 0 || wd === 6 ? ' weekend' : ''}${dateStr === todayStr ? ' today' : ''}">
                    ${cellHtml(emp.id, dateStr, byEmpDay[emp.id + '|' + dateStr])}</td>`;
            }
            body += '</tr>';
        }
        return body;
    };

    const gridFor = (emps, title) => emps.length ? `
        <h3 class="ts-section">${title}</h3>
        <div class="ts-scroll">
            <table class="timesheet"><thead>${head}</thead><tbody>${bodyFor(emps)}</tbody></table>
        </div>` : '';

    // горен панел: Сервитьор и Барман; долен: всички останали
    const FRONT = ['Сервитьор', 'Барман'];
    const front = data.employees.filter((e) => FRONT.includes(e.position));
    const rest = data.employees.filter((e) => !FRONT.includes(e.position));
    const gridsHtml = data.employees.length
        ? gridFor(front, 'Сервитьори / Бармани') + gridFor(rest, 'Кухня')
        : '<p class="muted">Няма активни служители.</p>';

    const label = `${days[0].getDate()} ${MONTHS[days[0].getMonth()]} – ` +
        `${days[6].getDate()} ${MONTHS[days[6].getMonth()]} ${days[6].getFullYear()}`;

    const shiftRow = (t) => `
        <div class="shift-row">
            <div class="shift-info">
                <strong>${esc(t.name)}${t.abbr ? ` <span class="muted">(${esc(t.abbr)})</span>` : ''}</strong>
                <span class="muted">${esc(t.start_time)} – ${esc(t.end_time)}${t.cutoff_time ? ` · авт. в ${esc(t.cutoff_time)}` : ''}</span>
            </div>
            <div class="row-actions">
                <button class="btn edit" data-sh-edit="${t.id}">Промяна</button>
                <button class="btn danger" data-sh-del="${t.id}" title="Изтрий">✕</button>
            </div>
        </div>`;

    view.innerHTML = `
        <div class="toolbar">
            <button class="btn arrow" id="sc-prev">‹</button>
            <span class="period-label">${label}</span>
            <button class="btn arrow" id="sc-next">›</button>
        </div>
        <div class="sched-layout">
            <div class="sched-grid">
                ${gridsHtml}
            </div>
            <div class="sched-side">
                <div class="settings-card">
                    <h2>Смени</h2>
                    ${data.shift_types.length
                        ? data.shift_types.map(shiftRow).join('')
                        : '<p class="muted" style="font-size:.86rem">Няма дефинирани смени. Добавете първата.</p>'}
                    <button class="btn primary" id="sh-add" style="margin-top:14px">+ Нова смяна</button>
                </div>
                <div class="settings-card" style="margin-top:20px">
                    <h2>Заявки за промяна${data.requests.length ? ` <span class="req-badge">${data.requests.length}</span>` : ''}</h2>
                    ${data.requests.length ? data.requests.map((r) => `
                        <div class="req-row">
                            <div class="req-info">
                                <strong>${esc(r.employee_name)}</strong>
                                <span class="muted">${(() => { const d = new Date(r.day + 'T00:00:00');
                                    return d.getDate() + ' ' + MONTHS[d.getMonth()]; })()}:
                                    ${esc(r.current_name || 'почивка')} → ${esc(r.requested_name || 'почивка')}</span>
                            </div>
                            <div class="row-actions">
                                <button class="btn edit" data-rq-ok="${r.id}" title="Одобри">✓</button>
                                <button class="btn danger" data-rq-no="${r.id}" title="Откажи">✕</button>
                            </div>
                        </div>`).join('')
                        : '<p class="muted" style="font-size:.86rem">Няма чакащи заявки.</p>'}
                </div>
            </div>
        </div>`;

    document.getElementById('sc-prev').addEventListener('click', () => {
        schedState.monday = addDays(schedState.monday, -7); renderSchedules();
    });
    document.getElementById('sc-next').addEventListener('click', () => {
        schedState.monday = addDays(schedState.monday, 7); renderSchedules();
    });

    // запис на клетка (смяна + коментар) при промяна на което и да е от двете
    const cellSave = async (el) => {
        const q = `[data-emp="${el.dataset.emp}"][data-day="${el.dataset.day}"]`;
        const sel = view.querySelector('.sched-select' + q);
        const note = view.querySelector('.sched-note' + q);
        sel.disabled = true; note.disabled = true;
        try {
            await api('schedule_set', {
                employee_id: Number(el.dataset.emp),
                day: el.dataset.day,
                shift_type_id: Number(sel.value),
                note: note.value,
            });
            sel.classList.toggle('empty', sel.value === '0');
            el.classList.add('saved-blink');
            setTimeout(() => el.classList.remove('saved-blink'), 800);
        } catch (err) { alert(err.message); }
        sel.disabled = false; note.disabled = false;
    };
    view.querySelectorAll('.sched-select, .sched-note').forEach((el) =>
        el.addEventListener('change', () => cellSave(el)));

    // ширината на коментара следва текста (резервен вариант за браузъри без field-sizing)
    const sizeNote = (inp) => {
        inp.style.width = Math.max(30, Math.min(150, inp.value.length * 7 + 16)) + 'px';
    };
    view.querySelectorAll('.sched-note').forEach((inp) => {
        sizeNote(inp);
        inp.addEventListener('input', () => sizeNote(inp));
    });

    const resolveReq = async (id, approve) => {
        try { await api('shift_request_resolve', { id, approve: approve ? 1 : 0 }); }
        catch (err) { alert(err.message); return; }
        renderSchedules();
    };
    view.querySelectorAll('[data-rq-ok]').forEach((b) =>
        b.addEventListener('click', () => resolveReq(Number(b.dataset.rqOk), true)));
    view.querySelectorAll('[data-rq-no]').forEach((b) =>
        b.addEventListener('click', () => resolveReq(Number(b.dataset.rqNo), false)));

    document.getElementById('sh-add').addEventListener('click', () => shiftTypeModal(null));
    view.querySelectorAll('[data-sh-edit]').forEach((b) =>
        b.addEventListener('click', () => shiftTypeModal(data.shift_types.find((t) => t.id == b.dataset.shEdit))));
    view.querySelectorAll('[data-sh-del]').forEach((b) =>
        b.addEventListener('click', async () => {
            const t = data.shift_types.find((x) => x.id == b.dataset.shDel);
            if (!confirm('Изтриване на смяна „' + t.name + '“? Ще бъде премахната и от графика.')) return;
            try { await api('shift_type_delete', { id: t.id }); } catch (err) { alert(err.message); return; }
            renderSchedules();
        }));
}

function shiftTypeModal(t) {
    openModal(`
        <h3>${t ? 'Промяна на смяна' : 'Нова смяна'}</h3>
        <form id="shift-form">
            <label>Име
                <input type="text" id="shf-name" value="${t ? esc(t.name) : ''}" required maxlength="100">
            </label>
            <label>Съкращение <span class="muted">(за мобилен изглед)</span>
                <input type="text" id="shf-abbr" value="${t ? esc(t.abbr || '') : ''}" maxlength="10">
            </label>
            <div class="rate-row">
                <label>От <input type="time" id="shf-start" value="${t ? t.start_time : '08:00'}" required></label>
                <label>До <input type="time" id="shf-end" value="${t ? t.end_time : '16:00'}" required></label>
            </div>
            <label class="check-row">
                <input type="checkbox" id="shf-ac"${t && t.cutoff_time ? ' checked' : ''}>
                Автоматично приключване
            </label>
            <label>Час на приключване
                <span class="muted">(забравена отворена смяна се затваря сама в този час)</span>
                <input type="time" id="shf-cutoff" value="${t && t.cutoff_time ? esc(t.cutoff_time) : '01:30'}"
                       required ${t && t.cutoff_time ? '' : 'disabled'}>
            </label>
            <div class="actions">
                <span class="spacer"></span>
                <button type="button" class="btn" id="shf-cancel">Отказ</button>
                <button type="submit" class="btn primary">Запази</button>
            </div>
        </form>`);
    document.getElementById('shf-cancel').addEventListener('click', closeModal);
    const shfAc = document.getElementById('shf-ac');
    const shfCutoff = document.getElementById('shf-cutoff');
    shfAc.addEventListener('change', () => { shfCutoff.disabled = !shfAc.checked; });
    document.getElementById('shift-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api('shift_type_save', {
                id: t ? t.id : 0,
                name: document.getElementById('shf-name').value,
                abbr: document.getElementById('shf-abbr').value,
                start_time: document.getElementById('shf-start').value,
                end_time: document.getElementById('shf-end').value,
                cutoff_time: shfAc.checked ? shfCutoff.value : '',
            });
            closeModal();
            renderSchedules();
        } catch (err) { alert(err.message); }
    });
}

// ---------- Справки ----------

const repState = (() => {
    const now = new Date();
    return { year: now.getFullYear(), month: now.getMonth(), mode: 'month' }; // mode: h1|h2|month|custom
})();

function repRange() {
    if (repState.mode === 'custom') return { from: repState.from, to: repState.to };
    const m = pad(repState.month + 1);
    const last = lastDay(repState.year, repState.month);
    if (repState.mode === 'h1') return { from: `${repState.year}-${m}-01`, to: `${repState.year}-${m}-15` };
    if (repState.mode === 'h2') return { from: `${repState.year}-${m}-16`, to: `${repState.year}-${m}-${last}` };
    return { from: `${repState.year}-${m}-01`, to: `${repState.year}-${m}-${last}` };
}

function repShiftMonth(dir) {
    repState.month += dir;
    if (repState.month > 11) { repState.month = 0; repState.year++; }
    if (repState.month < 0) { repState.month = 11; repState.year--; }
    if (repState.mode === 'custom') repState.mode = 'month';
}

async function renderReports() {
    view.innerHTML = '<p class="muted">Зареждане…</p>';
    const r = repRange();
    // статусът "платено" важи само за половинмесечните периоди (1-15 / 16-край)
    const period = (repState.mode === 'h1' || repState.mode === 'h2')
        ? `${repState.year}-${pad(repState.month + 1)}-${repState.mode === 'h1' ? '1' : '2'}`
        : null;
    const params = { from: r.from, to: r.to };
    if (period) params.period = period;
    const data = await api('report', null, params);

    const hourlyRows = data.rows.filter((x) => x.rate_unit === 'hour');
    const salariedRows = data.rows.filter((x) => x.rate_unit !== 'hour');
    const total = data.rows.reduce((s, x) => s + x.amount, 0);

    const paidToggle = (row) => period
        ? `<td class="paid-col"><button class="paid-toggle ${row.paid == 1 ? 'paid' : 'unpaid'}"
                data-emp="${row.id}" title="${row.paid == 1 ? 'Платено — натиснете за отмяна' : 'Неплатено — натиснете за отбелязване'}">
                ${row.paid == 1 ? '✓' : '✕'}</button></td>`
        : '';

    // почасова ставка: без "Отработени дни"; дневна/месечна: без "Отработени часове"
    const tableFor = (list, title, hourly) => {
        if (!list.length) return '';
        const subtotal = list.reduce((s, x) => s + x.amount, 0);
        const rows = list.map((row) => `<tr${hourly ? '' : ' class="per-day-row"'}>
            <td><div class="emp-cell">${avatarHtml(row)}
                <span class="emp-cell-text">${esc(row.name)}<span class="pos">${esc(row.position)}</span></span></div></td>
            <td>${fmtMoney(row.rate_amount)}${hourly ? '' : ' ' + UNITS[row.rate_unit]}</td>
            <td class="num">${hourly ? row.hours.toFixed(2) : row.days}</td>
            <td class="num"><strong>${fmtMoney(row.amount)}</strong></td>
            ${paidToggle(row)}
        </tr>`).join('');
        return `
        <h3 class="ts-section">${title}</h3>
        <table class="data">
            <thead><tr>
                <th>Служител</th><th>Ставка</th>
                <th class="num">${hourly ? 'Отработени часове' : 'Отработени дни'}</th>
                <th class="num">Сума за плащане</th>
                ${period ? '<th class="paid-col">Платено</th>' : ''}
            </tr></thead>
            <tbody>${rows}</tbody>
            <tfoot><tr>
                <th colspan="3" class="num">Общо:</th>
                <th class="num">${fmtMoney(subtotal)}</th>
                ${period ? '<th></th>' : ''}
            </tr></tfoot>
        </table>`;
    };

    const monthName = MONTHS[repState.month] + ' ' + repState.year;
    const periodLabel = repState.mode === 'custom'
        ? `${r.from} — ${r.to}`
        : monthName;

    view.innerHTML = `
        <div class="toolbar">
            <button class="btn arrow" id="rep-prev">‹</button>
            <button class="btn ${repState.mode === 'h1' ? 'active' : ''}" id="rep-h1">1 – 15</button>
            <button class="btn ${repState.mode === 'h2' ? 'active' : ''}" id="rep-h2">16 – край</button>
            <button class="btn ${repState.mode === 'month' ? 'active' : ''}" id="rep-month">Целият месец</button>
            <span class="period-label">${periodLabel}</span>
            <button class="btn arrow" id="rep-next">›</button>
            <div class="spacer"></div>
            <input type="date" id="rep-from" class="btn" value="${r.from}">
            <input type="date" id="rep-to" class="btn" value="${r.to}">
            <button class="btn" id="rep-custom">Покажи</button>
        </div>
        ${data.rows.length
            ? tableFor(hourlyRows, 'Почасова ставка', true) + tableFor(salariedRows, 'Дневна / месечна ставка', false)
            : '<p class="muted">Няма активни служители.</p>'}
        ${data.rows.length ? `<p class="rep-total">Общо за периода: <strong>${fmtMoney(total)}</strong></p>` : ''}
        <p class="muted" style="font-size:.85rem">
            При месечна ставка сумата е пропорционална на частта от месеца, покрита от избрания период.
        </p>`;

    document.getElementById('rep-prev').addEventListener('click', () => { repShiftMonth(-1); renderReports(); });
    document.getElementById('rep-next').addEventListener('click', () => { repShiftMonth(1); renderReports(); });
    document.getElementById('rep-h1').addEventListener('click', () => { repState.mode = 'h1'; renderReports(); });
    document.getElementById('rep-h2').addEventListener('click', () => { repState.mode = 'h2'; renderReports(); });
    document.getElementById('rep-month').addEventListener('click', () => { repState.mode = 'month'; renderReports(); });
    document.getElementById('rep-custom').addEventListener('click', () => {
        const from = document.getElementById('rep-from').value;
        const to = document.getElementById('rep-to').value;
        if (!from || !to || to < from) { alert('Невалиден период.'); return; }
        repState.mode = 'custom';
        repState.from = from;
        repState.to = to;
        renderReports();
    });

    view.querySelectorAll('.paid-toggle').forEach((b) =>
        b.addEventListener('click', async () => {
            b.disabled = true;
            try {
                const res = await api('toggle_paid', { employee_id: Number(b.dataset.emp), period });
                b.classList.toggle('paid', res.paid);
                b.classList.toggle('unpaid', !res.paid);
                b.textContent = res.paid ? '✓' : '✕';
                b.title = res.paid ? 'Платено — натиснете за отмяна' : 'Неплатено — натиснете за отбелязване';
            } catch (err) { alert(err.message); }
            b.disabled = false;
        }));
}

// ---------- Настройки ----------

async function renderSettings() {
    view.innerHTML = '<div class="settings-card"><p class="muted">Зареждане…</p></div>';
    let s = { positions: [] };
    try { s = await api('get_settings'); } catch (err) { /* показваме подразбиранията */ }
    view.innerHTML = `
        <div class="settings-grid">
        <div class="settings-card">
            <h2>Администраторска парола</h2>
            <form id="pass-form">
                <label>Нова парола <span class="muted">(празно = без промяна)</span>
                    <input type="password" id="sf-password" minlength="6" autocomplete="new-password">
                </label>
                <label>Email за възстановяване <span class="muted">(за „Забравена парола?“)</span>
                    <input type="email" id="sf-remail" value="${esc(s.recovery_email || '')}"
                           placeholder="name@example.com" autocomplete="email">
                </label>
                <button type="submit" class="btn primary">Запази</button>
                <span id="pass-saved" class="saved-msg" hidden> ✔ Запазено</span>
            </form>
        </div>

        <div class="settings-card">
            <h2>Проверка на местоположение</h2>
            <p class="muted" style="font-size:.86rem;margin:0 0 14px">
                Ако е включено, записване и отписване са възможни само когато устройството
                е в зададения радиус около ресторанта.
            </p>
            <form id="geo-form">
                <label class="check-row">
                    <input type="checkbox" id="sf-geo-enabled" ${s.geo_enabled ? 'checked' : ''}>
                    Изисквай местоположение при записване
                </label>
                <div class="rate-row">
                    <label>Ширина (lat)
                        <input type="text" id="sf-geo-lat" inputmode="decimal" value="${esc(s.geo_lat || '')}">
                    </label>
                    <label>Дължина (lng)
                        <input type="text" id="sf-geo-lng" inputmode="decimal" value="${esc(s.geo_lng || '')}">
                    </label>
                </div>
                <button type="button" class="btn" id="sf-geo-here" style="margin-bottom:16px">📍 Вземи текущата ми позиция</button>
                <label>Радиус (метри)
                    <input type="number" id="sf-geo-radius" min="10" max="5000" value="${Number(s.geo_radius) || 150}">
                </label>
                <button type="submit" class="btn primary">Запази</button>
                <span id="geo-saved" class="saved-msg" hidden> ✔ Запазено</span>
            </form>
        </div>

        <div class="settings-card">
            <h2>Длъжности</h2>
            <p class="muted" style="font-size:.86rem;margin:0 0 14px">
                По една длъжност на ред. Списъкът се използва при добавяне и редакция на служител.
            </p>
            <form id="pos-form">
                <label>Списък
                    <textarea id="sf-positions" rows="6">${esc((s.positions || []).join('\n'))}</textarea>
                </label>
                <button type="submit" class="btn primary">Запази</button>
                <span id="pos-saved" class="saved-msg" hidden> ✔ Запазено</span>
            </form>
        </div>

        <div class="settings-card">
            <h2>Архивиране</h2>
            <p class="muted" style="font-size:.86rem;margin:0 0 14px">
                Архивът съдържа служителите, всички записи и снимките.
                Администраторската парола не се архивира и не се променя при възстановяване.
            </p>
            <button type="button" class="btn primary" id="sf-backup">⬇ Изтегли архив</button>

            <hr style="border:none;border-top:1px solid rgba(0,0,0,.06);margin:22px 0">

            <label>Възстановяване от архив
                <input type="file" id="sf-restore-file" accept=".zip">
            </label>
            <button type="button" class="btn danger" id="sf-restore">Възстанови</button>
            <p class="muted" style="font-size:.82rem;margin:10px 0 0">
                Възстановяването <strong>изтрива всички текущи данни</strong> и ги заменя с тези от архива.
            </p>
        </div>
        </div>`;

    const savedFlash = (id) => {
        const el = document.getElementById(id);
        el.hidden = false;
        setTimeout(() => { el.hidden = true; }, 2000);
    };

    document.getElementById('pass-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api('save_settings', {
                password: document.getElementById('sf-password').value,
                recovery_email: document.getElementById('sf-remail').value,
            });
            document.getElementById('sf-password').value = '';
            savedFlash('pass-saved');
        } catch (err) { alert(err.message); }
    });

    document.getElementById('sf-geo-here').addEventListener('click', () => {
        const btn = document.getElementById('sf-geo-here');
        if (!navigator.geolocation) { alert('Браузърът няма достъп до местоположение.'); return; }
        btn.disabled = true;
        btn.textContent = 'Определяне…';
        navigator.geolocation.getCurrentPosition(
            (p) => {
                document.getElementById('sf-geo-lat').value = p.coords.latitude.toFixed(6);
                document.getElementById('sf-geo-lng').value = p.coords.longitude.toFixed(6);
                btn.disabled = false;
                btn.textContent = '📍 Вземи текущата ми позиция';
            },
            () => {
                alert('Неуспешно определяне на позицията. Разрешете достъп до местоположението.');
                btn.disabled = false;
                btn.textContent = '📍 Вземи текущата ми позиция';
            },
            { enableHighAccuracy: true, timeout: 12000 }
        );
    });

    document.getElementById('geo-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api('save_settings', {
                geo_enabled: document.getElementById('sf-geo-enabled').checked ? 1 : 0,
                geo_lat: document.getElementById('sf-geo-lat').value,
                geo_lng: document.getElementById('sf-geo-lng').value,
                geo_radius: Number(document.getElementById('sf-geo-radius').value) || 150,
            });
            savedFlash('geo-saved');
        } catch (err) { alert(err.message); }
    });

    document.getElementById('pos-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api('save_settings', {
                positions: document.getElementById('sf-positions').value
                    .split('\n').map((x) => x.trim()).filter(Boolean),
            });
            savedFlash('pos-saved');
        } catch (err) { alert(err.message); }
    });

    document.getElementById('sf-backup').addEventListener('click', () => {
        window.location.href = 'api.php?action=backup';
    });

    document.getElementById('sf-restore').addEventListener('click', async () => {
        const file = document.getElementById('sf-restore-file').files[0];
        if (!file) { alert('Изберете ZIP файл с архив.'); return; }
        if (!confirm('Възстановяването ще ИЗТРИЕ всички текущи данни (служители, записи, снимки) ' +
                     'и ще ги замени с тези от архива.\n\nПродължавате ли?')) return;
        const btn = document.getElementById('sf-restore');
        btn.disabled = true;
        btn.textContent = 'Възстановяване…';
        try {
            const fd = new FormData();
            fd.append('backup', file);
            const res = await fetch('api.php?action=restore', { method: 'POST', body: fd });
            const json = await res.json();
            if (!res.ok) throw new Error(json.error || 'Грешка при възстановяване.');
            alert('Възстановено успешно: ' + json.employees + ' служители, ' +
                  json.entries + ' записа, ' + json.photos + ' снимки.');
            location.reload();
        } catch (err) {
            alert(err.message);
            btn.disabled = false;
            btn.textContent = 'Възстанови';
        }
    });
}

// ---------- Старт ----------

renderPeople();
