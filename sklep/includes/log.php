<?php
// includes/log.php — HOTFIX zgodny z tabelą `logs` (NULL-safe bindy, walidacje)

if (!function_exists('uuidv4')) {
    function uuidv4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
// Spróbuj dociągnąć klasę, jeśli ktoś jej użyje wcześniej
$__logEnginePath = __DIR__ . '/../engine/Log/LogEngine.php';
if (!class_exists('\\Engine\\Log\\LogEngine') && is_file($__logEnginePath)) {
    require_once $__logEnginePath;
}
unset($__logEnginePath);
if (!function_exists('logg')) {
    /**
     * Minimalny, pewny zapis do `logs` (NULL-safe bind, ENUM-safe)
     */
    function logg(
        string $level,
        string $channel,
        ?string $event = null,
        array $ctx = [],
        array $meta = []
    ): void {
        /** @var PDO|null $pdo */
        global $pdo;
        if (!($pdo instanceof PDO)) {
            error_log('logg: no PDO');
            return;
        }

        // ——— helpers ———
        $bindNullableInt = static function (PDOStatement $st, string $name, $val): void {
            if ($val === null) $st->bindValue($name, null, PDO::PARAM_NULL);
            else $st->bindValue($name, (int)$val, PDO::PARAM_INT);
        };

        // ——— mapowanie meta → kolumny ———
        $ownerId  = isset($meta['owner_id']) ? (int)$meta['owner_id'] : null;
        $userId   = isset($meta['user_id']) ? (int)$meta['user_id'] : null;
        $clientId = isset($meta['client_id']) ? (int)$meta['client_id'] : null;
        $orderId  = isset($meta['order_id']) ? (int)$meta['order_id'] : null;
        $groupId  = isset($meta['order_group_id']) ? (int)$meta['order_group_id'] : null;
        $liveId   = isset($meta['live_id']) ? (int)$meta['live_id'] : null;

        $context  = (string)($meta['context'] ?? 'general'); // NOT NULL default
        $channel  = $channel !== '' ? $channel : 'general';

        // ENUM level / source (ułóż pod Twoją definicję w DB)
        $allowedLevels  = ['debug', 'info', 'warning', 'error', 'critical'];
        $allowedSources = ['panel', 'shop', 'webhook', 'cron', 'cli', 'api'];
        $level = in_array($level, $allowedLevels, true) ? $level : 'info';

        $src = (string)($meta['source'] ?? '');
        if (!in_array($src, $allowedSources, true)) {
            $src = PHP_SAPI === 'cli' ? 'cli'
                : ((isset($_SERVER['REQUEST_URI']) && str_contains((string)$_SERVER['REQUEST_URI'], '/api/')) ? 'api' : 'panel');
        }

        // IP → VARBINARY(16)
        $ipStr = $meta['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $ipBin = $ipStr ? @inet_pton($ipStr) : null;

        // UA limit 255
        $ua = $meta['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
        if (is_string($ua) && strlen($ua) > 255) $ua = substr($ua, 0, 255);

        // message VARCHAR(1024)
        $message = $ctx['message'] ?? ($meta['message'] ?? '');
        if (!$message) $message = $event ?? 'event';
        if (strlen($message) > 1024) $message = substr($message, 0, 1024);

        // context_json (walidowalny JSON lub NULL)
        $contextJson = !empty($ctx) ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        // trace (opcjonalnie)
        $trace = null;
        if (!empty($meta['trace'])) {
            $trace = is_string($meta['trace']) ? $meta['trace'] : json_encode($meta['trace'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // request_id char(36) — UUIDv4 (chyba że podano)
        $requestId = $meta['request_id'] ?? uuidv4();

        // msg_hash (sha256) — pod deduplikację
        $msgHash = hash('sha256', implode('|', [
            (string)$ownerId,
            $channel,
            (string)$event,
            $message,
            (string)$requestId
        ]));

        try {
            $sql = "INSERT INTO logs
                (owner_id,user_id,client_id,order_id,context,order_group_id,live_id,
                 level,channel,event,message,context_json,trace,request_id,source,ip,user_agent,flags,created_at,msg_hash)
                VALUES
                (:owner_id,:user_id,:client_id,:order_id,:context,:order_group_id,:live_id,
                 :level,:channel,:event,:message,:context_json,:trace,:request_id,:source,:ip,:user_agent,:flags,NOW(),:msg_hash)";
            $st = $pdo->prepare($sql);

            // NULL-safe bindy
            $bindNullableInt($st, ':owner_id',      $ownerId);
            $bindNullableInt($st, ':user_id',       $userId);
            $bindNullableInt($st, ':client_id',     $clientId);
            $bindNullableInt($st, ':order_id',      $orderId);
            $st->bindValue(':context', $context, PDO::PARAM_STR);
            $bindNullableInt($st, ':order_group_id', $groupId);
            $bindNullableInt($st, ':live_id',       $liveId);

            $st->bindValue(':level',   $level,   PDO::PARAM_STR);
            $st->bindValue(':channel', $channel, PDO::PARAM_STR);
            if ($event === null) $st->bindValue(':event', null, PDO::PARAM_NULL);
            else                 $st->bindValue(':event', $event, PDO::PARAM_STR);

            $st->bindValue(':message', $message, PDO::PARAM_STR);
            if ($contextJson === null) $st->bindValue(':context_json', null, PDO::PARAM_NULL);
            else                       $st->bindValue(':context_json', $contextJson, PDO::PARAM_STR);

            if ($trace === null) $st->bindValue(':trace', null, PDO::PARAM_NULL);
            else                 $st->bindValue(':trace', $trace, PDO::PARAM_STR);

            $st->bindValue(':request_id', $requestId, PDO::PARAM_STR);
            $st->bindValue(':source',     $src, PDO::PARAM_STR);

            // VARBINARY(16) — surowy binarny string (lub NULL)
            if ($ipBin !== null) $st->bindValue(':ip', $ipBin, PDO::PARAM_STR);
            else                 $st->bindValue(':ip', null, PDO::PARAM_NULL);

            if ($ua === null) $st->bindValue(':user_agent', null, PDO::PARAM_NULL);
            else              $st->bindValue(':user_agent', $ua, PDO::PARAM_STR);

            if (!empty($meta['flags'])) $st->bindValue(':flags', (string)$meta['flags'], PDO::PARAM_STR);
            else                        $st->bindValue(':flags', null, PDO::PARAM_NULL);

            $st->bindValue(':msg_hash', $msgHash, PDO::PARAM_STR);

            $st->execute();
        } catch (\Throwable $e) {
            error_log('[logg fail] ' . $e->getMessage());
            error_log("[$level][$channel][" . ($event ?? '') . "] " . $message);
        }
    }
}

if (!function_exists('wlog')) {
    function wlog(string $msg): void
    {
        logg('warning', 'stderr', 'wlog', ['message' => $msg], ['context' => 'stderr']);
    }
}
