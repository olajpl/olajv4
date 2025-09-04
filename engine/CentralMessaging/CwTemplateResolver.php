<?php

declare(strict_types=1);

namespace Engine\CentralMessaging;

use PDO;
use PDOException;
use Engine\Log\LogEngine;

/**
 * CwTemplateResolver — wybór szablonu CW:
 *  - dopasowanie: owner_id + (event_key|event) + channel (+ opcjonalnie language)
 *  - status: status='active' AND active=1
 *  - warianty: losowanie z wagami (variant, weight)
 *  - sort: najpierw nienull updated_at, potem updated_at DESC, potem id DESC (MySQL-safe)
 *  - legacy: COALESCE(event_key, event)
 *  - guziki: buttons_json → buttons (array)
 */
final class CwTemplateResolver
{
    /**
     * @param PDO    $pdo
     * @param int    $ownerId
     * @param string $eventKey   np. 'checkout.completed'
     * @param string $channel    'messenger' | 'email' | 'sms'
     * @param array  $opts       ['language'=>'pl','strict'=>false,'allow_fallback'=>true]
     */
    public static function resolve(PDO $pdo, int $ownerId, string $eventKey, string $channel, array $opts = []): ?array
    {
        $language      = isset($opts['language']) && is_string($opts['language']) ? $opts['language'] : null;
        $strict        = (bool)($opts['strict']          ?? false);
        $allowFallback = (bool)($opts['allow_fallback']  ?? true);

        $log = self::bootLogger($pdo, $ownerId);

        try {
            // 1) Exact language match (jeśli podany)
            $candidates = self::queryTemplates($pdo, $ownerId, $eventKey, $channel, $language);
            if (!empty($candidates)) {
                $pick = self::pickWeighted($candidates);
                $tpl  = self::normalizeTemplateRow($pick, '_source', 'primary');
                $log->debug('cw.resolver', 'resolve_success_multi', [
                    'owner_id' => $ownerId,
                    'event_key' => $eventKey,
                    'channel' => $channel,
                    'language' => $language,
                    'variants' => count($candidates),
                    'picked_variant' => $tpl['variant'] ?? null
                ]);
                return $tpl;
            }

            // 2) Fallback bez języka (dowolny aktywny), gdy dozwolone
            if ($language !== null && $allowFallback && !$strict) {
                $candidates = self::queryTemplates($pdo, $ownerId, $eventKey, $channel, null);
                if (!empty($candidates)) {
                    $pick = self::pickWeighted($candidates);
                    $tpl  = self::normalizeTemplateRow($pick, '_source', 'fallback_language');
                    $log->warning('cw.resolver', 'resolve_fallback_language', [
                        'owner_id' => $ownerId,
                        'event_key' => $eventKey,
                        'channel' => $channel,
                        'requested_lang' => $language,
                        'picked_lang' => $tpl['language'] ?? null,
                        'variants' => count($candidates),
                        'picked_variant' => $tpl['variant'] ?? null
                    ]);
                    return $tpl;
                }
            }

            // 3) Miss
            $log->warning('cw.resolver', 'resolve_miss', [
                'owner_id' => $ownerId,
                'event_key' => $eventKey,
                'channel' => $channel,
                'language' => $language,
                'strict' => $strict,
                'allow_fallback' => $allowFallback
            ]);
            return null;
        } catch (PDOException $e) {
            self::err($log, 'resolve_pdo_error', $ownerId, $eventKey, $channel, $language, $e->getMessage());
            return null;
        } catch (\Throwable $e) {
            self::err($log, 'resolve_generic_error', $ownerId, $eventKey, $channel, $language, $e->getMessage());
            return null;
        }
    }

    /** Wariant „strict” — wyłącznie exact language match */
    public static function resolveStrict(PDO $pdo, int $ownerId, string $eventKey, string $channel, ?string $language = null): ?array
    {
        return self::resolve($pdo, $ownerId, $eventKey, $channel, [
            'language'       => $language,
            'strict'         => true,
            'allow_fallback' => false,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private static function bootLogger(PDO $pdo, int $ownerId): LogEngine
    {
        return LogEngine::boot($pdo, $ownerId, [
            'context' => 'cw.resolver',
            'source'  => 'CwTemplateResolver'
        ]);
    }

    /**
     * Pobiera wszystkie dopasowane rekordy (może zwrócić wiele wariantów).
     * MySQL-safe ORDER BY (bez NULLS LAST):
     *  - najpierw rekordy z NIE-NULL updated_at (updated_at IS NULL ASC)
     *  - potem updated_at DESC
     *  - na końcu id DESC
     */
    private static function queryTemplates(PDO $pdo, int $ownerId, string $eventKey, string $channel, ?string $language): array
    {
        $sql = "
    SELECT t.*
    FROM cw_templates t
    WHERE t.owner_id = :oid
      AND t.event_key = :ek
      AND t.channel = :ch
      AND (t.status = 'active')
      AND (t.active = 1)
      " . ($language !== null ? "AND t.language = :lang" : "") . "
    ORDER BY
      (t.updated_at IS NULL) ASC,
      t.updated_at DESC,
      t.id DESC
";


        $params = [':oid' => $ownerId, ':ek' => $eventKey, ':ch' => $channel];
        if ($language !== null) $params[':lang'] = $language;

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $rows;
    }

    /** Losowanie z wagami; gdy brak kolumny weight → traktuje jak 1 */
    private static function pickWeighted(array $rows): array
    {
        // Mikro-optymalizacja: policz sumę wag i wylosuj bez alokowania poola
        $total = 0;
        foreach ($rows as $r) {
            $w = (int)($r['weight'] ?? 1);
            if ($w < 1) $w = 1;
            $total += $w;
        }
        $needle = random_int(1, max(1, $total));
        $acc = 0;
        foreach ($rows as $r) {
            $w = (int)($r['weight'] ?? 1);
            if ($w < 1) $w = 1;
            $acc += $w;
            if ($needle <= $acc) {
                return $r;
            }
        }
        return $rows[array_key_first($rows)]; // fallback teoretycznie nieosiągalny
    }

    /**
     * Normalizacja: placeholdery, test_payload, buttons_json, variant/weight,
     * preferencje body_* vs template_* oraz legacy event.
     */
    private static function normalizeTemplateRow(array $row, string $flagKey = '_source', string $flagValue = 'primary'): array
    {
        $placeholders = self::decodeJsonField($row['placeholders'] ?? null);
        $testPayload  = self::decodeJsonField($row['test_payload'] ?? null);
        $buttons      = self::decodeJsonField($row['buttons_json'] ?? null);

        $eventKey = $row['event_key'] ?? ($row['event'] ?? null);

        $bodyText = $row['body_text']    ?? $row['template_text'] ?? null;
        $bodyHtml = $row['body_html']    ?? $row['template_html'] ?? null;

        $normalized = [
            'id'            => (int)$row['id'],
            'owner_id'      => (int)$row['owner_id'],
            'event_key'     => $eventKey,
            'event_id'      => isset($row['event_id']) ? (int)$row['event_id'] : null,
            'channel'       => $row['channel'] ?? null,

            'status'        => $row['status'] ?? 'active',
            'active'        => isset($row['active']) ? (int)$row['active'] : 1,
            'language'      => $row['language'] ?? null,

            'variant'       => isset($row['variant']) ? (int)$row['variant'] : 1,
            'weight'        => isset($row['weight'])  ? (int)$row['weight']  : 1,

            'subject'       => $row['subject'] ?? null,
            'body_text'     => $bodyText,
            'body_html'     => $bodyHtml,

            'template_name' => $row['template_name'] ?? null,
            'template_code' => $row['template_code'] ?? null,
            'template_text' => $row['template_text'] ?? null,
            'template_html' => $row['template_html'] ?? null,

            'placeholders'  => is_array($placeholders) ? $placeholders : null,
            'test_payload'  => is_array($testPayload)  ? $testPayload  : null,
            'buttons'       => is_array($buttons)      ? $buttons      : null,

            'updated_at'    => $row['updated_at'] ?? null,
            $flagKey        => $flagValue,
        ];

        if (!isset($row['event_key']) && isset($row['event'])) {
            $normalized[$flagKey] = 'legacy_event_column';
        }

        return $normalized;
    }

    /** Bezpieczne dekodowanie JSON */
    private static function decodeJsonField($val): ?array
    {
        if ($val === null) return null;
        if (is_array($val)) return $val;
        if (!is_string($val) || trim($val) === '') return null;
        $decoded = json_decode($val, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function err(LogEngine $log, string $event, int $ownerId, string $eventKey, string $channel, ?string $language, string $msg): void
    {
        $log->error('cw.resolver', $event, [
            'owner_id' => $ownerId,
            'event_key' => $eventKey,
            'channel' => $channel,
            'language' => $language,
            'error' => $msg
        ]);
    }
    public static function getStructuredTemplate(PDO $pdo, int $ownerId, string $eventKey, array $payload = [], array $opts = []): array
    {
        $channel = $opts['channel'] ?? 'messenger';
        $tpl     = \Engine\CentralMessaging\CwTemplateResolver::resolve($pdo, $ownerId, $eventKey, $channel, $opts);

        if (!$tpl || !is_array($tpl)) {
            return [
                'text'    => '',
                'html'    => null,
                'buttons' => [],
            ];
        }

        return TemplateRenderer::renderStructured($tpl, $payload, $opts);
    }
}
