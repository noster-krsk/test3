<?php
// php tests/run.php
declare(strict_types=1);
require __DIR__ . '/../src/Sync.php';

$fail = 0;
$eq = function ($exp, $got, string $msg) use (&$fail) {
    if ($exp !== $got) {
        $fail++;
        echo "FAIL $msg: expected " . var_export($exp, true) . ', got ' . var_export($got, true) . "\n";
    }
};

foreach ([
    '+7 999 123-45-67' => '+79991234567', '8 (999) 123 45 67' => '+79991234567',
    '79991234567' => '+79991234567', '+7-926-123-45-67' => '+79261234567',
    '9005551212' => null, '123' => null, '' => null, 'не указан' => null, '+7 999' => null,
    '19991234567' => null, '+7 999 123-45-678' => null,
] as $in => $exp) {
    $eq($exp, Sync::normalizePhone((string)$in), "phone '$in'");
}
$eq(null, Sync::normalizePhone(null), 'phone null');

$r = Sync::process([
    ['id' => 1, 'name' => 'A', 'phone' => '+7 999 123-45-67', 'email' => 'a', 'amount' => 1000, 'created_at' => '2026-10-01T10:00:00Z'],
    ['id' => 2, 'name' => 'B', 'phone' => '8 (999) 123 45 67', 'email' => '', 'amount' => 5000, 'created_at' => '2026-10-02T11:00:00Z'],
    ['id' => 3, 'name' => 'C', 'phone' => '79991234567', 'email' => 'a', 'amount' => 3000, 'created_at' => '2026-10-03T09:00:00Z'],
    ['id' => 4, 'name' => 'M1', 'phone' => '8-916-000-00-01', 'email' => 'm', 'amount' => 200, 'created_at' => '2026-10-01T12:00:00Z'],
    ['id' => 5, 'name' => 'M2', 'phone' => '+7 (916) 000 00 01', 'email' => 'm', 'amount' => 200, 'created_at' => '2026-10-05T12:00:00Z'],
    ['id' => 6, 'name' => 'X', 'phone' => '123', 'email' => '', 'amount' => 999, 'created_at' => '2026-10-01T00:00:00Z'],
]);
$eq(['B', 'M2'], array_column($r['clients'], 'name'), 'dedup winners');
$eq(['received' => 6, 'unique' => 2, 'duplicates_merged' => 3, 'invalid_dropped' => 1], $r['stats'], 'stats');
$eq(5000, $r['clients'][0]['amount'], 'amount stays int');

// идемпотентность хранилища
$db = tempnam(sys_get_temp_dir(), 'sync');
$sync = new Sync('', $db);
$orders = [
    ['id' => 1, 'name' => 'A', 'phone' => '89991234567', 'email' => '', 'amount' => 1, 'created_at' => '2026-10-01T00:00:00Z'],
    ['id' => 2, 'name' => 'B', 'phone' => '+79991234567', 'email' => '', 'amount' => 2, 'created_at' => '2026-10-01T00:00:00Z'],
];
$first = $sync->run($orders);
$sync->run($orders);
$sync->run($orders);
$eq($first, $sync->stored(), 'repeated sync gives same stored result');
$eq(1, count($sync->stored()['clients']), 'no duplicates after repeated sync');
echo $fail ? "$fail failed\n" : "all ok\n";
@unlink($db);
exit($fail ? 1 : 0);
