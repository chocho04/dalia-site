<?php
// Копирайте този файл като config.php и попълнете данните.

// Парола за вход в приложението.
define('APP_PASSWORD', '');

// Пощенска кутия (IMAP през SSL, порт 993).
// Gmail: imap.gmail.com, потребител = целият адрес, парола = „Парола за
// приложения“ (Google акаунт » Сигурност » Потвърждаване в 2 стъпки »
// Пароли за приложения) — 16 букви без интервалите. Обикновената парола не работи.
// Поща на хостинга (cPanel): mail.rtdalia.com, потребител = целият адрес.
define('IMAP_HOST', 'imap.gmail.com');
define('IMAP_PORT', 993);
define('IMAP_USER', 'you@gmail.com');
define('IMAP_PASS', '');
define('IMAP_FOLDER', 'INBOX');

// Приложението за документи (doc-transcriber-app).
define('TRANSCRIBER_URL', 'https://docs.rtdalia.com');
// Access PIN — същият, с който влизате в docs.rtdalia.com.
define('TRANSCRIBER_PIN', '');
