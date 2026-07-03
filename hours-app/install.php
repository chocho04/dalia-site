<?php
// Инсталация: създава таблиците и задава администраторска парола.
// След успешна инсталация изтрийте този файл от сървъра!
require_once __DIR__ . '/db.php';

$driver = db()->getAttribute(PDO::ATTR_DRIVER_NAME); // 'mysql' или 'sqlite'

function table_exists(string $name): bool {
    global $driver;
    try {
        db()->query("SELECT 1 FROM $name LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$installed = table_exists('settings') && setting('admin_password_hash') !== null;
$message = '';

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = $_POST['password'] ?? '';
    if (strlen($pass) < 6) {
        $message = 'Паролата трябва да е поне 6 символа.';
    } else {
        $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $charset = $driver === 'mysql' ? ' DEFAULT CHARSET=utf8mb4' : '';

        db()->exec("CREATE TABLE IF NOT EXISTS employees (
            id $id,
            name VARCHAR(100) NOT NULL,
            position VARCHAR(100) NOT NULL DEFAULT '',
            rate_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            rate_unit VARCHAR(10) NOT NULL DEFAULT 'hour',
            active TINYINT NOT NULL DEFAULT 1,
            default_shift_id INT NOT NULL DEFAULT 0,
            default_days VARCHAR(20) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        )$charset");

        db()->exec("CREATE TABLE IF NOT EXISTS time_entries (
            id $id,
            employee_id INT NOT NULL,
            clock_in DATETIME NOT NULL,
            clock_out DATETIME NULL,
            selfie_in VARCHAR(100) NULL,
            selfie_out VARCHAR(100) NULL,
            admin_edited TINYINT NOT NULL DEFAULT 0,
            auto_closed TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        )$charset");

        db()->exec("CREATE TABLE IF NOT EXISTS settings (
            skey VARCHAR(50) PRIMARY KEY,
            svalue TEXT NOT NULL
        )$charset");

        db()->exec("CREATE TABLE IF NOT EXISTS payments (
            employee_id INT NOT NULL,
            period VARCHAR(12) NOT NULL,
            PRIMARY KEY (employee_id, period)
        )$charset");

        db()->exec("CREATE TABLE IF NOT EXISTS shift_types (
            id $id,
            name VARCHAR(100) NOT NULL,
            abbr VARCHAR(10) NOT NULL DEFAULT '',
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

        db()->exec("CREATE TABLE IF NOT EXISTS shift_requests (
            id $id,
            employee_id INT NOT NULL,
            day DATE NOT NULL,
            shift_type_id INT NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            resolved_at DATETIME NULL
        )$charset");

        set_setting('admin_password_hash', password_hash($pass, PASSWORD_DEFAULT));
        set_setting('auto_close_time', '01:30');
        set_setting('schema_auto_closed', '1');
        set_setting('schema_payments', '1');
        set_setting('schema_schedules', '1');
        set_setting('schema_schedule_note', '1');
        set_setting('schema_shift_requests', '1');
        set_setting('schema_emp_defaults', '1');
        set_setting('schema_emp_sort', '1');
        set_setting('schema_shift_abbr', '1');
        $installed = true;
    }
}
?>
<!DOCTYPE html>
<html lang="bg">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Инсталация — Далия Работно време</title>
<style>
body{font-family:system-ui,sans-serif;max-width:480px;margin:40px auto;padding:0 16px;color:#222}
input,button{font-size:16px;padding:10px;width:100%;box-sizing:border-box;margin-top:8px}
button{background:#2e7d32;color:#fff;border:0;border-radius:6px;cursor:pointer}
.err{color:#c62828}.ok{color:#2e7d32}
</style>
</head>
<body>
<h1>Далия — Работно време</h1>
<?php if ($installed): ?>
    <p class="ok">✔ Инсталацията е завършена.</p>
    <p><strong>Изтрийте файла <code>install.php</code> от сървъра!</strong></p>
    <p><a href="index.php">Приложение за служители</a> · <a href="admin.php">Администрация</a></p>
<?php else: ?>
    <p>Създаване на базата данни и задаване на администраторска парола.</p>
    <?php if ($message): ?><p class="err"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <form method="post">
        <label>Администраторска парола:
            <input type="password" name="password" minlength="6" required>
        </label>
        <button type="submit">Инсталирай</button>
    </form>
<?php endif; ?>
</body>
</html>
