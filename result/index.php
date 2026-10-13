<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

/** @var Sync $sync */
$sync = require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $result = $sync->run();
} catch (Throwable $e) {
    error_log('sync failed: ' . $e->getMessage());
    $result = $sync->stored();
    if ($result === null) {
        http_response_code(502);
        echo json_encode(['error' => 'source unavailable and no stored result']);
        exit;
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
