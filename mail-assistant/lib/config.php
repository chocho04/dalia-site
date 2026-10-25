<?php
if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Липсва config.php — копирайте config.sample.php като config.php и попълнете данните.\n");
}
require_once __DIR__ . '/../config.php';
