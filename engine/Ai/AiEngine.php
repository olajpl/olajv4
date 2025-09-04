<?php
// engine/ai/AiEngine.php — Olaj.pl V4 (zgodne z ai_cache: input_text/response_text/status/metadata)
declare(strict_types=1);

namespace Engine\Ai;

use PDO;
use Throwable;

if (!\function_exists('logg')) {
    function logg(string $level, string $channel, string $message, array $ctx = [], array $extra = []): void
    {
        error_log('[logg-fallback] ' . json_encode(compact('level', 'channel', 'message', 'ctx', 'extra'), JSON_UNESCAPED_UNICODE));
    }
}

final class AiEngine
{
    /** SELECT po (owner_id,input_hash). Zwraca wiersz lub null. */
    public static function cacheGet(PDO $pdo, int $ownerId, string $inputHash): ?array
    {
        $st = $pdo->prepare("SELECT * FROM ai_cache WHERE owner_id=:oid AND input_hash=:h LIMIT 1");
        $st->execute([':oid' => $ownerId, ':h' => $inputHash]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * UPSERT do ai_cache w Twojej strukturze.
     * Wymagane pola w $data: input_hash, input_text
     * Opcjonalne: response_text, model, status ('cached'|'expired'|'error'), error_message, metadata(array|string)
     */
    public static function cacheSet(PDO $pdo, int $ownerId, array $data): void
    {
        try {
            $meta = $data['metadata'] ?? null;
            if (is_array($meta)) {
                $meta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $sql = "
                INSERT INTO ai_cache (owner_id, input_hash, input_text, response_text, model, status, error_message, metadata, created_at)
                VALUES (:oid, :h, :in_text, :out_text, :model, :status, :err, :meta, NOW())
                ON DUPLICATE KEY UPDATE
                    input_text     = VALUES(input_text),
                    response_text  = VALUES(response_text),
                    model          = VALUES(model),
                    status         = VALUES(status),
                    error_message  = VALUES(error_message),
                    metadata       = VALUES(metadata)
            ";
            $pdo->prepare($sql)->execute([
                ':oid'     => $ownerId,
                ':h'       => (string)$data['input_hash'],
                ':in_text' => (string)($data['input_text'] ?? ''),
                ':out_text' => $data['response_text'] ?? null,
                ':model'   => $data['model'] ?? null,
                ':status'  => $data['status'] ?? 'cached',
                ':err'     => $data['error_message'] ?? null,
                ':meta'    => $meta,
            ]);

            logg('info', 'ai.cache', 'set', [
                'owner_id' => $ownerId,
                'hash' => $data['input_hash'],
                'status' => $data['status'] ?? 'cached',
            ]);
        } catch (Throwable $e) {
            logg('error', 'ai.cache', 'set_error', [
                'owner_id' => $ownerId,
                'ex' => $e->getMessage(),
            ]);
        }
    }

    // ── Helpery do stabilnego hashowania wejścia ────────────────────────────────
    public static function hashPayload(array $payload, string $salt = 'v1'): string
    {
        $norm = self::normalize($payload);
        $json = json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $salt . '|' . $json);
    }

    private static function normalize(mixed $v): mixed
    {
        if (is_array($v)) {
            $isAssoc = array_keys($v) !== range(0, count($v) - 1);
            if ($isAssoc) ksort($v);
            foreach ($v as $k => $vv) $v[$k] = self::normalize($vv);
        }
        return $v;
    }

    /**
     * Cache-first: jeśli jest cached → zwraca hit.
     * Jeśli miss i podano $compute → liczy, zapisuje do ai_cache i zwraca.
     *
     * $compute ma zwrócić: ['text'=>string, 'model'=>string|null, 'metadata'=>array|string|null]
     *
     * @return array{hit:bool, hash:string, text:?string, row:?array}
     */
    public static function cached(PDO $pdo, int $ownerId, array $payload, ?callable $compute = null, string $salt = 'v1'): array
    {
        $hash = self::hashPayload($payload, $salt);

        // HIT?
        $row = self::cacheGet($pdo, $ownerId, $hash);
        if ($row && $row['status'] === 'cached' && $row['response_text'] !== null) {
            return ['hit' => true, 'hash' => $hash, 'text' => (string)$row['response_text'], 'row' => $row];
        }

        if (!$compute) {
            return ['hit' => false, 'hash' => $hash, 'text' => null, 'row' => $row];
        }

        // MISS → compute
        try {
            $res = $compute(); // ['text','model','metadata']
            $text = (string)($res['text'] ?? '');

            self::cacheSet($pdo, $ownerId, [
                'input_hash'    => $hash,
                'input_text'    => json_encode(['salt' => $salt, 'payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_text' => $text,
                'model'         => $res['model'] ?? null,
                'status'        => 'cached',
                'metadata'      => $res['metadata'] ?? null,
            ]);

            return ['hit' => false, 'hash' => $hash, 'text' => $text, 'row' => null];
        } catch (Throwable $e) {
            self::cacheSet($pdo, $ownerId, [
                'input_hash'    => $hash,
                'input_text'    => json_encode(['salt' => $salt, 'payload' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_text' => null,
                'model'         => null,
                'status'        => 'error',
                'error_message' => $e->getMessage(),
                'metadata'      => null,
            ]);
            throw $e;
        }
    }
}
