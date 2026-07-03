<?php
// Показва селфи на администратор: selfie.php?id=123&which=in|out
// Аватар (последното селфи на служител, без вход): selfie.php?avatar=<employee_id>
require_once __DIR__ . '/db.php';

function serve_jpeg(?string $rel, int $maxAge = 86400): void {
    if (!$rel || !preg_match('#^[0-9]{6}/[0-9A-Za-z_]+\.jpg$#', $rel)) {
        http_response_code(404); exit('Not found');
    }
    $path = __DIR__ . '/selfies/' . $rel;
    if (!is_file($path)) { http_response_code(404); exit('Not found'); }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=' . $maxAge);
    readfile($path);
    exit;
}

session_start();
$isAdmin = !empty($_SESSION['admin']);

// Аватар за списъците: последното селфи на служител (от най-новата смяна;
// снимката при отписване е по-нова от тази при записване).
// Публично само за активни (както списъка с имена в киоска); админ вижда и неактивни.
// По-кратък кеш, за да се обнови аватарът скоро след нова снимка.
$avatarEmp = (int)($_GET['avatar'] ?? 0);
if ($avatarEmp) {
    $activeFilter = $isAdmin ? '' : 'AND e.active = 1';
    $st = db()->prepare(
        "SELECT t.selfie_in, t.selfie_out FROM time_entries t
           JOIN employees e ON e.id = t.employee_id $activeFilter
          WHERE t.employee_id = ? AND (t.selfie_in IS NOT NULL OR t.selfie_out IS NOT NULL)
          ORDER BY t.clock_in DESC LIMIT 1"
    );
    $st->execute([$avatarEmp]);
    $row = $st->fetch();
    serve_jpeg($row ? ($row['selfie_out'] ?: $row['selfie_in']) : null, 300);
}

if (!$isAdmin) { http_response_code(403); exit('Forbidden'); }

$id = (int)($_GET['id'] ?? 0);
$which = $_GET['which'] === 'out' ? 'selfie_out' : 'selfie_in';

$st = db()->prepare("SELECT $which AS f FROM time_entries WHERE id = ?");
$st->execute([$id]);
$row = $st->fetch();
serve_jpeg($row ? $row['f'] : null);
