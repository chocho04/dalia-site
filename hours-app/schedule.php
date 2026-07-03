<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Далия — Седмичен график</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-background"></div>

<main class="screen sched-screen">
    <div class="week-nav">
        <button id="wk-prev" class="wk-btn" type="button">‹</button>
        <span id="wk-label" class="wk-label"></span>
        <button id="wk-next" class="wk-btn" type="button">›</button>
    </div>
    <p class="hint">Докоснете своя ден, за да заявите промяна. Промяната важи след одобрение от администратор.</p>
    <div id="grid"><p class="loading">Зареждане…</p></div>

    <header class="topbar">
        <div class="logo-icon"><img class="logo-img" src="assets/logo-mark.png" alt="Далия"></div>
        <h1>Седмичен график</h1>
    </header>
</main>

<div id="sr-backdrop" class="sr-backdrop" hidden>
    <div id="sr-modal" class="sr-modal"></div>
</div>

<script src="assets/schedule.js"></script>
</body>
</html>
