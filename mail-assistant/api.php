<?php
// JSON API за интерфейса (assets/app.js).
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/mailbox.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array {
    $j = json_decode((string)file_get_contents('php://input'), true);
    return is_array($j) ? $j : [];
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['error' => 'Method Not Allowed'], 405);
}

function list_payload(): array {
    return [
        'ok'       => true,
        'messages' => list_messages(),
        'lastScan' => (int)setting('last_scan', '0'),
        'scanDays' => scan_days(),
    ];
}

function find_attachment(int $id, string $section): array {
    $row = message_row($id);
    $att = $row ? message_attachment($row, $section) : null;
    if (!$row || !$att) out(['error' => 'Файлът не е намерен.'], 404);
    return [$row, $att];
}

$action = (string)($_GET['action'] ?? '');

// Собственият header не може да се изпрати от чужд сайт без CORS разрешение,
// така че POST заявките не могат да се подправят (CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'mail-assistant') {
    out(['error' => 'forbidden'], 403);
}

try {
    if ($action === 'login') {
        require_post();
        if (!auth_configured()) out(['error' => 'Задайте APP_PASSWORD (поне 6 символа) в config.php.'], 400);
        if (auth_login((string)(input()['password'] ?? ''))) out(['ok' => true]);
        out(['error' => 'Грешна парола.'], 401);
    }
    if (!auth_ok()) out(['error' => 'unauthorized'], 401);

    switch ($action) {
        case 'logout':
            require_post();
            auth_logout();
            out(['ok' => true]);

        case 'list':
            out(list_payload());

        case 'scan':
            require_post();
            set_time_limit(180);
            $stats = scan_mailbox();
            out(['stats' => $stats] + list_payload());

        case 'send':
            require_post();
            set_time_limit(240);
            ignore_user_abort(true); // анализът да завърши, дори браузърът да се откаже
            $in = input();
            [$row, $att] = find_attachment((int)($in['id'] ?? 0), (string)($in['section'] ?? ''));
            $res = send_attachment($row, $att, false, !empty($in['force']));
            out($res + ['message' => single_message_view((int)$row['id'])]);

        case 'dismiss':
            require_post();
            $in = input();
            db()->prepare('UPDATE messages SET dismissed = ? WHERE id = ?')
                ->execute([!empty($in['value']) ? 1 : 0, (int)($in['id'] ?? 0)]);
            out(['ok' => true, 'message' => single_message_view((int)($in['id'] ?? 0))]);

        case 'preview':
            $row = message_row((int)($_GET['id'] ?? 0));
            if (!$row) out(['error' => 'Писмото не е намерено.'], 404);
            out(['ok' => true, 'text' => message_preview($row)]);

        case 'file':
            [$row, $att] = find_attachment((int)($_GET['id'] ?? 0), (string)($_GET['section'] ?? ''));
            $bytes = fetch_attachment_bytes($row, $att);
            $mime = EXT_MIME[$att['ext']] ?? null;
            $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/u', '_', $att['filename']);
            header('Content-Type: ' . ($mime ?? 'application/octet-stream'));
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: ' . ($mime ? 'inline' : 'attachment')
                . "; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($att['filename']));
            header('Content-Length: ' . strlen($bytes));
            echo $bytes;
            exit;

        case 'settings':
            out(['ok' => true, 'settings' => settings_payload()]);

        case 'settings-save':
            require_post();
            $in = input();
            $keywords = trim((string)($in['keywords'] ?? ''));
            if ($keywords === '') {
                db()->prepare('DELETE FROM settings WHERE skey = ?')->execute(['keywords']); // обратно към думите по подразбиране
            } else {
                set_setting('keywords', $keywords);
            }
            set_setting('ignore_senders', trim((string)($in['ignoreSenders'] ?? '')));
            set_setting('auto_senders', trim((string)($in['autoSenders'] ?? '')));
            // Помни кога е добавен всеки подател за автоматично изпращане.
            $added = json_decode((string)setting('auto_senders_added', '{}'), true) ?: [];
            $next = [];
            foreach (setting_list('auto_senders') as $p) $next[$p] = $added[$p] ?? time();
            set_setting('auto_senders_added', json_encode($next));
            set_setting('scan_days', (string)max(1, min(365, (int)($in['scanDays'] ?? 30))));
            out(['ok' => true, 'settings' => settings_payload()] + list_payload());

        case 'test':
            $result = ['docs' => transcriber_check()];
            try {
                [$imap, $uidv] = imap_connect();
                $result['imap'] = ['ok' => true, 'gmail' => $imap->hasCap('X-GM-EXT-1')];
                $imap->logout();
            } catch (Throwable $e) {
                $result['imap'] = ['ok' => false, 'error' => $e->getMessage()];
            }
            out(['ok' => true] + $result);
    }
    out(['error' => 'Invalid action.'], 400);
} catch (Throwable $e) {
    out(['error' => $e->getMessage()], 500);
}

function settings_payload(): array {
    $keywords = setting('keywords');
    return [
        'keywords'       => $keywords ?? implode("\n", DEFAULT_KEYWORDS),
        'autoSenders'    => (string)setting('auto_senders', ''),
        'ignoreSenders'  => (string)setting('ignore_senders', ''),
        'scanDays'       => scan_days(),
        'imapUser'       => IMAP_USER,
        'imapHost'       => IMAP_HOST,
        'imapFolder'     => IMAP_FOLDER,
        'transcriberUrl' => transcriber_base(),
        'cronCommand'    => 'php ' . realpath(__DIR__ . '/cron.php'),
    ];
}
