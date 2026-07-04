<?php
// „Запомни ме“ за администратора: дълготрайна бисквитка, която възстановява
// сесията, след като PHP сесията изтече. Токените се пазят хеширани в
// settings (admin_remember_tokens) — по един на устройство, до 10 устройства.
require_once __DIR__ . '/db.php';

const REMEMBER_COOKIE = 'dalia_admin_remember';
const REMEMBER_DAYS = 180;

function remember_tokens(): array {
    $raw = setting('admin_remember_tokens');
    $arr = $raw ? json_decode($raw, true) : null;
    if (!is_array($arr)) $arr = [];
    $now = date('Y-m-d H:i:s');
    return array_filter($arr, fn($t) => ($t['expires'] ?? '') > $now);
}

function remember_store(array $tokens): void {
    if (count($tokens) > 10) $tokens = array_slice($tokens, -10, null, true);
    set_setting('admin_remember_tokens', json_encode($tokens));
}

function remember_setcookie(string $value, int $expires): void {
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Издава нов токен за това устройство (при вход).
function remember_issue(): void {
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + REMEMBER_DAYS * 86400;
    $tokens = remember_tokens();
    $tokens[$selector] = [
        'hash' => hash('sha256', $validator),
        'expires' => date('Y-m-d H:i:s', $expires),
    ];
    remember_store($tokens);
    remember_setcookie($selector . ':' . $validator, $expires);
}

// Възстановява сесията от бисквитката; при успех удължава срока на токена.
function remember_try(): bool {
    if (!empty($_SESSION['admin'])) return true;
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!$raw || substr_count($raw, ':') !== 1) return false;
    [$selector, $validator] = explode(':', $raw);
    $tokens = remember_tokens();
    $t = $tokens[$selector] ?? null;
    if (!$t || !hash_equals($t['hash'], hash('sha256', $validator))) return false;
    $expires = time() + REMEMBER_DAYS * 86400;
    $tokens[$selector]['expires'] = date('Y-m-d H:i:s', $expires);
    remember_store($tokens);
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    remember_setcookie($raw, $expires);
    return true;
}

// Забравя токена на текущото устройство (при изход).
function remember_forget(): void {
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($raw && substr_count($raw, ':') === 1) {
        [$selector] = explode(':', $raw);
        $tokens = remember_tokens();
        unset($tokens[$selector]);
        remember_store($tokens);
    }
    remember_setcookie('', time() - 3600);
}
