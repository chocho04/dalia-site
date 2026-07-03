'use strict';

// Публичен седмичен график: всеки вижда графика и може да заяви
// промяна на своя смяна; промяната се прилага след одобрение от админ.

const gridEl = document.getElementById('grid');
const labelEl = document.getElementById('wk-label');
const backdrop = document.getElementById('sr-backdrop');
const modalEl = document.getElementById('sr-modal');

const DOW_SHORT = ['пн', 'вт', 'ср', 'чт', 'пт', 'сб', 'нд'];
const MONTHS = ['януари', 'февруари', 'март', 'април', 'май', 'юни',
    'юли', 'август', 'септември', 'октомври', 'ноември', 'декември'];

function pad(n) { return String(n).padStart(2, '0'); }
function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
function mondayOf(d) {
    const x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
    return x;
}
function addDays(d, n) { const x = new Date(d); x.setDate(x.getDate() + n); return x; }

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s == null ? '' : String(s);
    return div.innerHTML;
}

async function api(action, data, params) {
    let url = 'api.php?action=' + action;
    if (params) url += '&' + new URLSearchParams(params);
    const opts = data
        ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }
        : {};
    const res = await fetch(url, opts);
    const json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Грешка в сървъра.');
    return json;
}

let monday = mondayOf(new Date());
let data = null;
let restOpen = false; // групата „Кухня“ е свита по подразбиране

async function load() {
    gridEl.innerHTML = '<p class="loading">Зареждане…</p>';
    try {
        data = await api('week_schedule', null, { from: ymd(monday) });
    } catch (e) {
        gridEl.innerHTML = '<p class="loading">' + escapeHtml(e.message) + '</p>';
        return;
    }
    render();
}

function render() {
    const days = [];
    for (let i = 0; i < 7; i++) days.push(addDays(monday, i));
    labelEl.textContent = days[0].getDate() + ' ' + MONTHS[days[0].getMonth()] + ' – ' +
        days[6].getDate() + ' ' + MONTHS[days[6].getMonth()] + ' ' + days[6].getFullYear();

    const shiftById = {};
    data.shift_types.forEach((t) => { shiftById[t.id] = t; });
    const byEmpDay = {};
    data.entries.forEach((e) => { byEmpDay[e.employee_id + '|' + e.day] = e; });
    const reqByEmpDay = {};
    data.requests.forEach((r) => { reqByEmpDay[r.employee_id + '|' + r.day] = r; });

    const todayStr = ymd(new Date());

    // на тесен екран се показва съкращението (CSS превключва .sh-full/.sh-abbr)
    const shiftLabel = (t) => '<span class="sh-full">' + escapeHtml(t.name) + '</span>' +
        '<span class="sh-abbr">' + escapeHtml(t.abbr || t.name) + '</span>';

    let head = '<tr><th class="ps-emp">Служител</th>';
    for (const d of days) {
        const wd = d.getDay();
        head += '<th class="ps-day' + (wd === 0 || wd === 6 ? ' weekend' : '') +
            (ymd(d) === todayStr ? ' today' : '') + '">' +
            '<strong>' + d.getDate() + '.' + (d.getMonth() + 1) + '</strong><br>' +
            '<span class="ps-dow">' + DOW_SHORT[(wd + 6) % 7] + '</span></th>';
    }
    head += '</tr>';

    const bodyFor = (emps) => {
        let body = '';
        for (const emp of emps) {
            const initials = emp.name.split(/\s+/).map((w) => w[0]).slice(0, 2).join('');
            const avatar = emp.has_avatar == 1
                ? '<img class="avatar photo ps-avatar" src="selfie.php?avatar=' + emp.id + '" alt="" loading="lazy">'
                : '<span class="avatar c' + (emp.id % 4) + ' ps-avatar">' + escapeHtml(initials) + '</span>';
            body += '<tr><td class="ps-emp"><div class="ps-emp-inner">' + avatar +
                '<span class="ps-name">' + escapeHtml(emp.name) + '</span></div></td>';
            for (const d of days) {
                const dateStr = ymd(d);
                const wd = d.getDay();
                const entry = byEmpDay[emp.id + '|' + dateStr];
                const req = reqByEmpDay[emp.id + '|' + dateStr];
                const sh = entry && entry.shift_type_id != 0 ? shiftById[entry.shift_type_id] : null;
                let cell = sh ? '<span class="ps-shift">' + shiftLabel(sh) + '</span>' : '';
                if (entry && entry.note) cell += '<span class="ps-note">' + escapeHtml(entry.note) + '</span>';
                if (req) {
                    const rsh = req.shift_type_id != 0 ? shiftById[req.shift_type_id] : null;
                    cell += '<span class="ps-req">⏳ → ' + (rsh ? shiftLabel(rsh) : '—') + '</span>';
                }
                body += '<td class="ps-cell' + (wd === 0 || wd === 6 ? ' weekend' : '') +
                    (dateStr === todayStr ? ' today' : '') + '" data-emp="' + emp.id +
                    '" data-day="' + dateStr + '">' + cell + '</td>';
            }
            body += '</tr>';
        }
        return body;
    };
    const tableFor = (emps) => '<div class="pub-sched-scroll"><table class="pub-sched">' +
        '<thead>' + head + '</thead><tbody>' + bodyFor(emps) + '</tbody></table></div>';

    // горе: сервитьори и бармани; отдолу: кухня (свита по подразбиране)
    const FRONT = ['Сервитьор', 'Барман'];
    const front = data.employees.filter((e) => FRONT.includes(e.position));
    const rest = data.employees.filter((e) => !FRONT.includes(e.position));

    if (!data.employees.length) {
        gridEl.innerHTML = '<p class="loading">Няма активни служители.</p>';
        return;
    }
    let html = '';
    if (front.length) {
        html += '<p class="ps-group-label">Сервитьори / Бармани</p>' + tableFor(front);
    }
    if (rest.length) {
        if (front.length) {
            html += '<button type="button" class="ps-group-toggle' + (restOpen ? ' open' : '') + '" id="ps-toggle">' +
                '<span class="chev">▸</span> Кухня</button>' +
                '<div id="ps-rest"' + (restOpen ? '' : ' hidden') + '>' + tableFor(rest) + '</div>';
        } else {
            html += '<p class="ps-group-label">Кухня</p>' + tableFor(rest);
        }
    }
    gridEl.innerHTML = html;

    const toggle = document.getElementById('ps-toggle');
    if (toggle) {
        toggle.addEventListener('click', () => {
            restOpen = !restOpen;
            toggle.classList.toggle('open', restOpen);
            document.getElementById('ps-rest').hidden = !restOpen;
        });
    }

    gridEl.querySelectorAll('td.ps-cell').forEach((td) =>
        td.addEventListener('click', () => {
            const emp = data.employees.find((x) => x.id == td.dataset.emp);
            openRequestModal(emp, td.dataset.day);
        }));
}

function openRequestModal(emp, dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    const entry = data.entries.find((e) => e.employee_id == emp.id && e.day === dateStr);
    const currentSid = entry ? Number(entry.shift_type_id) : 0;
    const shiftById = {};
    data.shift_types.forEach((t) => { shiftById[t.id] = t; });

    const opt = (sid, title, sub) => `
        <button type="button" class="sr-opt${sid === currentSid ? ' current' : ''}" data-sid="${sid}">
            <span class="sr-opt-name">${title}</span>
            ${sub ? '<span class="sr-opt-sub">' + sub + '</span>' : ''}
            ${sid === currentSid ? '<span class="sr-opt-tag">сега</span>' : ''}
        </button>`;

    modalEl.innerHTML = `
        <h3>${escapeHtml(emp.name)} — ${d.getDate()} ${MONTHS[d.getMonth()]}</h3>
        <p class="sr-sub">Изберете желаната смяна и изпратете заявка:</p>
        <div class="sr-opts">
            ${opt(0, 'Почивка', '')}
            ${data.shift_types.map((t) =>
                opt(Number(t.id), escapeHtml(t.name), t.start_time + '–' + t.end_time)).join('')}
        </div>
        <div class="sr-actions">
            <button type="button" class="sr-btn" id="sr-cancel">Отказ</button>
            <button type="button" class="sr-btn primary" id="sr-send" disabled>Изпрати заявка</button>
        </div>
        <p class="sr-msg" id="sr-msg" hidden></p>`;
    backdrop.hidden = false;

    let chosen = null;
    modalEl.querySelectorAll('.sr-opt').forEach((b) =>
        b.addEventListener('click', () => {
            modalEl.querySelectorAll('.sr-opt').forEach((x) => x.classList.remove('active'));
            b.classList.add('active');
            chosen = Number(b.dataset.sid);
            document.getElementById('sr-send').disabled = chosen === currentSid;
        }));
    document.getElementById('sr-cancel').addEventListener('click', closeModal);
    document.getElementById('sr-send').addEventListener('click', async () => {
        const btn = document.getElementById('sr-send');
        btn.disabled = true;
        try {
            await api('shift_request', { employee_id: emp.id, day: dateStr, shift_type_id: chosen });
            closeModal();
            load(); // показва ⏳ маркера
        } catch (e) {
            const msg = document.getElementById('sr-msg');
            msg.textContent = e.message;
            msg.hidden = false;
            btn.disabled = false;
        }
    });
}

function closeModal() {
    backdrop.hidden = true;
    modalEl.innerHTML = '';
}
// затваря само ако и натискането, и отпускането са върху фона —
// иначе влачене от модала навън би го затворило
let backdropPressed = false;
backdrop.addEventListener('pointerdown', (e) => { backdropPressed = e.target === backdrop; });
backdrop.addEventListener('pointerup', (e) => {
    if (backdropPressed && e.target === backdrop) closeModal();
    backdropPressed = false;
});

document.getElementById('wk-prev').addEventListener('click', () => { monday = addDays(monday, -7); load(); });
document.getElementById('wk-next').addEventListener('click', () => { monday = addDays(monday, 7); load(); });

load();
