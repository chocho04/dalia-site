<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Далия — Работно време</title>
<link rel="icon" href="assets/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#0284c7">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-background"></div>

<header class="topbar">
    <div class="logo-icon"><img class="logo-img" src="assets/logo-mark.png" alt="Далия"></div>
    <h1>Работно време</h1>
</header>

<!-- Екран 1: списък със служители -->
<main id="screen-list" class="screen">
    <p class="hint">Изберете името си:</p>
    <div id="employee-list" class="employee-list">
        <p class="loading">Зареждане…</p>
    </div>
</main>

<!-- Екран 2: селфи и записване/отписване -->
<main id="screen-clock" class="screen" hidden>
    <div id="live-clock" class="live-clock" aria-hidden="true"></div>
    <h2 id="emp-name"></h2>
    <p id="emp-status" class="status"></p>
    <div class="camera-ring">
        <div class="camera-inner">
            <video id="camera" autoplay playsinline muted></video>
            <div id="camera-error" class="camera-error" hidden>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2m5.66 0H14a2 2 0 0 1 2 2v3.34l1 1L23 7v10"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
                <span>Камерата не е достъпна.<br>Записването ще стане без снимка.</span>
            </div>
        </div>
    </div>
    <div class="btn-row">
        <button id="clock-btn" class="clock-btn" type="button"></button>
        <button id="back-btn" class="back-btn" type="button" title="Назад" aria-label="Назад">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
    </div>
    <p id="result-msg" class="result" hidden></p>
    <div id="week-sched" class="week-sched" hidden></div>
</main>

<canvas id="capture-canvas" hidden></canvas>
<script src="assets/app.js"></script>
</body>
</html>
