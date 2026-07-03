<?php
require_once __DIR__ . '/db.php';
session_start();
$loggedIn = !empty($_SESSION['admin']);
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
        <button data-tab="people" class="tab active">Хора</button>
        <button data-tab="timesheets" class="tab">Присъствия</button>
        <button data-tab="schedules" class="tab">Графици</button>
        <button data-tab="reports" class="tab">Справки</button>
        <button data-tab="settings" class="tab">Настройки</button>
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
