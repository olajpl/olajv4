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

        // --- helpers ---
        $getInt = static function (array $a, string $snake, string $camel): ?int {
            $v = $a[$snake] ?? $a[$camel] ?? null;
            if ($v === null || $v === '' || !is_numeric($v)) return null;
            $i = (int)$v;
            return $i > 0 ? $i : null;
        };
        $bindNullableInt = static function (\PDOStatement $st, string $name, $val): void {
            if ($val === null) $st->bindValue($name, null, PDO::PARAM_NULL);
            else               $st->bindValue($name, (int)$val, PDO::PARAM_INT);
        };

        // --- ID-ki: preferuj ctx → potem meta → potem środowisko ---
        $ownerId  = $getInt($ctx,  'owner_id', 'ownerId')   ?? $getInt($meta, 'owner_id', 'ownerId');
        $userId   = $getInt($ctx,  'user_id', 'userId')     ?? $getInt($meta, 'user_id', 'userId');
        $clientId = $getInt($ctx,  'client_id', 'clientId') ?? $getInt($meta, 'client_id', 'clientId');
        $orderId  = $getInt($ctx,  'order_id', 'orderId')   ?? $getInt($meta, 'order_id', 'orderId');
        $groupId  = $getInt($ctx,  'order_group_id', 'orderGroupId') ?? $getInt($meta, 'order_group_id', 'orderGroupId');
        $liveId   = $getInt($ctx,  'live_id', 'liveId')     ?? $getInt($meta, 'live_id', 'liveId');

        if ($ownerId === null) {
            if (isset($GLOBALS['__olaj_owner_id']))         $ownerId = (int)$GLOBALS['__olaj_owner_id'];
            elseif (isset($_SESSION['user']['owner_id']))   $ownerId = (int)$_SESSION['user']['owner_id'];
            elseif (defined('APP_OWNER_ID'))                $ownerId = (int)APP_OWNER_ID;
        }
        if ($ownerId === 0) $ownerId = null; // 0 → NULL

        // --- pozostałe meta / pola kontrolne ---
        $context  = (string)($meta['context'] ?? 'general'); // kolumna VARCHAR
        $channel  = $channel !== '' ? $channel : 'general';

        $allowedLevels  = ['debug', 'info', 'warning', 'error', 'critical'];
        $allowedSources = ['panel', 'shop', 'webhook', 'cron', 'cli', 'api'];
        $level = in_array($level, $allowedLevels, true) ? $level : 'info';

        $src = (string)($meta['source'] ?? '');
        if (!in_array($src, $allowedSources, true)) {
            $src = PHP_SAPI === 'cli' ? 'cli'
                : ((isset($_SERVER['REQUEST_URI']) && str_contains((string)$_SERVER['REQUEST_URI'], '/api/')) ? 'api' : 'panel');
        }

        // IP → VARBINARY(16) albo NULL
        $ipStr = $meta['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $ipBin = $ipStr ? @inet_pton($ipStr) : null;

        // UA (<=255)
        $ua = $meta['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
        if (is_string($ua) && strlen($ua) > 255) $ua = substr($ua, 0, 255);

        // message (<=1024)
        $message = $ctx['message'] ?? ($meta['message'] ?? ($event ?? 'event'));
        if (strlen($message) > 1024) $message = substr($message, 0, 1024);

        // context_json
        $contextJson = !empty($ctx) ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        // trace
        $trace = null;
        if (!empty($meta['trace'])) {
            $trace = is_string($meta['trace']) ? $meta['trace'] : json_encode($meta['trace'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // request_id (z nagłówka/globali lub uuid)
        $requestId = $meta['request_id'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? ($GLOBALS['__olaj_request_id'] ?? uuidv4()));

        // msg_hash → deduplikacja
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

            // bindy
            $bindNullableInt($st, ':owner_id',       $ownerId);
            $bindNullableInt($st, ':user_id',        $userId);
            $bindNullableInt($st, ':client_id',      $clientId);
            $bindNullableInt($st, ':order_id',       $orderId);
            $st->bindValue(':context', $context, PDO::PARAM_STR);
            $bindNullableInt($st, ':order_group_id', $groupId);
            $bindNullableInt($st, ':live_id',        $liveId);

            $st->bindValue(':level',   $level,   PDO::PARAM_STR);
            $st->bindValue(':channel', $channel, PDO::PARAM_STR);
            $event === null
                ? $st->bindValue(':event', null, PDO::PARAM_NULL)
                : $st->bindValue(':event', $event, PDO::PARAM_STR);

            $st->bindValue(':message', $message, PDO::PARAM_STR);
            $contextJson === null
                ? $st->bindValue(':context_json', null, PDO::PARAM_NULL)
                : $st->bindValue(':context_json', $contextJson, PDO::PARAM_STR);

            $trace === null
                ? $st->bindValue(':trace', null, PDO::PARAM_NULL)
                : $st->bindValue(':trace', $trace, PDO::PARAM_STR);

            $st->bindValue(':request_id', $requestId, PDO::PARAM_STR);
            $st->bindValue(':source',     $src, PDO::PARAM_STR);

            if ($ipBin !== null) $st->bindValue(':ip', $ipBin, PDO::PARAM_STR);
            else                 $st->bindValue(':ip', null, PDO::PARAM_NULL);

            $ua === null
                ? $st->bindValue(':user_agent', null, PDO::PARAM_NULL)
                : $st->bindValue(':user_agent', $ua, PDO::PARAM_STR);

            !empty($meta['flags'])
                ? $st->bindValue(':flags', (string)$meta['flags'], PDO::PARAM_STR)
                : $st->bindValue(':flags', null, PDO::PARAM_NULL);

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
if (!function_exists('log_exception')) {
    function log_exception(\Throwable $e, array $ctx = []): void {
        logg('error', 'exception', $e->getMessage(), $ctx + [
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
