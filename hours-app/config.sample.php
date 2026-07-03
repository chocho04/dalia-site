<?php
// Копирайте този файл като config.php и попълнете данните.

// SuperHosting (MySQL) — данните са от cPanel » MySQL Databases:
define('DB_DSN', 'mysql:host=localhost;dbname=ИМЕ_НА_БАЗА;charset=utf8mb4');
define('DB_USER', 'ПОТРЕБИТЕЛ');
define('DB_PASS', 'ПАРОЛА');

// За локална разработка може да се ползва SQLite вместо MySQL:
// define('DB_DSN', 'sqlite:' . __DIR__ . '/dev.sqlite');
// define('DB_USER', null);
// define('DB_PASS', null);
