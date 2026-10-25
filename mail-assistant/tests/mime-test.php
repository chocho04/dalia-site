<?php
// php tests/mime-test.php — проверки на парсера на IMAP отговори и MIME частите.
require_once __DIR__ . '/../lib/imap.php';
require_once __DIR__ . '/../lib/mime.php';

$fails = 0;
function check(string $name, $got, $want): void {
    global $fails;
    if ($got === $want) { echo "ok   $name\n"; return; }
    $fails++;
    echo "FAIL $name\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n";
}

// --- парсер ---
$r = Imap::parse('* OK [UIDVALIDITY 1700000000] UIDs valid');
check('uidvalidity atom', $r[2], '[UIDVALIDITY 1700000000]');

$r = Imap::parse("* 3 FETCH (UID 42 BODY[1.2] \0" . "0\0)", ["%PDF-1.4 data"]);
check('literal value', $r[3][3], '%PDF-1.4 data');
check('section key', $r[3][2], 'BODY[1.2]');

$r = Imap::parse('* 1 FETCH (BODY[HEADER.FIELDS (SUBJECT)] NIL X "a \"q\" \\\\ b")');
check('bracket with parens', $r[3][0], 'BODY[HEADER.FIELDS (SUBJECT)]');
check('NIL', $r[3][1], null);
check('quoted escapes', $r[3][3], 'a "q" \\ b');

// --- Gmail-подобно писмо: текст + html + PDF с RFC 2231 име + вградено лого ---
$bs = Imap::parse('(((("text" "plain" ("charset" "UTF-8") NIL NIL "quoted-printable" 120 4 NIL NIL NIL)'
    . '("text" "html" ("charset" "UTF-8") NIL NIL "base64" 900 12 NIL NIL NIL) "alternative" ("boundary" "b2") NIL NIL)'
    . '("image" "png" ("name" "logo.png") "<logo@x>" NIL "base64" 6000 NIL ("inline" ("filename" "logo.png")) NIL) "related" ("boundary" "b1") NIL NIL)'
    . '("application" "pdf" ("name" "=?UTF-8?B?0KTQsNC60YLRg9GA0LAg4oSWIDEyMy5wZGY=?=") NIL NIL "base64" 140000 NIL ("attachment" ("filename*" "utf-8\'\'%D0%A4%D0%B0%D0%BA%D1%82%D1%83%D1%80%D0%B0%20%E2%84%96%20123.pdf")) NIL)'
    . '("application" "vnd.openxmlformats-officedocument.spreadsheetml.sheet" ("name" "spravka.xlsx") NIL NIL "base64" 30000 NIL ("attachment" ("filename" "spravka.xlsx")) NIL)'
    . '("application" "pkcs7-signature" ("name" "smime.p7s") NIL NIL "base64" 4000 NIL ("attachment" ("filename" "smime.p7s")) NIL)'
    . ' "mixed" ("boundary" "b0") NIL NIL)');
$parts = mime_parts($bs[0]);
check('sections', array_column($parts, 'section'), ['1.1.1', '1.1.2', '1.2', '2', '3', '4']);
$atts = mime_attachments($parts);
check('attachment names', array_column($atts, 'filename'), ['Фактура № 123.pdf', 'spravka.xlsx']);
check('supported', array_column($atts, 'supported'), [true, false]);
check('pdf size decoded', $atts[0]['size'], 105000);
check('text part', mime_text_part($parts)['section'], '1.1.1');

// --- единичен PDF без multipart; име с RFC 2231 продължения ---
$bs = Imap::parse('("application" "octet-stream" NIL NIL NIL "base64" 80000 NIL ("attachment" ("filename*0*" "windows-1251\'\'%D1%EC%E5" "filename*1" "tka.pdf")) NIL NIL)');
$atts = mime_attachments(mime_parts($bs[0]));
check('single part section', $atts[0]['section'] ?? null, '1');
check('rfc2231 continuation cp1251', $atts[0]['filename'] ?? null, 'Смеtka.pdf');
check('ext from filename', $atts[0]['ext'] ?? null, 'pdf');

// --- снимка без име, като прикачен файл ---
$bs = Imap::parse('("image" "jpeg" NIL NIL NIL "base64" 400000 NIL ("attachment" NIL) NIL NIL)');
$atts = mime_attachments(mime_parts($bs[0]));
check('unnamed jpeg', $atts[0]['filename'] ?? null, 'attachment-1.jpg');

// --- заглавия ---
check('encoded words joined', mime_header_decode('=?UTF-8?B?0KTQsNC6?= =?UTF-8?Q?=D1=82=D1=83=D1=80=D0=B0?= 10/2026'), 'Фактура 10/2026');
check('cp1251 word', mime_header_decode('=?windows-1251?B?' . base64_encode(iconv('UTF-8', 'CP1251', 'Сметка')) . '?='), 'Сметка');
check('envelope from', envelope_address([['=?UTF-8?B?0JXQktCd?=', null, 'Fakturi', 'EVN.bg']]), ['name' => 'ЕВН', 'email' => 'fakturi@evn.bg']);

// --- съдържание ---
check('base64 body', mime_decode_body(chunk_split(base64_encode('%PDF-1.7 abc')), 'base64'), '%PDF-1.7 abc');
check('qp body', mime_decode_body("=D0=A4=D0=B0=\r\n=D0=BA", 'quoted-printable'), 'Фак');

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
