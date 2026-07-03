<?php
require_once __DIR__ . '/config.php';

date_default_timezone_set('Europe/Sofia');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        if (strpos(DB_DSN, 'mysql:') === 0) {
            $pdo->exec("SET NAMES utf8mb4");
        }
    }
    return $pdo;
}

function setting(string $key, ?string $default = null): ?string {
    $st = db()->prepare("SELECT svalue FROM settings WHERE skey = ?");
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ? $row['svalue'] : $default;
}

function set_setting(string $key, string $value): void {
    $st = db()->prepare("SELECT COUNT(*) AS c FROM settings WHERE skey = ?");
    $st->execute([$key]);
    if ($st->fetch()['c'] > 0) {
        db()->prepare("UPDATE settings SET svalue = ? WHERE skey = ?")->execute([$value, $key]);
    } else {
        db()->prepare("INSERT INTO settings (skey, svalue) VALUES (?, ?)")->execute([$key, $value]);
    }
}
