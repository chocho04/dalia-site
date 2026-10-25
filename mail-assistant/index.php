<?php
require_once __DIR__ . '/lib/auth.php';
header('Cache-Control: no-store');
$authed = auth_ok();
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Далия — Фактури от пощата</title>
<link rel="icon" href="assets/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<meta name="theme-color" content="#0284c7">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
</head>
<body data-authed="<?= $authed ? '1' : '0' ?>">

<header class="topbar">
    <img class="logo" src="assets/logo-mark.png" alt="Далия">
    <div class="title">
        <h1>Фактури от пощата</h1>
        <p id="scan-info" class="muted"></p>
    </div>
    <div class="top-actions" id="top-actions" hidden>
        <button id="scan-btn" class="btn" type="button">Обнови</button>
        <button id="settings-btn" class="icon-btn" type="button" title="Настройки" aria-label="Настройки">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg>
        </button>
    </div>
</header>

<!-- Вход -->
<main id="screen-login" class="screen" hidden>
    <form id="login-form" class="card login">
        <h2>Вход</h2>
        <input id="login-password" type="password" placeholder="Парола" autocomplete="current-password" required>
        <button class="btn primary" type="submit">Влез</button>
        <p id="login-error" class="error" hidden></p>
    </form>
</main>

<!-- Списък с писмата -->
<main id="screen-list" class="screen" hidden>
    <nav class="tabs" id="tabs">
        <button type="button" data-tab="todo" class="active">За изпращане <span class="count"></span></button>
        <button type="button" data-tab="all">Всички <span class="count"></span></button>
        <button type="button" data-tab="sent">Изпратени <span class="count"></span></button>
        <button type="button" data-tab="hidden">Скрити <span class="count"></span></button>
    </nav>
    <p id="banner" class="banner" hidden></p>
    <div id="list" class="list"></div>
</main>

<!-- Лента за изпращане на избраните -->
<div id="sendbar" class="sendbar" hidden>
    <span id="sendbar-text"></span>
    <button id="sendbar-btn" class="btn primary" type="button">Изпрати за анализ</button>
</div>

<!-- Настройки -->
<dialog id="settings" class="settings">
    <form id="settings-form" method="dialog">
        <h2>Настройки</h2>

        <section class="info" id="settings-info"></section>
        <button id="test-btn" class="btn" type="button">Провери връзките</button>
        <div id="test-result" class="test-result"></div>

        <label>Автоматично изпращане от тези податели
            <small>По един на ред: адрес (ivan@firma.bg) или домейн (evn.bg). Нужен е cron (вижте долу).
                Изпращат се само писмата, пристигнали след добавянето на подателя.</small>
            <textarea name="autoSenders" rows="4" placeholder="fakturi@evn.bg&#10;a1.bg"></textarea>
        </label>
        <label>Скрити податели
            <small>Писмата им не се показват в „За изпращане“ и „Всички“.</small>
            <textarea name="ignoreSenders" rows="3" placeholder="newsletter@magazin.bg"></textarea>
        </label>
        <label>Ключови думи за разпознаване
            <small>Търсят се в темата, името на файла и подателя. Изтрийте всички, за да върнете стандартните.</small>
            <textarea name="keywords" rows="6"></textarea>
        </label>
        <label>Преглеждай писмата от последните (дни)
            <input name="scanDays" type="number" min="1" max="365">
        </label>

        <p class="muted small" id="cron-info"></p>

        <div class="dialog-actions">
            <button id="logout-btn" class="btn ghost" type="button">Изход</button>
            <span class="spacer"></span>
            <button class="btn" value="cancel" formnovalidate>Затвори</button>
            <button id="settings-save" class="btn primary" type="button">Запази</button>
        </div>
    </form>
</dialog>

<!-- Преглед на писмото -->
<dialog id="preview" class="preview">
    <form method="dialog">
        <h2 id="preview-title"></h2>
        <p id="preview-from" class="muted"></p>
        <pre id="preview-text"></pre>
        <div class="dialog-actions"><span class="spacer"></span><button class="btn">Затвори</button></div>
    </form>
</dialog>

<script src="assets/app.js"></script>
</body>
</html>
