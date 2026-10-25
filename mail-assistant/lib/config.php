<?php
// Пише се с проста синтаксис, за да даде ясна грешка и на стар PHP.
if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Нужен е PHP 8.0 или по-нов (сега: ' . PHP_VERSION . ") — cPanel » MultiPHP Manager.\n");
}
if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Липсва config.php — копирайте config.sample.php като config.php и попълнете данните.\n");
}
require_once __DIR__ . '/../config.php';
