<?php
// Декодиране на заглавия, BODYSTRUCTURE и съдържание на MIME части.

// Формати, които docs.rtdalia.com/api/analyze-doc приема.
const SUPPORTED_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
// Офис/архивни файлове — показват се, но не могат да се изпратят за анализ.
const LISTED_UNSUPPORTED_EXT = ['doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'rtf', 'xml', 'zip', 'heic'];

const MIME_EXT = [
    'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/pjpeg' => 'jpg',
    'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic',
];
const EXT_MIME = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png', 'webp' => 'image/webp',
];

function to_utf8(string $s, string $charset = ''): string {
    $charset = strtoupper(trim($charset));
    if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8' || $charset === 'US-ASCII') {
        if (mb_check_encoding($s, 'UTF-8')) return $s;
        $charset = 'WINDOWS-1251'; // писма без обявена кодировка у нас обикновено са cp1251
    }
    $out = @iconv($charset, 'UTF-8//IGNORE', $s);
    if ($out === false) $out = @mb_convert_encoding($s, 'UTF-8', $charset);
    return $out === false ? mb_scrub($s, 'UTF-8') : $out;
}

// RFC 2047: =?charset?B|Q?…?= думи в заглавия.
function mime_header_decode(?string $s): string {
    if ($s === null || $s === '') return '';
    $s = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $s); // интервалите между кодирани думи се пропускат
    $out = preg_replace_callback('/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function ($m) {
        $charset = preg_replace('/\*.*$/', '', $m[1]);
        $raw = strtoupper($m[2]) === 'B'
            ? (string)base64_decode($m[3])
            : quoted_printable_decode(str_replace('_', ' ', $m[3]));
        return to_utf8($raw, $charset);
    }, $s);
    return trim(to_utf8($out));
}

function imap_pairs($list): array {
    $out = [];
    if (!is_array($list)) return $out;
    for ($i = 0; $i + 1 < count($list); $i += 2) {
        $out[strtolower((string)$list[$i])] = $list[$i + 1];
    }
    return $out;
}

// Разглежда BODYSTRUCTURE и връща плосък списък с крайните части.
function mime_parts($bs, string $prefix = ''): array {
    if (!is_array($bs) || !$bs) return [];
    if (is_array($bs[0])) { // multipart: (част)(част)… "подтип" …
        $parts = [];
        for ($k = 0; isset($bs[$k]) && is_array($bs[$k]); $k++) {
            $section = $prefix === '' ? (string)($k + 1) : "$prefix." . ($k + 1);
            $parts = array_merge($parts, mime_parts($bs[$k], $section));
        }
        return $parts;
    }
    $type = strtolower((string)$bs[0]);
    $subtype = strtolower((string)($bs[1] ?? ''));
    // Разширените полета след size са на различна позиция според типа.
    $dispIdx = $type === 'text' ? 9 : ($type === 'message' && $subtype === 'rfc822' ? 11 : 8);
    $disp = $bs[$dispIdx] ?? null;
    return [[
        'section'     => $prefix === '' ? '1' : $prefix,
        'type'        => $type,
        'subtype'     => $subtype,
        'params'      => imap_pairs($bs[2] ?? null),
        'id'          => (string)($bs[3] ?? ''),
        'encoding'    => strtolower((string)($bs[5] ?? '7bit')),
        'size'        => (int)($bs[6] ?? 0),
        'disposition' => is_array($disp) ? strtolower((string)($disp[0] ?? '')) : '',
        'dparams'     => is_array($disp) ? imap_pairs($disp[1] ?? null) : [],
    ]];
}

// Името на файла: filename (Content-Disposition) или name (Content-Type),
// вкл. RFC 2231 вариантите filename*=utf-8''… и filename*0*=…
function mime_part_filename(array $p): string {
    foreach ([$p['dparams'], $p['params']] as $params) {
        foreach (['filename', 'name'] as $key) {
            if (isset($params["$key*"])) return rfc2231_value((string)$params["$key*"]);
            if (isset($params["$key*0"]) || isset($params["$key*0*"])) {
                $raw = '';
                $charset = 'UTF-8';
                for ($i = 0; isset($params["$key*$i*"]) || isset($params["$key*$i"]); $i++) {
                    if (isset($params["$key*$i*"])) {
                        $v = (string)$params["$key*$i*"];
                        if ($i === 0 && preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
                            $charset = $m[1] !== '' ? $m[1] : 'UTF-8';
                            $v = $m[2];
                        }
                        $raw .= rawurldecode($v);
                    } else {
                        $raw .= (string)$params["$key*$i"];
                    }
                }
                return trim(to_utf8($raw, $charset));
            }
            if (isset($params[$key]) && trim((string)$params[$key]) !== '') {
                return mime_header_decode((string)$params[$key]);
            }
        }
    }
    return '';
}

function rfc2231_value(string $v): string {
    if (preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
        return trim(to_utf8(rawurldecode($m[2]), $m[1] !== '' ? $m[1] : 'UTF-8'));
    }
    return mime_header_decode($v);
}

/**
 * Прикачените файлове, които си струва да се покажат: PDF/снимки (+ офис
 * файлове като неподдържани). Пропуска текста на писмото, вградените лога
 * от подписи и дребни иконки.
 */
function mime_attachments(array $parts): array {
    $out = [];
    foreach ($parts as $p) {
        if ($p['type'] === 'multipart' || ($p['type'] === 'message' && $p['subtype'] === 'rfc822')) continue;
        $name = mime_part_filename($p);
        $mime = $p['type'] . '/' . $p['subtype'];
        if ($p['type'] === 'text' && $p['disposition'] !== 'attachment') continue;
        if ($name === '' && $p['disposition'] !== 'attachment' && $p['type'] !== 'application' && $p['type'] !== 'image') continue;

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, SUPPORTED_EXT, true) && !in_array($ext, LISTED_UNSUPPORTED_EXT, true)) {
            $ext = MIME_EXT[$mime] ?? $ext;
        }
        $supported = in_array($ext, SUPPORTED_EXT, true);
        if (!$supported && !in_array($ext, LISTED_UNSUPPORTED_EXT, true)) continue;

        // Приблизителен декодиран размер (base64 е ~4/3 по-голям).
        $size = $p['encoding'] === 'base64' ? (int)($p['size'] * 3 / 4) : $p['size'];
        if ($p['type'] === 'image' || in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $inline = $p['disposition'] === 'inline' || ($p['disposition'] === '' && $p['id'] !== '');
            if ($size < 10000 || ($inline && $size < 40000)) continue; // лога, икони, подписи
        }
        if ($name === '') $name = 'attachment-' . $p['section'] . '.' . $ext;

        $out[] = [
            'section'   => $p['section'],
            'filename'  => $name,
            'ext'       => $ext,
            'size'      => $size,
            'encoding'  => $p['encoding'],
            'supported' => $supported,
        ];
    }
    return $out;
}

// Първата текстова част на писмото (за преглед): text/plain, иначе text/html.
function mime_text_part(array $parts): ?array {
    $html = null;
    foreach ($parts as $p) {
        if ($p['type'] !== 'text' || $p['disposition'] === 'attachment') continue;
        $info = [
            'section'  => $p['section'],
            'encoding' => $p['encoding'],
            'charset'  => (string)($p['params']['charset'] ?? ''),
            'html'     => $p['subtype'] === 'html',
        ];
        if ($p['subtype'] === 'plain') return $info;
        if ($p['subtype'] === 'html' && $html === null) $html = $info;
    }
    return $html;
}

function mime_decode_body(string $data, string $encoding): string {
    if ($encoding === 'base64') {
        $clean = preg_replace('/[^A-Za-z0-9+\/=]/', '', $data);
        $clean = substr($clean, 0, strlen($clean) - strlen($clean) % 4); // съкратен преглед
        return (string)base64_decode($clean);
    }
    if ($encoding === 'quoted-printable') return quoted_printable_decode($data);
    return $data;
}

// Адрес от ENVELOPE: (име adl кутия домейн).
function envelope_address($list): array {
    $a = is_array($list) && isset($list[0]) && is_array($list[0]) ? $list[0] : null;
    if (!$a) return ['name' => '', 'email' => ''];
    $email = strtolower(trim((string)($a[2] ?? '') . '@' . (string)($a[3] ?? ''), '@'));
    return ['name' => mime_header_decode($a[0] ?? ''), 'email' => $email];
}
