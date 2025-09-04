<?php

declare(strict_types=1);

namespace Engine\Log;

use PDO;
use Throwable;

/**
 * LogEngine — lekki, odporny logger do tabeli `logs` (z fallbackami).
 *
 * API:
 *  - statycznie: LogEngine::write($pdo, $level, $channel, $event, $ctx?, $extra?)
 *  - instancyjnie: LogEngine::boot($pdo, $ownerId)->info($channel, $event, $ctx?, $extra?)
 */
final class LogEngine
{
    private PDO $pdo;
    private ?int $ownerId;
    /** @var array<string,bool> */
    private array $tableExistsCache = [];

    private function __construct(PDO $pdo, ?int $ownerId)
    {
        $this->pdo = $pdo;
        $this->ownerId = $ownerId;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** Fabryka dla silników */
    public static function boot(PDO $pdo, ?int $ownerId = null): self
    {
        return new self($pdo, $ownerId);
    }

    /** Alternatywna fabryka (równoważna) */
    public static function instance(PDO $pdo, ?int $ownerId = null): self
    {
        return new self($pdo, $ownerId);
    }

    /**
     * Statyczny zapis — wygodne do użycia w globalnym logg().
     */
    public static function write(?PDO $pdo, string $level, string $channel, string $event, array $context = [], array $extra = []): void
    {
        try {
            if (!$pdo) {
                @error_log(sprintf(
                    '[logs:NOPDO] %s %s %s %s',
                    $level,
                    $channel,
                    $event,
                    json_encode($context, JSON_UNESCAPED_UNICODE)
                ));
                return;
            }
            $ownerId = self::extractInt($ctx, 'owner_id', 'ownerId')
         ?? self::extractInt($extra, 'owner_id', 'ownerId')
         ?? $this->ownerId
         ?? null;

            self::boot($pdo, $ownerId)->writeInternal($level, $channel, $event, $context, $extra);
        } catch (Throwable $e) {
            @error_log(sprintf(
                '[logs:write-static-fail] %s %s %s :: %s',
                $level,
                $channel,
                $event,
                $e->getMessage()
            ));
        }
    }

    // ── Public API instancyjne ─────────────────────────────────
    public function debug(string $channel, string $event, array $ctx = [], array $extra = []): void
    {
        $this->writeInternal('debug', $channel, $event, $ctx, $extra);
    }
    public function info(string $channel, string $event, array $ctx = [], array $extra = []): void
    {
        $this->writeInternal('info', $channel, $event, $ctx, $extra);
    }
    public function warning(string $channel, string $event, array $ctx = [], array $extra = []): void
    {
        $this->writeInternal('warning', $channel, $event, $ctx, $extra);
    }
    public function error(string $channel, string $event, array $ctx = [], array $extra = []): void
    {
        $this->writeInternal('error', $channel, $event, $ctx, $extra);
    }
    public function exception(string $channel, string $event, Throwable $e, array $ctx = [], array $extra = []): void
    {
        $ctx = $ctx + [
            '_ex_message' => $e->getMessage(),
            '_ex_file'    => $e->getFile(),
            '_ex_line'    => $e->getLine(),
        ];
        $extra = $extra + ['trace' => $e->getTraceAsString()];
        $this->writeInternal('error', $channel, $event, $ctx, $extra);
    }

    // ── Core ───────────────────────────────────────────────────
    private function writeInternal(string $level, string $channel, string $event, array $ctx, array $extra): void
    {
        // 1) ID-ki z ctx (snake/camel), potem z konstruktora
        $ownerId      = self::extractInt($ctx, 'owner_id', 'ownerId') ?? $this->ownerId ?? null;
        $userId       = self::extractInt($ctx, 'user_id', 'userId');
        $clientId     = self::extractInt($ctx, 'client_id', 'clientId');
        $orderId      = self::extractInt($ctx, 'order_id', 'orderId');
        $orderGroupId = self::extractInt($ctx, 'order_group_id', 'orderGroupId');
        $liveId       = self::extractInt($ctx, 'live_id', 'liveId');

        // 2) Meta
        $defaultExtra = $this->defaultExtra($ownerId);
        $requestId = (string)($ctx['request_id'] ?? $ctx['req_id'] ?? $extra['request_id'] ?? $defaultExtra['request_id'] ?? '');
        $source    = (string)($ctx['source']     ?? $extra['source']     ?? $defaultExtra['source']);
        $ip        = (string)($ctx['ip']         ?? $extra['ip']         ?? $defaultExtra['ip']);
        $ua        = (string)($ctx['user_agent'] ?? $extra['user_agent'] ?? $defaultExtra['user_agent']);
        $flags     = (string)($ctx['flags']      ?? $extra['flags']      ?? '');
        $trace     = (string)($ctx['trace']      ?? $extra['trace']      ?? '');
        $message   = (string)($ctx['message']    ?? $extra['message']    ?? $event);

        // IP → jeśli masz VARBINARY(16), to przekaż binarkę; jeśli VARCHAR — zostaw string
        $ipParam = null;
        if ($ip !== '') {
            $bin = @inet_pton($ip);
            $ipParam = $bin !== false ? $bin : $ip; // wspiera oba typy kolumn
        }

        // 3) JSON kontekstu
        $contextJson = $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        // 4) INSERT do logs
        if ($this->tableExists('logs')) {
            try {
                $sql = "INSERT INTO `logs`
                    (owner_id, user_id, client_id, order_id, order_group_id, live_id,
                     level, channel, event, message, context_json, trace, request_id,
                     source, ip, user_agent, flags, created_at)
                 VALUES
                    (:owner_id, :user_id, :client_id, :order_id, :order_group_id, :live_id,
                     :level, :channel, :event, :message, :context_json, :trace, :request_id,
                     :source, :ip, :user_agent, :flags, NOW())";
                $st = $this->pdo->prepare($sql);
                $st->execute([
                    ':owner_id'       => $ownerId      ?: null,
                    ':user_id'        => $userId       ?: null,
                    ':client_id'      => $clientId     ?: null,
                    ':order_id'       => $orderId      ?: null,
                    ':order_group_id' => $orderGroupId ?: null,
                    ':live_id'        => $liveId       ?: null,
                    ':level'          => $level,
                    ':channel'        => $channel,
                    ':event'          => $event,
                    ':message'        => $message,
                    ':context_json'   => $contextJson,
                    ':trace'          => $trace !== '' ? $trace : null,
                    ':request_id'     => $requestId !== '' ? $requestId : null,
                    ':source'         => $source !== '' ? $source : 'engine',
                    ':ip'             => $ipParam ?: null,
                    ':user_agent'     => $ua !== '' ? $ua : null,
                    ':flags'          => $flags !== '' ? $flags : null,
                ]);
                return;
            } catch (Throwable $e) {
                @error_log(sprintf(
                    '[logs:insert-fail] %s %s %s :: %s',
                    $level,
                    $channel,
                    $event,
                    $e->getMessage()
                ));
                // fallback dalej
            }
        }

        // 5) Fallback — audit_logs
        if ($this->tableExists('audit_logs')) {
            try {
                $sql = "INSERT INTO `audit_logs`
                    (channel, level, message, context, source, owner_id, user_id, created_at)
                 VALUES
                    (:channel, :level, :message, :context, :source, :owner_id, :user_id, NOW())";
                $st = $this->pdo->prepare($sql);
                $st->execute([
                    ':channel'  => $channel,
                    ':level'    => $level,
                    ':message'  => $message,
                    ':context'  => $contextJson,
                    ':source'   => $source !== '' ? $source : 'engine',
                    ':owner_id' => $ownerId ?: null,
                    ':user_id'  => $userId  ?: null,
                ]);
                return;
            } catch (Throwable $e) {
                @error_log(sprintf(
                    '[audit_logs:insert-fail] %s %s %s :: %s',
                    $level,
                    $channel,
                    $event,
                    $e->getMessage()
                ));
            }
        }

        // 6) Ostateczność
        @error_log(sprintf(
            '[%s][%s] %s %s',
            $level,
            $channel,
            $event,
            $contextJson ?? ''
        ));
    }

    // ── Utils ───────────────────────────────────────────────────

    private function tableExists(string $table): bool
    {
        if (isset($this->tableExistsCache[$table])) {
            return $this->tableExistsCache[$table];
        }
        try {
            $this->pdo->query("DESCRIBE `$table`");
            return $this->tableExistsCache[$table] = true;
        } catch (Throwable $__) {
            return $this->tableExistsCache[$table] = false;
        }
    }

    private function defaultExtra(?int $ownerId): array
    {
        return [
           'owner_id' => $ownerId ?? ($GLOBALS['__olaj_owner_id'] ?? null),
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? ($GLOBALS['__olaj_request_id'] ?? null),
            'source'     => 'engine',
            'ip'         => $_SERVER['REMOTE_ADDR']     ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
    }

    private static function extractInt(array $ctx, string $snake, string $camel): ?int
    {
        $v = $ctx[$snake] ?? $ctx[$camel] ?? null;
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        $i = (int)$v;
        return $i > 0 ? $i : null;
    }
}
