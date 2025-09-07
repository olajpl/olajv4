<?php
// engine/ai/AiEngine.php — Olaj.pl V4 (Ollama-ready + cache-first + chat mode)
// Data: 2025-09-07
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
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * UPSERT do ai_cache.
     * Wymagane: input_hash, input_text
     * Opcjonalne: response_text, model, status ('cached'|'expired'|'error'), error_message, metadata(array|string)
     */
    public static function cacheSet(PDO $pdo, int $ownerId, array $data): void
    {
        try {
            $meta = $data['metadata'] ?? null;
            if (is_array($meta)) {
                $meta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            // Uwaga: wymagany UNIQUE(owner_id, input_hash) w ai_cache
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
                ':oid'      => $ownerId,
                ':h'        => (string)$data['input_hash'],
                ':in_text'  => (string)($data['input_text'] ?? ''),
                ':out_text' => $data['response_text'] ?? null,
                ':model'    => $data['model'] ?? null,
                ':status'   => $data['status'] ?? 'cached',
                ':err'      => $data['error_message'] ?? null,
                ':meta'     => $meta,
            ]);

            logg('info', 'ai.cache', 'set', [
                'owner_id' => $ownerId,
                'hash'     => $data['input_hash'],
                'status'   => $data['status'] ?? 'cached',
            ]);
        } catch (Throwable $e) {
            logg('error', 'ai.cache', 'set_error', [
                'owner_id' => $ownerId,
                'ex'       => $e->getMessage(),
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
     * Cache-first: jeśli HIT → zwraca hit==true.
     * Jeśli MISS i podano $compute → liczy, zapisuje do ai_cache i zwraca.
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
            $res  = $compute(); // ['text','model','metadata']
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

    // ─────────────────────────── OLLAMA CONFIG ────────────────────────────────

    /**
     * Skąd brać URL do Ollama:
     * 1) ENV OLLAMA_URL lub OLLAMA_HOST (np. http://127.0.0.1:11434)
     * 2) owner_settings (key: ai.ollama.base_url)
     * 3) fallback: http://127.0.0.1:11434
     */
    private static function getOllamaBaseUrl(PDO $pdo, int $ownerId): string
    {
        $envs = [getenv('OLLAMA_URL') ?: '', getenv('OLLAMA_HOST') ?: ''];
        foreach ($envs as $env) {
            if ($env && filter_var($env, FILTER_VALIDATE_URL)) {
                return rtrim($env, '/');
            }
        }

        try {
            $st = $pdo->prepare("SELECT `value` FROM owner_settings WHERE owner_id=:oid AND `key`='ai.ollama.base_url' LIMIT 1");
            $st->execute([':oid' => $ownerId]);
            $val = (string)($st->fetchColumn() ?: '');
            if ($val && filter_var($val, FILTER_VALIDATE_URL)) {
                return rtrim($val, '/');
            }
        } catch (\Throwable $__) {
            // brak tabeli lub brak uprawnień — trudno
        }

        return 'http://127.0.0.1:11434';
    }

    private static function curlJson(string $url, array $payload, int $timeout = 60, array $headers = []): array
    {
        $ch  = curl_init($url);
        $req = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: OlajV4-AiEngine/1.0',
        ], $headers);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $req,
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || !$raw || $code >= 400) {
            $snippet = is_string($raw) ? substr($raw, 0, 500) : '';
            throw new \RuntimeException(($err ?: "HTTP $code") . ' body=' . $snippet);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Invalid JSON from endpoint');
        }
        return $json;
    }

    // ─────────────────────────── OLLAMA: GENERATE ─────────────────────────────

    /**
     * Niskopoziomowe wywołanie /api/generate (stream=false).
     * Zwraca: ['text','model','metadata'].
     */
    public static function ollamaGenerate(PDO $pdo, int $ownerId, string $prompt, string $model = 'llama3:latest', array $options = []): array
    {
        $baseUrl = self::getOllamaBaseUrl($pdo, $ownerId);
        $url     = $baseUrl . '/api/generate';

        $payload = [
            'model'  => $model,
            'prompt' => $prompt,
            'stream' => false,
        ];

        // Pozwól przekazać np. system prompt, temperature, num_ctx, stop, top_p, top_k itd.
        foreach ($options as $k => $v) {
            $payload[$k] = $v;
        }

        try {
            $json = self::curlJson($url, $payload, (int)($options['timeout'] ?? 60));
        } catch (\Throwable $e) {
            logg('error', 'ai.ollama', 'generate_error', ['owner_id'=>$ownerId,'model'=>$model,'msg'=>$e->getMessage()]);
            throw $e;
        }

        $text = (string)($json['response'] ?? '');
        return [
            'text'     => $text,
            'model'    => $model,
            'metadata' => [
                'ollama'   => [
                    'total_duration' => $json['total_duration'] ?? null,
                    'load_duration'  => $json['load_duration'] ?? null,
                    'eval_count'     => $json['eval_count'] ?? null,
                    'eval_duration'  => $json['eval_duration'] ?? null,
                ],
                'request'  => $payload,
                'endpoint' => $url,
            ],
        ];
    }

    /**
     * Wygodny wrapper: cache-first dla promptu (salt: 'ollama:v1').
     * Zwraca dokładnie to, co `cached()`.
     */
    public static function askCached(PDO $pdo, int $ownerId, string $prompt, string $model = 'llama3:latest', array $options = []): array
    {
        $payload = [
            'kind'   => 'ollama.generate',
            'model'  => $model,
            'prompt' => $prompt,
            'opts'   => $options,
        ];

        return self::cached($pdo, $ownerId, $payload, function () use ($pdo, $ownerId, $prompt, $model, $options) {
            return self::ollamaGenerate($pdo, $ownerId, $prompt, $model, $options);
        }, 'ollama:v1');
    }

    // ─────────────────────────── OLLAMA: CHAT ─────────────────────────────────

    /**
     * /api/chat — struktura messages: [['role'=>'system'|'user'|'assistant','content'=>'...'], ...]
     * Zwraca: ['text','model','metadata'] (text = odpowiedź asystenta).
     */
    public static function ollamaChat(PDO $pdo, int $ownerId, array $messages, string $model = 'llama3.1:8b-instruct', array $options = []): array
    {
        // Sanitization & minimal walidacja
        $msgs = [];
        foreach ($messages as $m) {
            $role    = (string)($m['role'] ?? 'user');
            $content = trim((string)($m['content'] ?? ''));
            if ($content === '') { continue; }
            if (!in_array($role, ['system','user','assistant'], true)) { $role = 'user'; }
            $msgs[] = ['role' => $role, 'content' => $content];
        }
        if (!$msgs) {
            throw new \InvalidArgumentException('Empty messages for chat');
        }

        $baseUrl = self::getOllamaBaseUrl($pdo, $ownerId);
        $url     = $baseUrl . '/api/chat';

        $payload = [
            'model'    => $model,
            'messages' => $msgs,
            'stream'   => false,
        ];

        foreach ($options as $k => $v) {
            $payload[$k] = $v;
        }

        try {
            $json = self::curlJson($url, $payload, (int)($options['timeout'] ?? 60));
        } catch (\Throwable $e) {
            logg('error', 'ai.ollama', 'chat_error', ['owner_id'=>$ownerId,'model'=>$model,'msg'=>$e->getMessage()]);
            throw $e;
        }

        // Struktura: {"message":{"role":"assistant","content":"..."},"model":"..."}
        $assistant = (string)($json['message']['content'] ?? '');
        $retModel  = (string)($json['model'] ?? $model);

        return [
            'text'     => $assistant,
            'model'    => $retModel,
            'metadata' => [
                'request'  => $payload,
                'endpoint' => $url,
            ],
        ];
    }

    /**
     * Cache-first dla czatu (salt: 'ollama:chat:v1').
     * Payload haszuje cały kontekst (system + messages + model + opts).
     */
    public static function askChatCached(PDO $pdo, int $ownerId, array $messages, string $model = 'llama3.1:8b-instruct', array $options = []): array
    {
        $payload = [
            'kind'     => 'ollama.chat',
            'model'    => $model,
            'messages' => $messages,
            'opts'     => $options,
        ];

        return self::cached($pdo, $ownerId, $payload, function () use ($pdo, $ownerId, $messages, $model, $options) {
            return self::ollamaChat($pdo, $ownerId, $messages, $model, $options);
        }, 'ollama:chat:v1');
    }

    // ─────────────────────────── HEALTHCHECK ──────────────────────────────────

    /**
     * Szybki healthcheck: zwraca true, jeśli endpoint odpowiada na /api/version.
     */
    public static function healthCheck(PDO $pdo, int $ownerId): bool
    {
        $baseUrl = self::getOllamaBaseUrl($pdo, $ownerId);
        $url     = $baseUrl . '/api/version';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if (!$raw) return false;
        $j = json_decode($raw, true);
        return isset($j['version']);
    }
}
