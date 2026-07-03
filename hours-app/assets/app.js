'use strict';

const screenList = document.getElementById('screen-list');
const screenClock = document.getElementById('screen-clock');
const listEl = document.getElementById('employee-list');
const empNameEl = document.getElementById('emp-name');
const empStatusEl = document.getElementById('emp-status');
const clockBtn = document.getElementById('clock-btn');
const resultMsg = document.getElementById('result-msg');
const video = document.getElementById('camera');
const cameraError = document.getElementById('camera-error');
const canvas = document.getElementById('capture-canvas');

const liveClockEl = document.getElementById('live-clock');

let currentEmp = null;
let stream = null;
let clockTimer = null;
let geoRequired = false; // проверка на местоположението при записване (настройка)

const ICON_IN = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
    stroke-linecap="round" stroke-linejoin="round">
    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
    <polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>`;
const ICON_OUT = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
    stroke-linecap="round" stroke-linejoin="round">
    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
    <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>`;

function fmtTime(dt) {
    return dt.slice(11, 16);
}

function tickLiveClock() {
    const n = new Date();
    const p = (x) => String(x).padStart(2, '0');
    liveClockEl.innerHTML = p(n.getHours()) + ':' + p(n.getMinutes()) +
        '<span class="secs">' + p(n.getSeconds()) + '</span>';
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

async function loadEmployees() {
    listEl.innerHTML = '<p class="loading">Зареждане…</p>';
    try {
        const res = await api('employees');
        const employees = res.employees;
        geoRequired = !!res.geo_required;
        listEl.innerHTML = '';
        if (!employees.length) {
            listEl.innerHTML = '<p class="loading">Няма добавени служители.</p>';
            return;
        }
        employees.forEach((emp, i) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'employee-btn' + (emp.clocked_in ? ' clocked-in' : '');
            btn.style.animationDelay = (i * 45) + 'ms';
            const initials = emp.name.split(/\s+/).map((w) => w[0]).slice(0, 2).join('');
            const avatar = emp.has_avatar == 1
                ? '<img class="avatar photo" src="selfie.php?avatar=' + emp.id + '" alt="" loading="lazy">'
                : '<span class="avatar c' + (emp.id % 4) + '">' + escapeHtml(initials) + '</span>';
            btn.innerHTML =
                avatar +
                '<span class="info">' + escapeHtml(emp.name) +
                (emp.position ? '<span class="pos">' + escapeHtml(emp.position) + '</span>' : '') +
                (emp.today_shift
                    ? '<span class="shift-note">Днес: ' +
                      '<span class="sh-full">' + escapeHtml(emp.today_shift.name) + '</span>' +
                      '<span class="sh-abbr">' + escapeHtml(emp.today_shift.abbr || emp.today_shift.name) + '</span>' +
                      ' · ' + emp.today_shift.start + '–' + emp.today_shift.end + '</span>'
                    : '') +
                '</span>' +
                (emp.clocked_in ? '<span class="chip">на смяна</span>' : '');
            btn.addEventListener('click', () => openClockScreen(emp));
            listEl.appendChild(btn);
        });
    } catch (e) {
        listEl.innerHTML = '<p class="loading">' + escapeHtml(e.message) + '</p>';
    }
}

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

async function openClockScreen(emp) {
    currentEmp = emp;
    empNameEl.textContent = emp.name;
    updateClockUi();
    resultMsg.hidden = true;
    screenList.hidden = true;
    screenClock.hidden = false;
    document.querySelector('.topbar').hidden = true;
    tickLiveClock();
    clockTimer = setInterval(tickLiveClock, 1000);
    loadWeekSchedule(emp.id);
    await startCamera();
}

// График на служителя за текущата седмица (под бутона за записване)
const DOW_MON = ['Пон.', 'Вт.', 'Ср.', 'Четв.', 'Пет.', 'Съб.', 'Нед.'];

async function loadWeekSchedule(empId) {
    const el = document.getElementById('week-sched');
    el.hidden = true;
    el.innerHTML = '';
    let w;
    try { w = await api('my_week', null, { employee_id: empId }); } catch (e) { return; }
    if (!w.days.length) return; // няма график за седмицата — не показваме панела

    const byDay = {};
    w.days.forEach((d) => { byDay[d.day] = d; });
    const p = (x) => String(x).padStart(2, '0');
    const today = new Date();
    const todayStr = today.getFullYear() + '-' + p(today.getMonth() + 1) + '-' + p(today.getDate());
    const monday = new Date(w.monday + 'T00:00:00');

    let html = '<h3>Графикът ми тази седмица</h3>';
    for (let i = 0; i < 7; i++) {
        const d = new Date(monday);
        d.setDate(d.getDate() + i);
        const dateStr = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
        const entry = byDay[dateStr];
        html += '<div class="ws-row' + (dateStr === todayStr ? ' today' : '') + (entry ? '' : ' off') + '">' +
            '<span class="ws-date">' + d.getDate() + '.' + (d.getMonth() + 1) + '</span>' +
            '<span class="ws-dow">' + DOW_MON[i] + '</span>' +
            '<span class="ws-shift">' + (entry
                ? '<span class="sh-full">' + escapeHtml(entry.name) + '</span>' +
                  '<span class="sh-abbr">' + escapeHtml(entry.abbr || entry.name) + '</span>' +
                  (entry.note ? '<span class="ws-note">' + escapeHtml(entry.note) + '</span>' : '')
                : 'почивка') + '</span></div>';
    }
    el.innerHTML = html;
    el.hidden = false;
}

function updateClockUi() {
    if (currentEmp.clocked_in) {
        empStatusEl.innerHTML = '<span class="status-dot on"></span>' + (currentEmp.open_since
            ? 'На смяна от ' + fmtTime(currentEmp.open_since)
            : 'На смяна');
        clockBtn.innerHTML = ICON_OUT + '<span>Отписване</span>';
        clockBtn.className = 'clock-btn out';
    } else {
        empStatusEl.innerHTML = '<span class="status-dot"></span>Не сте на смяна';
        clockBtn.innerHTML = ICON_IN + '<span>Записване</span>';
        clockBtn.className = 'clock-btn in';
    }
    clockBtn.disabled = false;
}

async function startCamera() {
    cameraError.hidden = true;
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 } },
            audio: false,
        });
        video.srcObject = stream;
    } catch (e) {
        stream = null;
        cameraError.hidden = false;
    }
}

function stopCamera() {
    if (stream) {
        stream.getTracks().forEach((t) => t.stop());
        stream = null;
    }
    video.srcObject = null;
}

function capturePhoto() {
    if (!stream || !video.videoWidth) return null;
    const w = 480;
    const h = Math.round((video.videoHeight / video.videoWidth) * w);
    canvas.width = w;
    canvas.height = h;
    canvas.getContext('2d').drawImage(video, 0, 0, w, h);
    return canvas.toDataURL('image/jpeg', 0.8);
}

function backToList() {
    stopCamera();
    clearInterval(clockTimer);
    screenClock.hidden = true;
    screenList.hidden = false;
    document.querySelector('.topbar').hidden = false;
    loadEmployees();
}

// текуща GPS позиция (изисква се, когато проверката е включена от админ)
function getPosition() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) { reject(new Error('Устройството няма достъп до местоположение.')); return; }
        navigator.geolocation.getCurrentPosition(
            (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
            () => reject(new Error('Разрешете достъп до местоположението, за да се запишете.')),
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 }
        );
    });
}

clockBtn.addEventListener('click', async () => {
    clockBtn.disabled = true;
    resultMsg.hidden = true;
    clockBtn.innerHTML = '<span class="spinner"></span><span>Момент…</span>';
    try {
        let pos = {};
        if (geoRequired) pos = await getPosition();
        const photo = capturePhoto();
        const res = await api('clock', { employee_id: currentEmp.id, photo, ...pos });
        resultMsg.className = 'result ok';
        resultMsg.innerHTML = '<span class="check-pop">✓</span>' + (res.status === 'in'
            ? 'Записахте се в ' + fmtTime(res.time)
            : 'Отписахте се в ' + fmtTime(res.time));
        resultMsg.hidden = false;
        clockBtn.classList.add('done');
        setTimeout(backToList, 2500);
    } catch (e) {
        resultMsg.className = 'result err';
        resultMsg.textContent = e.message;
        resultMsg.hidden = false;
        updateClockUi();
    }
});

document.getElementById('back-btn').addEventListener('click', backToList);

loadEmployees();
