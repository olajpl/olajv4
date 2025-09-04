<?php
// includes/settings.php — V4 robust: auto-detekcja tabeli/kolumn dla ustawień
declare(strict_types=1);

// upewnij się, że mamy $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/db.php';
}

/**
 * Auto-detekcja magazynu ustawień.
 * Obsługiwane warianty:
 *  - owner_settings(owner_id, key|name|setting_key, value|setting_value)
 *  - settings(owner_id, key|name|setting_key, value|setting_value)
 */
function __olaj_detect_settings_storage(PDO $pdo): array
{
    static $det = null;
    if ($det !== null) return $det;

    $candidates = [
        'owner_settings',
        'settings',
    ];

    $found = null;
    $cols  = [];
    foreach ($candidates as $tbl) {
        try {
            $st = $pdo->query("SHOW COLUMNS FROM `{$tbl}`");
            $cols = $st ? $st->fetchAll(PDO::FETCH_COLUMN) : [];
            if ($cols) {
                $found = $tbl;
                break;
            }
        } catch (Throwable $e) {
            // próbuj dalej
        }
    }

    if ($found === null) {
        // twardy fallback – brak tabeli: zwróć syntetyczne mapowanie, a funkcje będą zwracać defaulty
        $det = [
            'table'     => null,
            'owner'     => 'owner_id',
            'key'       => 'key',
            'value'     => 'value',
            'has_unique' => false,
        ];
        return $det;
    }

    $norm = array_map('strtolower', $cols);

    // mapowanie kolumn
    $ownerCol = in_array('owner_id', $norm, true) ? 'owner_id' : (in_array('user_id', $norm, true) ? 'user_id' : 'owner_id');

    $keyCol = 'key';
    foreach (['setting_key', 'key', 'name'] as $k) {
        if (in_array($k, $norm, true)) {
            $keyCol = $k;
            break;
        }
    }

    $valCol = 'value';
    foreach (['setting_value', 'value', 'val'] as $v) {
        if (in_array($v, $norm, true)) {
            $valCol = $v;
            break;
        }
    }

    // czy mamy unikalny indeks (owner_id + key)?
    $hasUnique = false;
    try {
        $idx = $pdo->query("SHOW INDEX FROM `{$found}`")->fetchAll(PDO::FETCH_ASSOC);
        $composite = [];
        foreach ($idx as $r) {
            $name = (string)$r['Key_name'];
            $col  = strtolower((string)$r['Column_name']);
            $unique = (int)$r['Non_unique'] === 0;
            if ($unique) {
                $composite[$name][] = $col;
            }
        }
        foreach ($composite as $colsArr) {
            sort($colsArr);
            if (in_array($ownerCol, $colsArr, true) && in_array($keyCol, $colsArr, true)) {
                $hasUnique = true;
                break;
            }
        }
    } catch (Throwable $e) {
        // brak uprawnień do SHOW INDEX? — trudno, polecimy bez
    }

    $det = [
        'table'      => $found,
        'owner'      => $ownerCol,
        'key'        => $keyCol,
        'value'      => $valCol,
        'has_unique' => $hasUnique,
    ];
    return $det;
}

function get_setting(int $owner_id, string $key, $default = '')
{
    global $pdo;
    $m = __olaj_detect_settings_storage($pdo);
    if ($m['table'] === null) return $default;

    $sql = "SELECT " . q($m['value']) . " AS v
          FROM " . q($m['table']) . "
          WHERE " . q($m['owner']) . " = :oid
            AND " . q($m['key'])   . " = :k
          LIMIT 1";
    try {
        $st = $pdo->prepare($sql);
        $st->execute(['oid' => $owner_id, 'k' => $key]);
        $v = $st->fetchColumn();
        return ($v !== false) ? $v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function get_settings_bulk(int $owner_id, array $keys): array
{
    global $pdo;
    $out = array_fill_keys($keys, null);
    if (!$keys) return $out;

    $m = __olaj_detect_settings_storage($pdo);
    if ($m['table'] === null) return $out;

    $ph = implode(',', array_fill(0, count($keys), '?'));
    $sql = "SELECT " . q($m['key']) . " AS k, " . q($m['value']) . " AS v
          FROM " . q($m['table']) . "
          WHERE " . q($m['owner']) . " = ?
            AND " . q($m['key'])   . " IN ($ph)";
    try {
        $params = array_merge([$owner_id], array_values($keys));
        $st = $pdo->prepare($sql);
        $st->execute($params);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $out[(string)$r['k']] = $r['v'];
        }
    } catch (Throwable $e) {
        // zignoruj
    }
    return $out;
}

function set_setting(int $owner_id, string $key, $value): bool
{
    global $pdo;
    $m = __olaj_detect_settings_storage($pdo);
    if ($m['table'] === null) return false;

    // 1) Spróbuj UPDATE
    $sqlU = "UPDATE " . q($m['table']) . "
           SET " . q($m['value']) . " = :v
           WHERE " . q($m['owner']) . " = :oid
             AND " . q($m['key'])   . " = :k";
    try {
        $st = $pdo->prepare($sqlU);
        $st->execute(['v' => (string)$value, 'oid' => $owner_id, 'k' => $key]);
        if ($st->rowCount() > 0) return true;
    } catch (Throwable $e) {
        // polecimy insert-em
    }

    // 2) INSERT (bez zgadywania ON DUPLICATE — nie każdy ma unikalny indeks)
    $sqlI = "INSERT INTO " . q($m['table']) . " (" . q($m['owner']) . "," . q($m['key']) . "," . q($m['value']) . ")
           VALUES (:oid,:k,:v)";
    try {
        $st = $pdo->prepare($sqlI);
        $st->execute(['oid' => $owner_id, 'k' => $key, 'v' => (string)$value]);
        return true;
    } catch (Throwable $e) {
        // 3) Gdy istnieje unikalny indeks i trafimy w duplicate, zrób ponownie UPDATE
        if ($m['has_unique']) {
            try {
                $st = $pdo->prepare($sqlU);
                $st->execute(['v' => (string)$value, 'oid' => $owner_id, 'k' => $key]);
                return $st->rowCount() >= 0;
            } catch (Throwable $e2) {
                return false;
            }
        }
        return false;
    }
}

// prościutko quoting identyfikatorów
function q(string $name): string
{
    return '`' . str_replace('`', '', $name) . '`';
}
