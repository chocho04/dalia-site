<?php
require_once __DIR__ . '/db.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$in = json_decode(file_get_contents('php://input'), true) ?: [];

function out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail($msg, $code = 400) { http_response_code($code); out(['error' => $msg]); }
function require_admin() { if (empty($_SESSION['admin'])) fail('Не сте влезли.', 401); }

function valid_dt(?string $s): ?string {
    if ($s === null || $s === '') return null;
    $s = str_replace('T', ' ', $s);
    if (strlen($s) === 16) $s .= ':00';
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    if (!$d || $d->format('Y-m-d H:i:s') !== $s) fail('Невалидна дата/час.');
    return $s;
}

function valid_date(string $s): string {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) fail('Невалидна дата.');
    return $s;
}

function save_selfie(?string $dataUrl): ?string {
    if (!$dataUrl) return null;
    if (!preg_match('#^data:image/jpeg;base64,(.+)$#', $dataUrl, $m)) fail('Невалидна снимка.');
    $bytes = base64_decode($m[1], true);
    if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) fail('Невалидна снимка.');
    $dir = __DIR__ . '/selfies/' . date('Ym');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = date('Ym') . '/' . date('d_His') . '_' . bin2hex(random_bytes(6)) . '.jpg';
    file_put_contents(__DIR__ . '/selfies/' . $name, $bytes);
    return $name;
}

// Списък с длъжности за избор (настройка; редактира се в Настройки).
function positions_list(): array {
    $raw = setting('positions');
    $arr = $raw ? json_decode($raw, true) : null;
    if (!is_array($arr) || !$arr) {
        $arr = ['Сервитьор', 'Барман', 'Аниматор', 'Готвач', 'Раб. Кухня'];
    }
    return array_values($arr);
}

// Ако служител има смяна и работни дни по подразбиране, попълваме графика му
// за седмицата автоматично — само ако още няма нито един ред за нея и само за
// текуща/бъдеща седмица (миналото е история и не се дописва).
function apply_default_schedules(string $monday, string $sunday): void {
    if ($sunday < date('Y-m-d')) return;
    $emps = db()->query(
        "SELECT id, default_shift_id, default_days FROM employees
          WHERE active = 1 AND default_shift_id > 0 AND default_days <> ''"
    )->fetchAll();
    if (!$emps) return;
    $chk = db()->prepare("SELECT COUNT(*) AS c FROM schedule WHERE employee_id = ? AND day >= ? AND day <= ?");
    $shiftChk = db()->prepare("SELECT id FROM shift_types WHERE id = ?");
    $ins = db()->prepare("INSERT INTO schedule (employee_id, day, shift_type_id, note) VALUES (?, ?, ?, '')");
    foreach ($emps as $e) {
        $chk->execute([$e['id'], $monday, $sunday]);
        if ($chk->fetch()['c'] > 0) continue;
        $shiftChk->execute([$e['default_shift_id']]);
        if (!$shiftChk->fetch()) continue;
        foreach (explode(',', $e['default_days']) as $dn) {
            $dn = (int)$dn;
            if ($dn < 1 || $dn > 7) continue;
            $day = date('Y-m-d', strtotime($monday . ' +' . ($dn - 1) . ' days'));
            if ($day < date('Y-m-d')) continue; // не дописваме минали дни
            $ins->execute([$e['id'], $day, $e['default_shift_id']]);
        }
    }
}

// Смените на един служител не могат да се застъпват: нова смяна може да
// започне най-рано в момента, в който предишната свършва. Проверява дали
// интервал [in, out) (out = null → все още отворена) се застъпва с друга смяна.
function shift_overlaps(int $empId, string $in, ?string $out, int $excludeId = 0): bool {
    $sql = "SELECT COUNT(*) AS c FROM time_entries
             WHERE employee_id = ? AND id != ?
               AND (clock_out IS NULL OR clock_out > ?)";
    $params = [$empId, $excludeId, $in];
    if ($out !== null) {
        $sql .= " AND clock_in < ?";
        $params[] = $out;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetch()['c'] > 0;
}

// Миграции за стари бази (еднократно, отбелязано в settings).
function ensure_schema(): void {
    if (setting('schema_auto_closed') !== '1') {
        try {
            db()->exec("ALTER TABLE time_entries ADD COLUMN auto_closed TINYINT NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            // колоната вече съществува — няма проблем
        }
        set_setting('schema_auto_closed', '1');
    }
    if (setting('schema_payments') !== '1') {
        $charset = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' DEFAULT CHARSET=utf8mb4' : '';
        db()->exec("CREATE TABLE IF NOT EXISTS payments (
            employee_id INT NOT NULL,
            period VARCHAR(12) NOT NULL,
            PRIMARY KEY (employee_id, period)
        )$charset");
        set_setting('schema_payments', '1');
    }
    if (setting('schema_schedules') !== '1') {
        $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idCol = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $charset = $driver === 'mysql' ? ' DEFAULT CHARSET=utf8mb4' : '';
        db()->exec("CREATE TABLE IF NOT EXISTS shift_types (
            id $idCol,
            name VARCHAR(100) NOT NULL,
            start_time VARCHAR(5) NOT NULL,
            end_time VARCHAR(5) NOT NULL
        )$charset");
        db()->exec("CREATE TABLE IF NOT EXISTS schedule (
            employee_id INT NOT NULL,
            day DATE NOT NULL,
            shift_type_id INT NOT NULL,
            note VARCHAR(200) NOT NULL DEFAULT '',
            PRIMARY KEY (employee_id, day)
        )$charset");
        set_setting('schema_schedules', '1');
    }
    if (setting('schema_schedule_note') !== '1') {
        try {
            db()->exec("ALTER TABLE schedule ADD COLUMN note VARCHAR(200) NOT NULL DEFAULT ''");
        } catch (PDOException $e) {
            // колоната вече съществува — няма проблем
        }
        set_setting('schema_schedule_note', '1');
    }
    if (setting('schema_emp_defaults') !== '1') {
        foreach (["ALTER TABLE employees ADD COLUMN default_shift_id INT NOT NULL DEFAULT 0",
                  "ALTER TABLE employees ADD COLUMN default_days VARCHAR(20) NOT NULL DEFAULT ''"] as $sql) {
            try { db()->exec($sql); } catch (PDOException $e) { /* колоната съществува */ }
        }
        set_setting('schema_emp_defaults', '1');
    }
    if (setting('schema_shift_abbr') !== '1') {
        try {
            db()->exec("ALTER TABLE shift_types ADD COLUMN abbr VARCHAR(10) NOT NULL DEFAULT ''");
        } catch (PDOException $e) { /* колоната съществува */ }
        set_setting('schema_shift_abbr', '1');
    }
    if (setting('schema_emp_sort') !== '1') {
        try {
            db()->exec("ALTER TABLE employees ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
            // начален ред = текущата азбучна подредба
            $i = 0;
            $upd = db()->prepare("UPDATE employees SET sort_order = ? WHERE id = ?");
            foreach (db()->query("SELECT id FROM employees ORDER BY name")->fetchAll() as $r) {
                $upd->execute([++$i * 10, $r['id']]);
            }
        } catch (PDOException $e) { /* колоната съществува */ }
        set_setting('schema_emp_sort', '1');
    }
    if (setting('schema_shift_requests') !== '1') {
        $driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $idCol = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $charset = $driver === 'mysql' ? ' DEFAULT CHARSET=utf8mb4' : '';
        db()->exec("CREATE TABLE IF NOT EXISTS shift_requests (
            id $idCol,
            employee_id INT NOT NULL,
            day DATE NOT NULL,
            shift_type_id INT NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            resolved_at DATETIME NULL
        )$charset");
        set_setting('schema_shift_requests', '1');
    }
}

// Автоматично затваря забравени смени: всяка отворена смяна се затваря
// в първия зададен час (по подр. 01:30), настъпил след записването.
// Така се обработват и нощните смени (започнати предната вечер).
// Може да се изключи от Настройки (auto_close_enabled).
function auto_close_overdue_shifts(): void {
    if (setting('auto_close_enabled', '1') !== '1') return;
    $t = setting('auto_close_time', '01:30');
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) { $m = [null, 1, 30]; }
    $ch = (int)$m[1]; $cm = (int)$m[2];
    $rows = db()->query("SELECT id, clock_in FROM time_entries WHERE clock_out IS NULL")->fetchAll();
    if (!$rows) return;
    $now = new DateTime();
    $upd = db()->prepare("UPDATE time_entries SET clock_out = ?, auto_closed = 1 WHERE id = ?");
    foreach ($rows as $e) {
        $inDt = new DateTime($e['clock_in']);
        $cutoff = (clone $inDt)->setTime($ch, $cm, 0);
        if ($cutoff <= $inDt) $cutoff->modify('+1 day');
        if ($now >= $cutoff) {
            $upd->execute([$cutoff->format('Y-m-d H:i:s'), $e['id']]);
        }
    }
}

ensure_schema();
if (in_array($action, ['clock', 'employees', 'timesheet', 'report'], true)) {
    auto_close_overdue_shifts();
}

switch ($action) {

// ---------- Приложение за служители (без вход) ----------

case 'employees':
    $wkMon = date('Y-m-d', strtotime('-' . ((int)date('N') - 1) . ' days'));
    apply_default_schedules($wkMon, date('Y-m-d', strtotime($wkMon . ' +6 days')));
    $rows = db()->query(
        "SELECT e.id, e.name, e.position,
                (SELECT COUNT(*) FROM time_entries t
                  WHERE t.employee_id = e.id AND t.clock_out IS NULL) AS open_cnt,
                (SELECT MAX(t.clock_in) FROM time_entries t
                  WHERE t.employee_id = e.id AND t.clock_out IS NULL) AS open_since,
                CASE WHEN EXISTS (SELECT 1 FROM time_entries t
                  WHERE t.employee_id = e.id AND t.selfie_in IS NOT NULL) THEN 1 ELSE 0 END AS has_avatar
           FROM employees e WHERE e.active = 1 ORDER BY e.sort_order, e.name"
    )->fetchAll();
    // днешната смяна по график за индикация в списъка
    $st = db()->prepare(
        "SELECT s.employee_id, st.name, st.abbr, st.start_time, st.end_time
           FROM schedule s JOIN shift_types st ON st.id = s.shift_type_id
          WHERE s.day = ?"
    );
    $st->execute([date('Y-m-d')]);
    $todayShifts = [];
    foreach ($st->fetchAll() as $sh) $todayShifts[(int)$sh['employee_id']] = $sh;
    foreach ($rows as &$r) {
        $r['clocked_in'] = $r['open_cnt'] > 0;
        unset($r['open_cnt']);
        $sh = $todayShifts[(int)$r['id']] ?? null;
        $r['today_shift'] = $sh
            ? ['name' => $sh['name'], 'abbr' => $sh['abbr'], 'start' => $sh['start_time'], 'end' => $sh['end_time']]
            : null;
    }
    out($rows);

case 'my_week':
    // график на служителя за текущата седмица (публично, само активни)
    $empId = (int)($_GET['employee_id'] ?? 0);
    $st = db()->prepare("SELECT id FROM employees WHERE id = ? AND active = 1");
    $st->execute([$empId]);
    if (!$st->fetch()) fail('Няма такъв служител.');
    $monday = date('Y-m-d', strtotime('-' . ((int)date('N') - 1) . ' days'));
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    apply_default_schedules($monday, $sunday);
    $st = db()->prepare(
        "SELECT s.day, s.note, st.name, st.abbr, st.start_time AS start, st.end_time AS end
           FROM schedule s JOIN shift_types st ON st.id = s.shift_type_id
          WHERE s.employee_id = ? AND s.day >= ? AND s.day <= ?
          ORDER BY s.day"
    );
    $st->execute([$empId, $monday, $sunday]);
    out(['monday' => $monday, 'days' => $st->fetchAll()]);

case 'week_schedule':
    // публичен седмичен график на всички активни служители (страница График)
    $ref = valid_date($_GET['from'] ?? date('Y-m-d'));
    $monday = date('Y-m-d', strtotime($ref . ' -' . ((int)date('N', strtotime($ref)) - 1) . ' days'));
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    apply_default_schedules($monday, $sunday);
    $employees = db()->query(
        "SELECT e.id, e.name, e.position,
                CASE WHEN EXISTS (SELECT 1 FROM time_entries t
                  WHERE t.employee_id = e.id AND t.selfie_in IS NOT NULL) THEN 1 ELSE 0 END AS has_avatar
           FROM employees e WHERE e.active = 1 ORDER BY e.sort_order, e.name"
    )->fetchAll();
    $st = db()->prepare("SELECT employee_id, day, shift_type_id, note FROM schedule WHERE day >= ? AND day <= ?");
    $st->execute([$monday, $sunday]);
    $entries = $st->fetchAll();
    $rq = db()->prepare(
        "SELECT employee_id, day, shift_type_id FROM shift_requests
          WHERE status = 'pending' AND day >= ? AND day <= ?"
    );
    $rq->execute([$monday, $sunday]);
    out([
        'monday' => $monday,
        'employees' => $employees,
        'shift_types' => db()->query("SELECT * FROM shift_types ORDER BY start_time, name")->fetchAll(),
        'entries' => $entries,
        'requests' => $rq->fetchAll(),
    ]);

case 'shift_request':
    // заявка за промяна на смяна (публично; одобрява се от администратор)
    $empId = (int)($in['employee_id'] ?? 0);
    $day = valid_date($in['day'] ?? '');
    $sid = (int)($in['shift_type_id'] ?? 0);
    $st = db()->prepare("SELECT id FROM employees WHERE id = ? AND active = 1");
    $st->execute([$empId]);
    if (!$st->fetch()) fail('Няма такъв служител.');
    if ($sid) {
        $st = db()->prepare("SELECT id FROM shift_types WHERE id = ?");
        $st->execute([$sid]);
        if (!$st->fetch()) fail('Няма такава смяна.');
    }
    $st = db()->prepare("SELECT shift_type_id FROM schedule WHERE employee_id = ? AND day = ?");
    $st->execute([$empId, $day]);
    $cur = $st->fetch();
    if ((int)($cur['shift_type_id'] ?? 0) === $sid) fail('Това вече е смяната по график за този ден.');
    // нова заявка замества предишната чакаща за същия ден
    db()->prepare("DELETE FROM shift_requests WHERE employee_id = ? AND day = ? AND status = 'pending'")
        ->execute([$empId, $day]);
    db()->prepare("INSERT INTO shift_requests (employee_id, day, shift_type_id, status, created_at)
                   VALUES (?, ?, ?, 'pending', ?)")
        ->execute([$empId, $day, $sid, date('Y-m-d H:i:s')]);
    out(['ok' => true]);

case 'shift_request_resolve':
    require_admin();
    $id = (int)($in['id'] ?? 0);
    $approve = !empty($in['approve']);
    $st = db()->prepare("SELECT * FROM shift_requests WHERE id = ? AND status = 'pending'");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) fail('Няма такава чакаща заявка.');
    if ($approve) {
        // прилагаме заявката, като запазваме коментара от текущия график
        $st = db()->prepare("SELECT note FROM schedule WHERE employee_id = ? AND day = ?");
        $st->execute([$r['employee_id'], $r['day']]);
        $note = ($row = $st->fetch()) ? $row['note'] : '';
        db()->prepare("DELETE FROM schedule WHERE employee_id = ? AND day = ?")
            ->execute([$r['employee_id'], $r['day']]);
        if ((int)$r['shift_type_id'] || $note !== '') {
            db()->prepare("INSERT INTO schedule (employee_id, day, shift_type_id, note) VALUES (?, ?, ?, ?)")
                ->execute([$r['employee_id'], $r['day'], (int)$r['shift_type_id'], $note]);
        }
    }
    db()->prepare("UPDATE shift_requests SET status = ?, resolved_at = ? WHERE id = ?")
        ->execute([$approve ? 'approved' : 'rejected', date('Y-m-d H:i:s'), $id]);
    out(['ok' => true]);

case 'clock':
    $empId = (int)($in['employee_id'] ?? 0);
    $st = db()->prepare("SELECT id, name FROM employees WHERE id = ? AND active = 1");
    $st->execute([$empId]);
    $emp = $st->fetch();
    if (!$emp) fail('Няма такъв служител.');

    $selfie = save_selfie($in['photo'] ?? null);
    $now = date('Y-m-d H:i:s');

    $st = db()->prepare(
        "SELECT id FROM time_entries WHERE employee_id = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1"
    );
    $st->execute([$empId]);
    $open = $st->fetch();

    if ($open) {
        db()->prepare("UPDATE time_entries SET clock_out = ?, selfie_out = ? WHERE id = ?")
            ->execute([$now, $selfie, $open['id']]);
        out(['status' => 'out', 'time' => $now]);
    } else {
        if (shift_overlaps($empId, $now, null)) {
            fail('Записването е невъзможно: има друга смяна, която се застъпва с този момент.');
        }
        db()->prepare(
            "INSERT INTO time_entries (employee_id, clock_in, selfie_in, created_at) VALUES (?, ?, ?, ?)"
        )->execute([$empId, $now, $selfie, $now]);
        out(['status' => 'in', 'time' => $now]);
    }

// ---------- Администрация ----------

case 'login':
    $hash = setting('admin_password_hash');
    if ($hash && password_verify($in['password'] ?? '', $hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        out(['ok' => true]);
    }
    fail('Грешна парола.', 401);

case 'forgot_password':
    // изпраща линк за нова парола на email-а от настройките.
    // Отговорът е винаги еднакъв, за да не издава дали има зададен email.
    $email = setting('recovery_email', '');
    if ($email) {
        $token = bin2hex(random_bytes(32));
        set_setting('reset_token_hash', password_hash($token, PASSWORD_DEFAULT));
        set_setting('reset_token_expires', date('Y-m-d H:i:s', time() + 3600));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = str_replace('api.php', 'admin.php', $_SERVER['SCRIPT_NAME'] ?? '/api.php');
        $link = $scheme . '://' . $host . $path . '?reset=' . $token;
        $subject = '=?UTF-8?B?' . base64_encode('Далия — възстановяване на парола') . '?=';
        $body = "Поискано е възстановяване на администраторската парола за Работно време.\n\n"
              . "Линк за задаване на нова парола (валиден 1 час):\n" . $link . "\n\n"
              . "Ако не сте искали това, игнорирайте писмото.";
        $fromHost = preg_replace('/:\d+$/', '', $host);
        $headers = "From: no-reply@" . $fromHost . "\r\nContent-Type: text/plain; charset=UTF-8";
        @mail($email, $subject, $body, $headers);
    }
    out(['ok' => true]);

case 'reset_password':
    $token = (string)($in['token'] ?? '');
    $pass = (string)($in['password'] ?? '');
    if (strlen($pass) < 6) fail('Паролата трябва да е поне 6 символа.');
    $hash = setting('reset_token_hash');
    $exp = setting('reset_token_expires');
    if (!$hash || !$exp || $exp < date('Y-m-d H:i:s') || !password_verify($token, $hash)) {
        fail('Линкът е невалиден или е изтекъл. Заявете нов от „Забравена парола?“.');
    }
    set_setting('admin_password_hash', password_hash($pass, PASSWORD_DEFAULT));
    set_setting('reset_token_hash', '');
    set_setting('reset_token_expires', '');
    out(['ok' => true]);

case 'logout':
    require_admin();
    session_destroy();
    out(['ok' => true]);

case 'people':
    require_admin();
    out(db()->query(
        "SELECT e.*, CASE WHEN EXISTS (SELECT 1 FROM time_entries t
                WHERE t.employee_id = e.id AND t.selfie_in IS NOT NULL) THEN 1 ELSE 0 END AS has_avatar
           FROM employees e ORDER BY e.active DESC, e.sort_order, e.name"
    )->fetchAll());

case 'people_reorder':
    require_admin();
    $ids = $in['ids'] ?? [];
    if (!is_array($ids) || !$ids) fail('Невалидна подредба.');
    $st = db()->prepare("UPDATE employees SET sort_order = ? WHERE id = ?");
    $i = 0;
    foreach ($ids as $id) $st->execute([++$i * 10, (int)$id]);
    out(['ok' => true]);

case 'person_stats':
    require_admin();
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare(
        "SELECT e.id, e.name, e.position, e.rate_amount, e.rate_unit, e.active, e.created_at,
                CASE WHEN EXISTS (SELECT 1 FROM time_entries t
                  WHERE t.employee_id = e.id AND t.selfie_in IS NOT NULL) THEN 1 ELSE 0 END AS has_avatar
           FROM employees e WHERE e.id = ?"
    );
    $st->execute([$id]);
    $emp = $st->fetch();
    if (!$emp) fail('Няма такъв служител.');
    $st = db()->prepare("SELECT clock_in, clock_out FROM time_entries WHERE employee_id = ?");
    $st->execute([$id]);
    $totalSec = 0; $monthSec = 0; $shifts = 0;
    $days = []; $monthDays = [];
    $first = null; $last = null;
    $curMonth = date('Y-m');
    foreach ($st->fetchAll() as $e) {
        $shifts++;
        $day = substr($e['clock_in'], 0, 10);
        $days[$day] = true;
        $inMonth = strpos($day, $curMonth) === 0;
        if ($inMonth) $monthDays[$day] = true;
        if ($first === null || $day < $first) $first = $day;
        if ($last === null || $day > $last) $last = $day;
        if ($e['clock_out']) {
            $sec = strtotime($e['clock_out']) - strtotime($e['clock_in']);
            $totalSec += $sec;
            if ($inMonth) $monthSec += $sec;
        }
    }
    out([
        'employee' => $emp,
        'stats' => [
            'total_seconds' => $totalSec,
            'total_days' => count($days),
            'total_shifts' => $shifts,
            'month_seconds' => $monthSec,
            'month_days' => count($monthDays),
            'first_day' => $first,
            'last_day' => $last,
        ],
    ]);

case 'person_save':
    require_admin();
    $name = trim($in['name'] ?? '');
    if ($name === '') fail('Името е задължително.');
    $position = trim($in['position'] ?? '');
    $rate = (float)($in['rate_amount'] ?? 0);
    $unit = $in['rate_unit'] ?? 'hour';
    if (!in_array($unit, ['hour', 'day', 'month'], true)) fail('Невалидна ставка.');
    // смяна и работни дни по подразбиране (за автоматично попълване на графика)
    $defShift = (int)($in['default_shift_id'] ?? 0);
    if ($defShift) {
        $st = db()->prepare("SELECT id FROM shift_types WHERE id = ?");
        $st->execute([$defShift]);
        if (!$st->fetch()) fail('Няма такава смяна.');
    }
    $defDays = [];
    foreach ((array)($in['default_days'] ?? []) as $dn) {
        $dn = (int)$dn;
        if ($dn >= 1 && $dn <= 7 && !in_array($dn, $defDays, true)) $defDays[] = $dn;
    }
    sort($defDays);
    $defDaysCsv = implode(',', $defDays);
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        db()->prepare("UPDATE employees SET name=?, position=?, rate_amount=?, rate_unit=?, active=?,
                              default_shift_id=?, default_days=? WHERE id=?")
            ->execute([$name, $position, $rate, $unit, !empty($in['active']) ? 1 : 0,
                       $defShift, $defDaysCsv, $id]);
    } else {
        // новите служители отиват в края на подредбата
        $maxSort = (int)db()->query("SELECT COALESCE(MAX(sort_order), 0) AS m FROM employees")->fetch()['m'];
        db()->prepare("INSERT INTO employees (name, position, rate_amount, rate_unit, active,
                              default_shift_id, default_days, sort_order, created_at)
                       VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)")
            ->execute([$name, $position, $rate, $unit, $defShift, $defDaysCsv, $maxSort + 10, date('Y-m-d H:i:s')]);
    }
    out(['ok' => true]);

case 'person_delete':
    require_admin();
    $id = (int)($in['id'] ?? 0);
    $st = db()->prepare("SELECT COUNT(*) AS c FROM time_entries WHERE employee_id = ?");
    $st->execute([$id]);
    if ($st->fetch()['c'] > 0) {
        // има записи — само деактивираме, за да не се загуби историята
        db()->prepare("UPDATE employees SET active = 0 WHERE id = ?")->execute([$id]);
        out(['ok' => true, 'deactivated' => true]);
    }
    db()->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
    out(['ok' => true]);

case 'timesheet':
    require_admin();
    $from = valid_date($_GET['from'] ?? '');
    $to   = valid_date($_GET['to'] ?? '');
    $employees = db()->query(
        "SELECT e.id, e.name, e.position, e.rate_unit,
                CASE WHEN EXISTS (SELECT 1 FROM time_entries t
                  WHERE t.employee_id = e.id AND t.selfie_in IS NOT NULL) THEN 1 ELSE 0 END AS has_avatar
           FROM employees e WHERE e.active = 1 ORDER BY e.sort_order, e.name"
    )->fetchAll();
    $st = db()->prepare(
        "SELECT id, employee_id, clock_in, clock_out, admin_edited, auto_closed,
                CASE WHEN selfie_in  IS NULL THEN 0 ELSE 1 END AS has_selfie_in,
                CASE WHEN selfie_out IS NULL THEN 0 ELSE 1 END AS has_selfie_out
           FROM time_entries
          WHERE clock_in >= ? AND clock_in < ?
          ORDER BY clock_in"
    );
    $st->execute([$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
    out(['employees' => $employees, 'entries' => $st->fetchAll()]);

case 'entry_save':
    require_admin();
    $clockIn = valid_dt($in['clock_in'] ?? '');
    if (!$clockIn) fail('Часът на записване е задължителен.');
    $clockOut = valid_dt($in['clock_out'] ?? null);
    if ($clockOut !== null && $clockOut <= $clockIn) fail('Отписването трябва да е след записването.');
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        $st = db()->prepare("SELECT employee_id FROM time_entries WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) fail('Няма такъв запис.');
        if (shift_overlaps((int)$row['employee_id'], $clockIn, $clockOut, $id)) {
            fail('Смяната се застъпва с друга смяна на служителя.');
        }
        // ръчна корекция изчиства флага за автоматично затваряне
        db()->prepare("UPDATE time_entries SET clock_in=?, clock_out=?, admin_edited=1, auto_closed=0 WHERE id=?")
            ->execute([$clockIn, $clockOut, $id]);
    } else {
        $empId = (int)($in['employee_id'] ?? 0);
        $st = db()->prepare("SELECT id FROM employees WHERE id = ?");
        $st->execute([$empId]);
        if (!$st->fetch()) fail('Няма такъв служител.');
        if (shift_overlaps($empId, $clockIn, $clockOut)) {
            fail('Смяната се застъпва с друга смяна на служителя.');
        }
        db()->prepare("INSERT INTO time_entries (employee_id, clock_in, clock_out, admin_edited, created_at)
                       VALUES (?, ?, ?, 1, ?)")
            ->execute([$empId, $clockIn, $clockOut, date('Y-m-d H:i:s')]);
    }
    out(['ok' => true]);

case 'entry_delete':
    require_admin();
    $id = (int)($in['id'] ?? 0);
    $st = db()->prepare("SELECT selfie_in, selfie_out FROM time_entries WHERE id = ?");
    $st->execute([$id]);
    if ($row = $st->fetch()) {
        foreach (['selfie_in', 'selfie_out'] as $col) {
            if ($row[$col] && preg_match('#^[0-9]{6}/[0-9A-Za-z_]+\.jpg$#', $row[$col])) {
                @unlink(__DIR__ . '/selfies/' . $row[$col]);
            }
        }
    }
    db()->prepare("DELETE FROM time_entries WHERE id = ?")->execute([$id]);
    out(['ok' => true]);

case 'report':
    require_admin();
    $from = valid_date($_GET['from'] ?? '');
    $to   = valid_date($_GET['to'] ?? '');
    if ($to < $from) fail('Невалиден период.');
    // статус "платено" се пази само за половинмесечните периоди (1-15 / 16-край)
    $period = $_GET['period'] ?? '';
    $paidSet = [];
    if (preg_match('/^\d{4}-\d{2}-[12]$/', $period)) {
        $ps = db()->prepare("SELECT employee_id FROM payments WHERE period = ?");
        $ps->execute([$period]);
        foreach ($ps->fetchAll() as $p) $paidSet[(int)$p['employee_id']] = true;
    } else {
        $period = '';
    }
    $employees = db()->query(
        "SELECT e.id, e.name, e.position, e.rate_amount, e.rate_unit,
                CASE WHEN EXISTS (SELECT 1 FROM time_entries t
                  WHERE t.employee_id = e.id AND t.selfie_in IS NOT NULL) THEN 1 ELSE 0 END AS has_avatar
           FROM employees e WHERE e.active = 1 ORDER BY e.sort_order, e.name"
    )->fetchAll();
    $st = db()->prepare(
        "SELECT employee_id, clock_in, clock_out FROM time_entries
          WHERE clock_in >= ? AND clock_in < ?"
    );
    $st->execute([$from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
    $byEmp = [];
    foreach ($st->fetchAll() as $e) $byEmp[$e['employee_id']][] = $e;

    // за месечна ставка: сумата е пропорционална на частта от месеца, покрита от периода
    $monthFraction = function (string $from, string $to): float {
        $sum = 0.0;
        $cur = new DateTime(substr($from, 0, 7) . '-01');
        $end = new DateTime($to);
        while ($cur <= $end) {
            $mStart = $cur->format('Y-m-01');
            $mEnd = $cur->format('Y-m-t');
            $ovFrom = max($from, $mStart);
            $ovTo = min($to, $mEnd);
            $days = (new DateTime($ovFrom))->diff(new DateTime($ovTo))->days + 1;
            $sum += $days / (int)$cur->format('t');
            $cur->modify('first day of next month');
        }
        return $sum;
    };

    $rows = [];
    foreach ($employees as $emp) {
        $seconds = 0;
        $days = [];
        foreach ($byEmp[$emp['id']] ?? [] as $e) {
            $days[substr($e['clock_in'], 0, 10)] = true;
            if ($e['clock_out']) {
                $seconds += strtotime($e['clock_out']) - strtotime($e['clock_in']);
            }
        }
        $hours = $seconds / 3600;
        $daysWorked = count($days);
        switch ($emp['rate_unit']) {
            case 'hour':  $amount = $hours * $emp['rate_amount']; break;
            case 'day':   $amount = $daysWorked * $emp['rate_amount']; break;
            default:      $amount = $monthFraction($from, $to) * $emp['rate_amount'];
        }
        $rows[] = [
            'id' => $emp['id'], 'name' => $emp['name'], 'position' => $emp['position'],
            'rate_amount' => $emp['rate_amount'], 'rate_unit' => $emp['rate_unit'],
            'has_avatar' => $emp['has_avatar'],
            'hours' => round($hours, 2), 'days' => $daysWorked, 'amount' => round($amount, 2),
            'paid' => isset($paidSet[(int)$emp['id']]) ? 1 : 0,
        ];
    }
    out(['rows' => $rows, 'period' => $period]);

case 'toggle_paid':
    require_admin();
    $empId = (int)($in['employee_id'] ?? 0);
    $period = $in['period'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-[12]$/', $period)) fail('Невалиден период.');
    $st = db()->prepare("SELECT id FROM employees WHERE id = ?");
    $st->execute([$empId]);
    if (!$st->fetch()) fail('Няма такъв служител.');
    $st = db()->prepare("SELECT COUNT(*) AS c FROM payments WHERE employee_id = ? AND period = ?");
    $st->execute([$empId, $period]);
    if ($st->fetch()['c'] > 0) {
        db()->prepare("DELETE FROM payments WHERE employee_id = ? AND period = ?")->execute([$empId, $period]);
        out(['paid' => false]);
    } else {
        db()->prepare("INSERT INTO payments (employee_id, period) VALUES (?, ?)")->execute([$empId, $period]);
        out(['paid' => true]);
    }

// ---------- Графици ----------

case 'shift_types':
    require_admin();
    out(db()->query("SELECT * FROM shift_types ORDER BY start_time, name")->fetchAll());

case 'shift_type_save':
    require_admin();
    $name = trim($in['name'] ?? '');
    if ($name === '') fail('Името е задължително.');
    $start = (string)($in['start_time'] ?? '');
    $end = (string)($in['end_time'] ?? '');
    foreach ([$start, $end] as $t) {
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t)) fail('Невалиден час.');
    }
    $abbr = mb_substr(trim((string)($in['abbr'] ?? '')), 0, 10);
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        db()->prepare("UPDATE shift_types SET name=?, abbr=?, start_time=?, end_time=? WHERE id=?")
            ->execute([$name, $abbr, $start, $end, $id]);
    } else {
        db()->prepare("INSERT INTO shift_types (name, abbr, start_time, end_time) VALUES (?, ?, ?, ?)")
            ->execute([$name, $abbr, $start, $end]);
    }
    out(['ok' => true]);

case 'shift_type_delete':
    require_admin();
    $id = (int)($in['id'] ?? 0);
    db()->prepare("DELETE FROM schedule WHERE shift_type_id = ?")->execute([$id]);
    db()->prepare("UPDATE employees SET default_shift_id = 0 WHERE default_shift_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM shift_types WHERE id = ?")->execute([$id]);
    out(['ok' => true]);

case 'schedule':
    require_admin();
    $from = valid_date($_GET['from'] ?? '');
    $to   = valid_date($_GET['to'] ?? '');
    apply_default_schedules($from, $to);
    $employees = db()->query(
        "SELECT e.id, e.name, e.position,
                CASE WHEN EXISTS (SELECT 1 FROM time_entries t
                  WHERE t.employee_id = e.id AND t.selfie_in IS NOT NULL) THEN 1 ELSE 0 END AS has_avatar
           FROM employees e WHERE e.active = 1 ORDER BY e.sort_order, e.name"
    )->fetchAll();
    $st = db()->prepare("SELECT employee_id, day, shift_type_id, note FROM schedule WHERE day >= ? AND day <= ?");
    $st->execute([$from, $to]);
    // всички чакащи заявки за промяна (не само за показваната седмица)
    $requests = db()->query(
        "SELECT r.id, r.employee_id, r.day, r.shift_type_id, r.created_at,
                e.name AS employee_name,
                st.name AS requested_name,
                (SELECT st2.name FROM schedule s2
                   JOIN shift_types st2 ON st2.id = s2.shift_type_id
                  WHERE s2.employee_id = r.employee_id AND s2.day = r.day) AS current_name
           FROM shift_requests r
           JOIN employees e ON e.id = r.employee_id
           LEFT JOIN shift_types st ON st.id = r.shift_type_id
          WHERE r.status = 'pending'
          ORDER BY r.day, e.name"
    )->fetchAll();
    out([
        'employees' => $employees,
        'shift_types' => db()->query("SELECT * FROM shift_types ORDER BY start_time, name")->fetchAll(),
        'entries' => $st->fetchAll(),
        'requests' => $requests,
    ]);

case 'schedule_set':
    require_admin();
    $empId = (int)($in['employee_id'] ?? 0);
    $day = valid_date($in['day'] ?? '');
    $sid = (int)($in['shift_type_id'] ?? 0);
    $note = trim((string)($in['note'] ?? ''));
    if (mb_strlen($note) > 200) $note = mb_substr($note, 0, 200);
    $st = db()->prepare("SELECT id FROM employees WHERE id = ?");
    $st->execute([$empId]);
    if (!$st->fetch()) fail('Няма такъв служител.');
    if ($sid) {
        $st = db()->prepare("SELECT id FROM shift_types WHERE id = ?");
        $st->execute([$sid]);
        if (!$st->fetch()) fail('Няма такава смяна.');
    }
    db()->prepare("DELETE FROM schedule WHERE employee_id = ? AND day = ?")->execute([$empId, $day]);
    // ред се пази, ако има смяна ИЛИ коментар (sid=0 → само коментар, не е смяна)
    if ($sid || $note !== '') {
        db()->prepare("INSERT INTO schedule (employee_id, day, shift_type_id, note) VALUES (?, ?, ?, ?)")
            ->execute([$empId, $day, $sid, $note]);
    }
    out(['ok' => true]);

case 'get_settings':
    require_admin();
    out([
        'auto_close_enabled' => setting('auto_close_enabled', '1') === '1',
        'auto_close_time' => setting('auto_close_time', '01:30'),
        'positions' => positions_list(),
        'recovery_email' => setting('recovery_email', ''),
    ]);

case 'save_settings':
    require_admin();
    if (!empty($in['password'])) {
        if (strlen($in['password']) < 6) fail('Паролата трябва да е поне 6 символа.');
        set_setting('admin_password_hash', password_hash($in['password'], PASSWORD_DEFAULT));
    }
    if (array_key_exists('auto_close_enabled', $in)) {
        set_setting('auto_close_enabled', !empty($in['auto_close_enabled']) ? '1' : '0');
    }
    if (array_key_exists('auto_close_time', $in)) {
        $t = (string)$in['auto_close_time'];
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t)) fail('Невалиден час за автоматично затваряне.');
        set_setting('auto_close_time', $t);
    }
    if (array_key_exists('recovery_email', $in)) {
        $em = trim((string)$in['recovery_email']);
        if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) fail('Невалиден email адрес.');
        set_setting('recovery_email', $em);
    }
    if (array_key_exists('positions', $in)) {
        if (!is_array($in['positions'])) fail('Невалидни длъжности.');
        $list = [];
        foreach ($in['positions'] as $p) {
            $p = trim((string)$p);
            if ($p !== '' && mb_strlen($p) <= 50 && !in_array($p, $list, true)) $list[] = $p;
        }
        if (!$list) fail('Трябва да има поне една длъжност.');
        set_setting('positions', json_encode($list, JSON_UNESCAPED_UNICODE));
    }
    out(['ok' => true]);

// ---------- Архивиране ----------

case 'backup':
    require_admin();
    if (!class_exists('ZipArchive')) fail('ZIP не се поддържа на този сървър.');
    $data = [
        'app' => 'dalia-hours',
        'version' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'employees' => db()->query("SELECT * FROM employees")->fetchAll(),
        'time_entries' => db()->query("SELECT * FROM time_entries")->fetchAll(),
        'payments' => db()->query("SELECT * FROM payments")->fetchAll(),
        'shift_types' => db()->query("SELECT * FROM shift_types")->fetchAll(),
        'schedule' => db()->query("SELECT * FROM schedule")->fetchAll(),
        // паролата на администратора умишлено НЕ се архивира
        'settings' => [
            'auto_close_enabled' => setting('auto_close_enabled', '1'),
            'auto_close_time' => setting('auto_close_time', '01:30'),
            'positions' => positions_list(),
        ],
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'dhb');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) fail('Неуспешно създаване на архив.', 500);
    $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    foreach ($data['time_entries'] as $e) {
        foreach (['selfie_in', 'selfie_out'] as $col) {
            if (!empty($e[$col]) && preg_match('#^[0-9]{6}/[0-9A-Za-z_]+\.jpg$#', $e[$col])
                && is_file(__DIR__ . '/selfies/' . $e[$col])) {
                $zip->addFile(__DIR__ . '/selfies/' . $e[$col], 'selfies/' . $e[$col]);
            }
        }
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="dalia-hours-backup-' . date('Y-m-d_Hi') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;

case 'restore':
    require_admin();
    if (!class_exists('ZipArchive')) fail('ZIP не се поддържа на този сървър.');
    if (empty($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
        fail('Качването на файла не успя.');
    }
    $zip = new ZipArchive();
    if ($zip->open($_FILES['backup']['tmp_name']) !== true) fail('Файлът не е валиден ZIP архив.');
    $json = $zip->getFromName('data.json');
    if ($json === false) fail('Архивът не съдържа data.json.');
    $data = json_decode($json, true);
    if (!is_array($data) || ($data['app'] ?? '') !== 'dalia-hours'
        || !isset($data['employees'], $data['time_entries'])) {
        fail('Това не е валиден архив на приложението.');
    }

    db()->beginTransaction();
    try {
        db()->exec("DELETE FROM time_entries");
        db()->exec("DELETE FROM employees");
        $insEmp = db()->prepare(
            "INSERT INTO employees (id, name, position, rate_amount, rate_unit, active,
                                    default_shift_id, default_days, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $sortFallback = 0;
        foreach ($data['employees'] as $e) {
            $unit = in_array($e['rate_unit'] ?? '', ['hour', 'day', 'month'], true) ? $e['rate_unit'] : 'hour';
            $sortFallback += 10;
            $insEmp->execute([
                (int)$e['id'], (string)($e['name'] ?? ''), (string)($e['position'] ?? ''),
                (float)($e['rate_amount'] ?? 0), $unit,
                !empty($e['active']) ? 1 : 0,
                (int)($e['default_shift_id'] ?? 0), (string)($e['default_days'] ?? ''),
                (int)($e['sort_order'] ?? $sortFallback),
                $e['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }
        $insEntry = db()->prepare(
            "INSERT INTO time_entries (id, employee_id, clock_in, clock_out, selfie_in, selfie_out,
                                       admin_edited, auto_closed, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $selfieOk = function ($v) {
            return (is_string($v) && preg_match('#^[0-9]{6}/[0-9A-Za-z_]+\.jpg$#', $v)) ? $v : null;
        };
        foreach ($data['time_entries'] as $e) {
            $insEntry->execute([
                (int)$e['id'], (int)$e['employee_id'],
                (string)$e['clock_in'], $e['clock_out'] ?? null,
                $selfieOk($e['selfie_in'] ?? null), $selfieOk($e['selfie_out'] ?? null),
                !empty($e['admin_edited']) ? 1 : 0,
                !empty($e['auto_closed']) ? 1 : 0,
                $e['created_at'] ?? ($e['clock_in'] ?? date('Y-m-d H:i:s')),
            ]);
        }
        db()->exec("DELETE FROM payments");
        if (!empty($data['payments']) && is_array($data['payments'])) {
            $insPay = db()->prepare("INSERT INTO payments (employee_id, period) VALUES (?, ?)");
            foreach ($data['payments'] as $p) {
                if (preg_match('/^\d{4}-\d{2}-[12]$/', $p['period'] ?? '')) {
                    $insPay->execute([(int)$p['employee_id'], $p['period']]);
                }
            }
        }
        db()->exec("DELETE FROM schedule");
        db()->exec("DELETE FROM shift_types");
        if (!empty($data['shift_types']) && is_array($data['shift_types'])) {
            $insSt = db()->prepare("INSERT INTO shift_types (id, name, abbr, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
            foreach ($data['shift_types'] as $t) {
                $insSt->execute([(int)$t['id'], (string)($t['name'] ?? ''), (string)($t['abbr'] ?? ''),
                                 (string)($t['start_time'] ?? '00:00'), (string)($t['end_time'] ?? '00:00')]);
            }
        }
        if (!empty($data['schedule']) && is_array($data['schedule'])) {
            $insSc = db()->prepare("INSERT INTO schedule (employee_id, day, shift_type_id, note) VALUES (?, ?, ?, ?)");
            foreach ($data['schedule'] as $sc) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sc['day'] ?? '')) {
                    $insSc->execute([(int)$sc['employee_id'], $sc['day'], (int)$sc['shift_type_id'],
                                     (string)($sc['note'] ?? '')]);
                }
            }
        }
        if (!empty($data['settings']['auto_close_time'])) {
            set_setting('auto_close_time', (string)$data['settings']['auto_close_time']);
        }
        if (isset($data['settings']['auto_close_enabled'])) {
            set_setting('auto_close_enabled', $data['settings']['auto_close_enabled'] === '1' ? '1' : '0');
        }
        if (!empty($data['settings']['positions']) && is_array($data['settings']['positions'])) {
            set_setting('positions', json_encode(array_values($data['settings']['positions']), JSON_UNESCAPED_UNICODE));
        }
        db()->commit();
    } catch (Exception $ex) {
        db()->rollBack();
        fail('Възстановяването се провали: ' . $ex->getMessage(), 500);
    }

    // снимки: изтриваме старите и извличаме тези от архива (само валидни пътища)
    foreach (glob(__DIR__ . '/selfies/*/*.jpg') as $f) @unlink($f);
    $restoredPhotos = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (preg_match('#^selfies/([0-9]{6})/([0-9A-Za-z_]+\.jpg)$#', $n, $m)) {
            $dir = __DIR__ . '/selfies/' . $m[1];
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $bytes = $zip->getFromIndex($i);
            if ($bytes !== false) {
                file_put_contents($dir . '/' . $m[2], $bytes);
                $restoredPhotos++;
            }
        }
    }
    $zip->close();
    out([
        'ok' => true,
        'employees' => count($data['employees']),
        'entries' => count($data['time_entries']),
        'photos' => $restoredPhotos,
    ]);

default:
    fail('Непознато действие.', 404);
}
