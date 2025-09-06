<?php
// includes/db_guard.php — DB wrapper z bogatym logowaniem (Olaj V4)
declare(strict_types=1);

use Engine\Log\LogEngine;


/**
 * Główna funkcja: prepare+execute z logowaniem błędów.
 *
 * @param PDO    $pdo
 * @param string $sql
 * @param array  $params assoc lub numeryczna tablica parametrów
 * @param array  $ctx    np. ['owner_id'=>1, 'channel'=>'payments.tx', 'event'=>'tx_list.load']
 * @return PDOStatement
 * @throws PDOException dalej w górę po zalogowaniu
 */
function db_exec_logged(PDO $pdo, string $sql, array $params = [], array $ctx = []): PDOStatement
{
    // wymuś dobre atrybuty (jeśli nie wymuszone globalnie)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st;
    } catch (PDOException $e) {
        $info     = $e->errorInfo ?? [null, null, null];
        $sqlState = $info[0] ?? ($e->getCode() ?: null);
        $drvCode  = $info[1] ?? null;
        $drvMsg   = $info[2] ?? $e->getMessage();

        $hints = [];
        // 42S02 — missing table
        if ($sqlState === '42S02' && preg_match("/Table .*?'([^']+)'/i", (string)$drvMsg, $m)) {
            $hints['missing_table'] = $m[1];
        }
        // 42S22 — missing column
        if ($sqlState === '42S22' && preg_match("/Unknown column '([^']+)'/i", (string)$drvMsg, $m)) {
            $hints['missing_column'] = $m[1];
        }
        // HY093 — param mismatch
        if ($sqlState === 'HY093') {
            $hints['param_mismatch'] = true;
            $hints['param_keys'] = array_keys($params);
            $hints['positional_count'] = preg_match_all('/\?/', $sql) ?: 0;
            $hints['named_count'] = preg_match_all('/:\w+/', $sql) ?: 0;
        }

        $context = [
            'sql'        => $sql,
            'params'     => db_mask_params($params),
            'sqlstate'   => $sqlState,
            'driverCode' => $drvCode,
            'driverMsg'  => db_clip((string)$drvMsg, 4000),
        ] + $hints + $ctx;

        // spróbujmy złapać bazę i wersję
        try {
            $context['database'] = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $context['server_version'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable $__) {}

        // kanał/event z fallbackiem
        $channel = (string)($ctx['channel'] ?? 'db.sql');
        $event   = (string)($ctx['event']   ?? 'query');

        LogEngine::write($pdo, 'error', $channel, $event, $context);

        throw $e; // przekaż dalej (UI/API zareaguje)
    }
}

/**
 * Syntactic sugar: query-bez-paramów (SELECT ... bez bindów).
 */
function db_query_logged(PDO $pdo, string $sql, array $ctx = []): PDOStatement
{
    return db_exec_logged($pdo, $sql, [], $ctx + ['event' => $ctx['event'] ?? 'query.raw']);
}

/**
 * Syntactic sugar: pobierz jedną linię (lub null) z SELECT … LIMIT 1.
 */
function db_fetch_logged(PDO $pdo, string $sql, array $params = [], array $ctx = []): ?array
{
    $st = db_exec_logged($pdo, $sql, $params, $ctx + ['event' => $ctx['event'] ?? 'query.fetch']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

/**
 * Szybki audyt schematu: sprawdź, czy tabela/kolumna istnieje i zaloguj warning, jeśli nie.
 * Użyteczne w miejscach przejściowych migracji.
 */
function db_assert_table_has_columns(PDO $pdo, string $table, array $columns, array $ctx = []): void
{
    try {
        $cols = [];
        $res = db_query_logged($pdo, "SHOW COLUMNS FROM `{$table}`", $ctx + ['event' => 'schema.show_columns']);
        while ($r = $res->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $r['Field'];
        }
        $missing = array_values(array_diff($columns, $cols));
        if ($missing) {
            LogEngine::write($pdo, 'warning', 'db.schema', "schema.missing_columns:{$table}", $ctx + [
                'table' => $table,
                'missing' => $missing,
                'present' => $cols,
            ]);
        }
    } catch (Throwable $e) {
        LogEngine::write($pdo, 'error', 'db.schema', "schema.check_failed:{$table}", $ctx + [
            'table' => $table,
            'error' => $e->getMessage(),
        ]);
    }
}

/** Zmaskuj potencjalne sekrety w parametrach. */
function db_mask_params(array $params): array
{
    $masked = [];
    foreach ($params as $k => $v) {
        $key = is_int($k) ? (string)$k : (string)$k;
        if (preg_match('/pass|token|secret|key/i', $key)) {
            $masked[$k] = '***';
        } else {
            // cast scalars for logging; zostaw typy proste
            $masked[$k] = is_scalar($v) || $v === null ? $v : (is_array($v) ? '[array]' : (is_object($v) ? '[object]' : (string)$v));
        }
    }
    return $masked;
}

/** Utnij długie stringi, żeby nie blokować INSERT-a do logs. */
function db_clip(?string $s, int $limit): ?string
{
    if ($s === null) return null;
    if (mb_strlen($s, 'UTF-8') <= $limit) return $s;
    return mb_substr($s, 0, max(0, $limit - 1), 'UTF-8') . '…';
}
