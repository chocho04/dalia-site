<?php
// Пуска се от cPanel » Cron Jobs (напр. на всеки 15 минути):
//   php /home/ПОТРЕБИТЕЛ/inbox.rtdalia.com/cron.php
// Проверява пощата за нови писма и изпраща автоматично прикачените файлове от
// подателите в списъка „Автоматично изпращане“ (Настройки).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/lib/mailbox.php';

db(); // създава data/ при първо пускане
$lock = fopen(DATA_DIR . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit; // предишното пускане още работи

try {
    [$imap, $uidv] = imap_connect();
    $scan = scan_mailbox($imap, $uidv);
    $auto = auto_send_pending($imap, $uidv);
    $imap->logout();
    echo date('Y-m-d H:i') . " проверени: {$scan['checked']}, с файлове: {$scan['found']}, "
        . "изпратени: {$auto['sent']}, грешки: {$auto['failed']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i') . ' Грешка: ' . $e->getMessage() . "\n");
    exit(1);
}
