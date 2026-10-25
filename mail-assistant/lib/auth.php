<?php
// Вход с паролата от config.php. Сесията е подписана бисквитка (без сървърно
// състояние), валидна 180 дни; смяната на паролата я анулира на всички устройства.
require_once __DIR__ . '/config.php';

const AUTH_COOKIE = 'dalia_mail_auth';
const AUTH_DAYS = 180;

function auth_key(): string {
    return hash('sha256', 'dalia-mail-assistant|' . APP_PASSWORD . '|' . IMAP_USER);
}

function auth_configured(): bool {
    return strlen(APP_PASSWORD) >= 6;
}

function auth_ok(): bool {
    if (!auth_configured()) return false;
    $raw = $_COOKIE[AUTH_COOKIE] ?? '';
    if (!is_string($raw) || substr_count($raw, '.') !== 1) return false;
    [$expires, $sig] = explode('.', $raw);
    return ctype_digit($expires) && (int)$expires > time()
        && hash_equals(hash_hmac('sha256', $expires, auth_key()), $sig);
}

function auth_setcookie(string $value, int $expires): void {
    setcookie(AUTH_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function auth_login(string $password): bool {
    if (!auth_configured() || !hash_equals(APP_PASSWORD, $password)) {
        sleep(1); // забавя налучкването
        return false;
    }
    $expires = time() + AUTH_DAYS * 86400;
    auth_setcookie($expires . '.' . hash_hmac('sha256', (string)$expires, auth_key()), $expires);
    return true;
}

function auth_logout(): void {
    auth_setcookie('', time() - 3600);
}
