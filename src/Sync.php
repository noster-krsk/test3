<?php
declare(strict_types=1);

/**
 * Мини-синхронизатор заявок: загрузка -> нормализация телефонов -> дедупликация -> хранение.
 */
final class Sync
{
    public function __construct(private string $sourceUrl, private string $dbPath)
    {
    }

    /** Приводит телефон к +7XXXXXXXXXX; null — если номер не сводится к 11 цифрам (7/8 + 10 цифр или 9 + 9 цифр). */
    public static function normalizePhone(mixed $phone): ?string
    {
        if (!is_string($phone) && !is_int($phone)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string)$phone);
        // 10 цифр с 9 в начале — мобильный без кода страны, дописываем 7
        if (strlen($digits) === 10 && $digits[0] === '9') {
            $digits = '7' . $digits;
        }
        if (strlen($digits) !== 11 || ($digits[0] !== '7' && $digits[0] !== '8')) {
            return null;
        }
        return '+7' . substr($digits, 1);
    }

    /**
     * Чистая функция: сырые заявки -> клиенты + статистика.
     * При дубле побеждает больший amount, при равенстве — более поздний created_at.
     */
    public static function process(array $orders): array
    {
        $best = [];
        $valid = 0;
        $invalid = 0;
        foreach ($orders as $pos => $o) {
            $phone = is_array($o) ? self::normalizePhone($o['phone'] ?? null) : null;
            if ($phone === null) {
                $invalid++;
                continue;
            }
            $valid++;
            $rec = [
                'name'   => (string)($o['name'] ?? ''),
                'phone'  => $phone,
                'email'  => (string)($o['email'] ?? ''),
                'amount' => is_numeric($o['amount'] ?? null) ? $o['amount'] + 0 : 0,
                'ts'     => strtotime((string)($o['created_at'] ?? '')) ?: 0,
                'id'     => is_numeric($o['id'] ?? null) ? (int)$o['id'] : 0,
                'pos'    => $pos,
            ];
            $cur = $best[$phone] ?? null;
            if ($cur === null) {
                $best[$phone] = $rec;
                continue;
            }
            // первая позиция клиента в выдаче сохраняется, чтобы порядок был стабильным
            $rec['pos'] = $cur['pos'];
            if ([$rec['amount'], $rec['ts'], $rec['id']] > [$cur['amount'], $cur['ts'], $cur['id']]) {
                $best[$phone] = $rec;
            }
        }

        usort($best, fn($a, $b) => $a['pos'] <=> $b['pos']);
        $clients = array_map(fn($r) => [
            'name'   => $r['name'],
            'phone'  => $r['phone'],
            'email'  => $r['email'],
            'amount' => $r['amount'],
        ], $best);

        return [
            'clients' => $clients,
            'stats'   => [
                'received'          => count($orders),
                'unique'            => count($clients),
                'duplicates_merged' => $valid - count($clients),
                'invalid_dropped'   => $invalid,
            ],
        ];
    }

    /** Забирает заявки из источника. */
    public function fetch(): array
    {
        $ch = curl_init($this->sourceUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code !== 200) {
            throw new RuntimeException("source unavailable: HTTP $code $err");
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !array_is_list($data)) {
            throw new RuntimeException('source returned non-array JSON');
        }
        return $data;
    }

    /**
     * Синхронизация: снимок источника целиком заменяет хранимый в одной транзакции.
     * phone — PRIMARY KEY, поэтому повторные запуски не могут породить дубли.
     */
    public function run(?array $orders = null): array
    {
        $result = self::process($orders ?? $this->fetch());
        $db = $this->db();
        $db->beginTransaction();
        $db->exec('DELETE FROM clients');
        $ins = $db->prepare('INSERT INTO clients (phone, name, email, amount, pos) VALUES (?, ?, ?, ?, ?)');
        foreach ($result['clients'] as $i => $c) {
            $ins->execute([$c['phone'], $c['name'], $c['email'], $c['amount'], $i]);
        }
        $db->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')
            ->execute(['stats', json_encode($result['stats'])]);
        $db->commit();
        return $result;
    }

    /** Последний сохранённый результат (если источник недоступен). */
    public function stored(): ?array
    {
        $db = $this->db();
        $stats = $db->query("SELECT v FROM meta WHERE k = 'stats'")->fetchColumn();
        if ($stats === false) {
            return null;
        }
        $clients = [];
        foreach ($db->query('SELECT name, phone, email, amount FROM clients ORDER BY pos') as $r) {
            $r['amount'] += 0;
            $clients[] = $r;
        }
        return ['clients' => $clients, 'stats' => json_decode($stats, true)];
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite:' . $this->dbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('CREATE TABLE IF NOT EXISTS clients (
            phone TEXT PRIMARY KEY, name TEXT, email TEXT, amount NUMERIC, pos INTEGER)');
        $db->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)');
        return $db;
    }
}
