<?php
// Сканиране на пощата, разпознаване на фактури/плащания и изпращане на
// прикачените файлове към docs.rtdalia.com.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/imap.php';
require_once __DIR__ . '/mime.php';
require_once __DIR__ . '/transcriber.php';

// Писмо с поне толкова точки е „вероятна фактура/плащане“ и файловете му са
// избрани по подразбиране.
const LIKELY_SCORE = 3;
// Колко файла най-много изпраща автоматично едно пускане на cron.php.
const AUTO_SEND_LIMIT = 20;

function imap_connect(): array {
    $imap = new Imap(IMAP_HOST, (int)IMAP_PORT);
    $imap->login(IMAP_USER, IMAP_PASS);
    $uidv = $imap->examine(IMAP_FOLDER);
    return [$imap, $uidv];
}

// ---- сканиране ----

function scan_mailbox(?Imap $imap = null, int $uidv = 0): array {
    $own = $imap === null;
    if ($own) [$imap, $uidv] = imap_connect();
    try {
        $days = scan_days();
        if ($imap->hasCap('X-GM-EXT-1')) {
            // Gmail: търсачката му директно филтрира писмата с прикачени файлове.
            $uids = $imap->search('X-GM-RAW ' . Imap::quote("has:attachment newer_than:{$days}d"));
        } else {
            $uids = $imap->search('SINCE ' . date('j-M-Y', strtotime("-$days days")));
        }

        $st = db()->prepare('SELECT uid FROM messages WHERE uidv = ?');
        $st->execute([$uidv]);
        $known = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        $new = array_values(array_filter($uids, fn($u) => !isset($known[$u])));

        $ins = db()->prepare("INSERT INTO messages
            (mkey, uidv, uid, message_id, from_name, from_email, subject, received_at, attachments, text_part, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(mkey) DO UPDATE SET uidv = excluded.uidv, uid = excluded.uid");
        $found = 0;
        foreach (array_chunk($new, 50) as $chunk) {
            foreach ($imap->fetch($chunk, 'UID INTERNALDATE ENVELOPE BODYSTRUCTURE') as $uid => $f) {
                $env = is_array($f['ENVELOPE'] ?? null) ? $f['ENVELOPE'] : [];
                $parts = mime_parts($f['BODYSTRUCTURE'] ?? null);
                $atts = mime_attachments($parts);
                $text = mime_text_part($parts);
                $from = envelope_address($env[2] ?? null);
                $messageId = trim((string)($env[9] ?? ''));
                $ins->execute([
                    $messageId !== '' ? 'm:' . sha1($messageId) : "u:$uidv:$uid",
                    $uidv,
                    $uid,
                    $messageId,
                    $from['name'],
                    $from['email'],
                    mime_header_decode($env[1] ?? ''),
                    strtotime((string)($f['INTERNALDATE'] ?? '')) ?: time(),
                    json_encode($atts, JSON_UNESCAPED_UNICODE),
                    $text ? json_encode($text) : '',
                    time(),
                ]);
                if ($atts) $found++;
            }
        }
        set_setting('last_scan', (string)time());
        prune_messages($days);
        return ['checked' => count($new), 'found' => $found];
    } finally {
        if ($own) $imap->logout();
    }
}

function prune_messages(int $days): void {
    $cutoff = time() - max($days + 30, 90) * 86400;
    db()->prepare('DELETE FROM messages WHERE received_at < ?')->execute([$cutoff]);
    db()->exec('DELETE FROM sends WHERE mkey NOT IN (SELECT mkey FROM messages)');
}

// ---- разпознаване ----

// Връща съвпадналия шаблон: точен адрес (ivan@firma.bg) или домейн
// (firma.bg / @firma.bg — вкл. поддомейни).
function sender_match(string $email, array $patterns): ?string {
    if ($email === '') return null;
    foreach ($patterns as $p) {
        $domain = ltrim($p, '@');
        if (strpos($domain, '@') !== false) {
            if ($email === $domain) return $p;
        } elseif ($domain !== '' && (str_ends_with($email, '@' . $domain) || str_ends_with($email, '.' . $domain))) {
            return $p;
        }
    }
    return null;
}

function detect(array $m, array $atts, array $ctx): array {
    $score = 0;
    $reasons = [];
    $hit = function (string $text) use ($ctx) {
        foreach ($ctx['keywords'] as $k) {
            if ($text !== '' && mb_stripos($text, $k) !== false) return $k;
        }
        return null;
    };
    if (sender_match($m['from_email'], $ctx['auto']) !== null) {
        $score += 5;
        $reasons[] = 'Подател за автоматично изпращане';
    }
    if (($k = $hit($m['subject'])) !== null) {
        $score += 3;
        $reasons[] = "Тема: „{$k}“";
    }
    foreach ($atts as $a) {
        if (($k = $hit($a['filename'])) !== null) {
            $score += 3;
            $reasons[] = "Файл: „{$k}“";
            break;
        }
    }
    if (($k = $hit($m['from_name'] . ' ' . $m['from_email'])) !== null) {
        $score += 2;
        $reasons[] = "Подател: „{$k}“";
    }
    if (isset($ctx['sentSenders'][$m['from_email']])) {
        $score += 2;
        $reasons[] = 'Изпращали сте от този подател';
    }
    foreach ($atts as $a) {
        if ($a['ext'] === 'pdf') { $score += 1; break; }
    }
    return [$score, $reasons];
}

function detect_context(): array {
    $sent = db()->query("SELECT DISTINCT m.from_email FROM messages m
        JOIN sends s ON s.mkey = m.mkey WHERE s.status = 'sent' AND m.from_email != ''")->fetchAll(PDO::FETCH_COLUMN);
    return [
        'keywords'    => setting_list('keywords', DEFAULT_KEYWORDS),
        'auto'        => setting_list('auto_senders'),
        'ignore'      => setting_list('ignore_senders'),
        'autoAdded'   => json_decode((string)setting('auto_senders_added', '{}'), true) ?: [],
        'sentSenders' => array_flip($sent),
    ];
}

// ---- списък за интерфейса ----

function sends_by_mkey(): array {
    $out = [];
    foreach (db()->query('SELECT * FROM sends') as $s) $out[$s['mkey']][$s['section']] = $s;
    return $out;
}

function send_view(?array $s): ?array {
    if (!$s) return null;
    return [
        'status'    => $s['status'],
        'docId'     => $s['doc_id'],
        'dest'      => $s['dest'],
        'savedType' => $s['saved_type'],
        'error'     => $s['error'],
        'retryable' => (bool)$s['retryable'],
        'attempts'  => (int)$s['attempts'],
        'auto'      => (bool)$s['auto'],
        'at'        => (int)$s['updated_at'],
    ];
}

function message_view(array $row, array $sends, array $ctx): array {
    $atts = json_decode($row['attachments'], true) ?: [];
    [$score, $reasons] = detect($row, $atts, $ctx);
    foreach ($atts as &$a) {
        $a['send'] = send_view($sends[$a['section']] ?? null);
        unset($a['encoding']);
    }
    unset($a);
    $autoPattern = sender_match($row['from_email'], $ctx['auto']);
    return [
        'id'          => (int)$row['id'],
        'fromName'    => $row['from_name'],
        'fromEmail'   => $row['from_email'],
        'subject'     => $row['subject'],
        'date'        => (int)$row['received_at'],
        'dismissed'   => (bool)$row['dismissed'],
        'ignored'     => sender_match($row['from_email'], $ctx['ignore']) !== null,
        'auto'        => $autoPattern !== null,
        // Автоматично се изпращат само писма, получени след добавянето на
        // подателя в списъка — по-старите остават за ръчно решение.
        'autoEligible' => $autoPattern !== null && (int)$row['received_at'] >= (int)($ctx['autoAdded'][$autoPattern] ?? 0),
        'score'       => $score,
        'likely'      => $score >= LIKELY_SCORE,
        'reasons'     => $reasons,
        'attachments' => $atts,
    ];
}

function list_messages(): array {
    $ctx = detect_context();
    $sends = sends_by_mkey();
    $st = db()->prepare("SELECT * FROM messages WHERE attachments != '[]' AND received_at >= ? ORDER BY received_at DESC");
    $st->execute([time() - scan_days() * 86400]);
    $out = [];
    foreach ($st as $row) $out[] = message_view($row, $sends[$row['mkey']] ?? [], $ctx);
    return $out;
}

function message_row(int $id): ?array {
    $st = db()->prepare('SELECT * FROM messages WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function message_attachment(array $row, string $section): ?array {
    foreach (json_decode($row['attachments'], true) ?: [] as $a) {
        if ($a['section'] === $section) return $a;
    }
    return null;
}

function single_message_view(int $id): ?array {
    $row = message_row($id);
    if (!$row) return null;
    return message_view($row, sends_by_mkey()[$row['mkey']] ?? [], detect_context());
}

// ---- изтегляне и изпращане ----

function fetch_attachment_bytes(array $row, array $att, ?Imap $imap = null, int $uidv = 0): string {
    $own = $imap === null;
    if ($own) [$imap, $uidv] = imap_connect();
    try {
        if ($uidv !== (int)$row['uidv']) {
            throw new RuntimeException('Пощата е преиндексирана — натиснете „Обнови“ и опитайте пак.');
        }
        $raw = $imap->fetchSection((int)$row['uid'], $att['section']);
    } finally {
        if ($own) $imap->logout();
    }
    if ($raw === null || $raw === '') throw new RuntimeException('Писмото или файлът вече не е в пощата.');
    return mime_decode_body($raw, $att['encoding']);
}

function send_attachment(array $row, array $att, bool $auto, bool $force = false, ?Imap $imap = null, int $uidv = 0): array {
    $st = db()->prepare('SELECT * FROM sends WHERE mkey = ? AND section = ?');
    $st->execute([$row['mkey'], $att['section']]);
    $prev = $st->fetch() ?: null;
    if ($prev && $prev['status'] === 'sending' && (int)$prev['updated_at'] > time() - 300) {
        return ['ok' => false, 'error' => 'Файлът вече се изпраща.', 'send' => send_view($prev)];
    }
    if ($prev && $prev['status'] === 'sent' && !$force) {
        return ['ok' => true, 'send' => send_view($prev)];
    }

    db()->prepare("INSERT INTO sends (mkey, section, status, attempts, auto, updated_at) VALUES (?, ?, 'sending', 1, ?, ?)
        ON CONFLICT(mkey, section) DO UPDATE SET status = 'sending', error = '', attempts = attempts + 1,
        auto = excluded.auto, updated_at = excluded.updated_at")
        ->execute([$row['mkey'], $att['section'], $auto ? 1 : 0, time()]);

    try {
        if (!$att['supported']) throw new RuntimeException('Форматът не се поддържа от docs (само PDF и снимки).');
        $bytes = fetch_attachment_bytes($row, $att, $imap, $uidv);
        $filename = $att['filename'];
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $att['ext']) $filename .= '.' . $att['ext'];
        // Един и същ файл → един и същ clientDocId, така повторен опит след
        // изгубен отговор не създава дубликат в docs. Нарочното повторно
        // изпращане на вече изпратен файл получава нов идентификатор.
        $clientDocId = 'mail_' . substr(sha1($row['mkey'] . '|' . $att['section']), 0, 40)
            . ($prev && $prev['status'] === 'sent' ? '_r' . time() : '');
        $res = transcriber_send($bytes, $filename, $clientDocId);
    } catch (Throwable $e) {
        $res = ['ok' => false, 'error' => $e->getMessage(), 'retryable' => $e instanceof ImapError];
    }

    if ($res['ok']) {
        db()->prepare("UPDATE sends SET status = 'sent', doc_id = ?, dest = ?, saved_type = ?, error = '',
            retryable = 0, updated_at = ? WHERE mkey = ? AND section = ?")
            ->execute([(string)($res['docId'] ?? ''), (string)($res['dest'] ?? ''), (string)($res['savedType'] ?? ''),
                time(), $row['mkey'], $att['section']]);
    } else {
        db()->prepare("UPDATE sends SET status = 'error', error = ?, retryable = ?, updated_at = ?
            WHERE mkey = ? AND section = ?")
            ->execute([mb_substr((string)$res['error'], 0, 500), !empty($res['retryable']) ? 1 : 0, time(),
                $row['mkey'], $att['section']]);
    }
    $st->execute([$row['mkey'], $att['section']]);
    return ['ok' => $res['ok'], 'error' => $res['ok'] ? null : $res['error'], 'send' => send_view($st->fetch() ?: null)];
}

// Автоматично изпращане (cron.php) за писмата от подателите в списъка.
function auto_send_pending(Imap $imap, int $uidv): array {
    $sent = 0;
    $failed = 0;
    foreach (list_messages() as $m) {
        if (!$m['autoEligible'] || $m['dismissed'] || $m['ignored']) continue;
        $row = null;
        foreach ($m['attachments'] as $a) {
            $s = $a['send'];
            if (!$a['supported']) continue;
            if ($s && ($s['status'] === 'sent' || $s['status'] === 'sending' || !$s['retryable'] || $s['attempts'] >= 3)) continue;
            if ($sent + $failed >= AUTO_SEND_LIMIT) break 2;
            $row = $row ?? message_row($m['id']);
            $res = send_attachment($row, message_attachment($row, $a['section']), true, false, $imap, $uidv);
            $res['ok'] ? $sent++ : $failed++;
        }
    }
    return ['sent' => $sent, 'failed' => $failed];
}

// Текст на писмото за бърз преглед (първите ~6 KB).
function message_preview(array $row): string {
    $part = $row['text_part'] !== '' ? json_decode($row['text_part'], true) : null;
    if (!$part) return '';
    [$imap, $uidv] = imap_connect();
    try {
        if ($uidv !== (int)$row['uidv']) return '';
        $raw = $imap->fetchSection((int)$row['uid'], $part['section'], 6000);
    } finally {
        $imap->logout();
    }
    $decoded = mime_decode_body((string)$raw, $part['encoding']);
    // Съкратеният UTF-8 текст може да завършва с половин символ — това не
    // бива да го прави „невалиден UTF-8“ и да го праща към cp1251.
    $text = in_array(strtoupper($part['charset']), ['UTF-8', 'UTF8'], true)
        ? mb_scrub($decoded, 'UTF-8')
        : to_utf8($decoded, $part['charset']);
    if ($part['html']) {
        $text = preg_replace('#<(style|script|head)\b.*?</\1>#is', '', $text);
        $text = preg_replace('#<br\s*/?>|</(p|div|tr|li|h\d)>#i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $text = preg_replace("/[ \t]+/u", ' ', $text);
    $text = preg_replace("/\n\s*\n\s*\n+/u", "\n\n", $text);
    return trim(mb_scrub($text, 'UTF-8'));
}
