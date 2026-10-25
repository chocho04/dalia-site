<?php
require_once __DIR__ . '/config.php';

date_default_timezone_set('Europe/Sofia');

const DATA_DIR = __DIR__ . '/../data';

// Ключови думи по подразбиране за разпознаване на фактури / плащания.
// Търсят се (без значение от главни/малки букви) в темата, името на файла
// и подателя. Редактират се от Настройки.
const DEFAULT_KEYWORDS = [
    'фактура', 'фактури', 'проформа', 'сметка', 'сметки', 'плащане', 'платежно',
    'задължение', 'разписка', 'касов бон', 'касова бележка', 'кредитно известие',
    'дебитно известие', 'извлечение', 'абонамент',
    'invoice', 'receipt', 'payment', 'bill', 'statement', 'remittance',
    'faktura', 'smetka',
];

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
        if (!is_file(DATA_DIR . '/.htaccess')) {
            @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");
        }
        $pdo = new PDO('sqlite:' . DATA_DIR . '/mail.sqlite', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        db_migrate($pdo);
    }
    return $pdo;
}

function db_migrate(PDO $pdo): void {
    // Писмата, които сканирането е видяло. Пазят се и писма без подходящи
    // прикачени файлове (attachments = '[]'), за да не се изтеглят повторно.
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        mkey TEXT NOT NULL UNIQUE,
        uidv INTEGER NOT NULL,
        uid INTEGER NOT NULL,
        message_id TEXT NOT NULL DEFAULT '',
        from_name TEXT NOT NULL DEFAULT '',
        from_email TEXT NOT NULL DEFAULT '',
        subject TEXT NOT NULL DEFAULT '',
        received_at INTEGER NOT NULL,
        attachments TEXT NOT NULL DEFAULT '[]',
        text_part TEXT NOT NULL DEFAULT '',
        dismissed INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS messages_uid ON messages (uidv, uid)");

    // По един ред за всеки прикачен файл, изпратен (или опитан) към docs.
    $pdo->exec("CREATE TABLE IF NOT EXISTS sends (
        mkey TEXT NOT NULL,
        section TEXT NOT NULL,
        status TEXT NOT NULL,
        doc_id TEXT NOT NULL DEFAULT '',
        dest TEXT NOT NULL DEFAULT '',
        saved_type TEXT NOT NULL DEFAULT '',
        error TEXT NOT NULL DEFAULT '',
        retryable INTEGER NOT NULL DEFAULT 0,
        attempts INTEGER NOT NULL DEFAULT 0,
        auto INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        PRIMARY KEY (mkey, section)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        skey TEXT PRIMARY KEY,
        svalue TEXT NOT NULL
    )");
}

function setting(string $key, ?string $default = null): ?string {
    $st = db()->prepare("SELECT svalue FROM settings WHERE skey = ?");
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ? $row['svalue'] : $default;
}

function set_setting(string $key, string $value): void {
    db()->prepare("REPLACE INTO settings (skey, svalue) VALUES (?, ?)")->execute([$key, $value]);
}

// Настройка-списък (по един елемент на ред), в малки букви, без празни редове.
function setting_list(string $key, array $default = []): array {
    $raw = setting($key);
    if ($raw === null) return $default;
    $out = [];
    foreach (preg_split('/\R/u', $raw) as $line) {
        $line = mb_strtolower(trim($line));
        if ($line !== '') $out[] = $line;
    }
    return $out;
}

function scan_days(): int {
    return max(1, min(365, (int)setting('scan_days', '30')));
}
