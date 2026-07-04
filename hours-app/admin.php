<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
session_start();
$loggedIn = !empty($_SESSION['admin']) || remember_try();
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Администрация — Далия Работно време</title>
<link rel="icon" href="assets/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<meta name="theme-color" content="#0284c7">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="app-background"></div>

<?php if (!$loggedIn && isset($_GET['reset'])): ?>

<div class="login-wrap">
    <form id="reset-form" class="login-card glass-panel">
        <div class="logo-icon big"><img class="logo-img" src="assets/logo-mark.png" alt="Далия"></div>
        <h1>Нова администраторска парола</h1>
        <label>Нова парола:
            <input type="password" id="reset-password" minlength="6" autofocus required autocomplete="new-password">
        </label>
        <button type="submit">Запази паролата</button>
        <p id="reset-error" class="error" hidden></p>
        <p id="reset-ok" class="login-msg" hidden>Паролата е сменена. <a href="admin.php">Към входа</a></p>
    </form>
</div>
<script>
document.getElementById('reset-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errEl = document.getElementById('reset-error');
    errEl.hidden = true;
    const res = await fetch('api.php?action=reset_password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            token: <?= json_encode((string)$_GET['reset']) ?>,
            password: document.getElementById('reset-password').value,
        }),
    });
    const json = await res.json().catch(() => ({}));
    if (res.ok) {
        document.getElementById('reset-form').querySelector('button').hidden = true;
        document.getElementById('reset-ok').hidden = false;
        return;
    }
    errEl.textContent = json.error || 'Грешка.';
    errEl.hidden = false;
});
</script>

<?php elseif (!$loggedIn): ?>

<div class="login-wrap">
    <form id="login-form" class="login-card glass-panel">
        <div class="logo-icon big"><img class="logo-img" src="assets/logo-mark.png" alt="Далия"></div>
        <h1>Работно време — Администрация</h1>
        <label>Парола:
            <input type="password" id="login-password" autofocus required>
        </label>
        <button type="submit">Вход</button>
        <p class="forgot"><a href="#" id="forgot-link">Забравена парола?</a></p>
        <p id="login-error" class="error" hidden></p>
        <p id="forgot-msg" class="login-msg" hidden></p>
    </form>
</div>
<script>
document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errEl = document.getElementById('login-error');
    errEl.hidden = true;
    const res = await fetch('api.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: document.getElementById('login-password').value }),
    });
    if (res.ok) { location.reload(); return; }
    const json = await res.json().catch(() => ({}));
    errEl.textContent = json.error || 'Грешка при вход.';
    errEl.hidden = false;
});
document.getElementById('forgot-link').addEventListener('click', async (e) => {
    e.preventDefault();
    const msg = document.getElementById('forgot-msg');
    msg.hidden = true;
    await fetch('api.php?action=forgot_password', { method: 'POST' }).catch(() => {});
    msg.textContent = 'Ако е зададен email за възстановяване, изпратихме линк за нова парола.';
    msg.hidden = false;
});
</script>

<?php else: ?>

<header class="topbar glass-panel">
    <div class="brand-section">
        <div class="logo-icon"><img class="logo-img" src="assets/logo-mark.png" alt="Далия"></div>
        <h1>Работно време</h1>
    </div>
    <nav class="tabs">
        <button data-tab="timesheets" class="tab">
            <svg class="tab-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/></svg>
            <span class="tab-label">Присъствия</span>
        </button>
        <button data-tab="schedules" class="tab">
            <svg class="tab-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
            <span class="tab-label">Графици</span>
        </button>
        <button data-tab="reports" class="tab active">
            <svg class="tab-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h12"/><path d="M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2"/></svg>
            <span class="tab-label">Справки</span>
        </button>
        <button data-tab="people" class="tab">
            <svg class="tab-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span class="tab-label">Хора</span>
        </button>
        <button data-tab="settings" class="tab">
            <svg class="tab-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
            <span class="tab-label">Настройки</span>
        </button>
    </nav>
    <button id="logout-btn" class="logout">Изход</button>
</header>

<main id="view"></main>

<div id="modal-backdrop" class="modal-backdrop" hidden>
    <div id="modal" class="modal"></div>
</div>

<script src="assets/admin.js"></script>

<?php endif; ?>
</body>
</html>
