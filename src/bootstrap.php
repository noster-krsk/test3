<?php
declare(strict_types=1);

require __DIR__ . '/Sync.php';

// Конфигурация берётся из переменных окружения (php-fpm env / .env вне webroot), в репозитории только дефолты.
$envFile = dirname(__DIR__, 2) . '/private/.env';
if (is_readable($envFile)) {
    foreach (parse_ini_file($envFile) ?: [] as $k => $v) {
        if (getenv($k) === false) {
            putenv("$k=$v");
        }
    }
}

return new Sync(
    getenv('SOURCE_URL') ?: 'https://test.ooobs.ru/api/orders',
    getenv('DB_PATH') ?: dirname(__DIR__, 2) . '/private/sync.sqlite',
);
