<?php
// engine/Parser/Handlers/DajHandler.php — Olaj V4: parser "daj" z pełnym logowaniem CW i błędów
declare(strict_types=1);

namespace Engine\Parser\Handlers;

use PDO;
use Throwable;
use Engine\Orders\OrderEngine;
use Engine\Orders\ProductEngine;
use Engine\Enum\OrderItemChannel;
use Engine\Enum\SettingKey;
use Engine\Settings\SettingsHelper;
use Engine\CentralMessaging\Cw;
use Engine\CentralMessaging\CwHelper;
use Engine\CentralMessaging\CwTemplateResolver;

require_once __DIR__ . '/../../../includes/log.php';
require_once __DIR__ . '/../../CentralMessaging/CwHelper.php';
require_once __DIR__ . '/../../Orders/OrderEngine.php';
require_once __DIR__ . '/../../Orders/ProductEngine.php';
require_once __DIR__ . '/../../CentralMessaging/Cw.php';
require_once __DIR__ . '/../../CentralMessaging/CwTemplateResolver.php';
require_once __DIR__ . '/../../CentralMessaging/TemplateRenderer.php';
require_once __DIR__ . '/../../Enum/OrderItemChannel.php';
require_once __DIR__ . '/../../Enum/SettingKey.php';
require_once __DIR__ . '/../ParserEngine.php';
require_once __DIR__ . '/../../Settings/SettingsHelper.php';

final class DajHandler
{
    /** Jednorazowy self-test w życiu procesu (FPM worker). */
    private const SELF_TEST_ONCE_KEY = '__DAJHANDLER_SELFTEST_DONE__';

    public static function handle(PDO $pdo, int $ownerId, int $clientId, string $text): array
    {
        // Self-test loggera (przy pierwszym wywołaniu w tym procesie)
        if (!isset($GLOBALS[self::SELF_TEST_ONCE_KEY])) {
            $GLOBALS[self::SELF_TEST_ONCE_KEY] = true;
            self::log('info', 'parser', 'daj:selftest', [
                'owner_id'  => $ownerId,
                'client_id' => $clientId,
                'file'      => __FILE__,
            ]);
        }

        $text = trim($text);
        self::log('info', 'parser', 'daj:start', [
            'owner_id'  => $ownerId,
            'client_id' => $clientId,
            'text'      => $text,
        ]);

        if ($clientId <= 0) {
            self::log('warning', 'parser', 'daj:no_client', [
                'owner_id' => $ownerId,
                'text'     => $text,
            ]);
            return ['status' => 'error', 'reason' => 'client_not_found'];
        }

        $prefixes = SettingsHelper::getArray($pdo, $ownerId, SettingKey::PARSER_PREFIXES);
        if (!is_array($prefixes) || !$prefixes) $prefixes = ['daj', 'DAJ', 'Daj'];

        $parsed = self::parseDaj($text, $prefixes);
        if (!($parsed['ok'] ?? false)) {
            self::log('debug', 'parser', 'daj:parse_failed', [
                'owner_id'  => $ownerId,
                'client_id' => $clientId,
                'text'      => $text,
                'prefixes'  => $prefixes,
            ]);
            return ['status' => 'error', 'reason' => 'parse_error', 'message' => $parsed['error'] ?? 'U\u017cyj: \u201edaj KOD x2\u201d'];
        }

        // --- wejście do lookupu ---
        $codeRaw  = (string)$parsed['code'];
        $qty      = (float)$parsed['qty'];
        $codeNorm = self::normCode($codeRaw);
        self::log('debug', 'parser', 'daj:lookup_input', [
            'owner_id'  => $ownerId,
            'client_id' => $clientId,
            'code_raw'  => $codeRaw,
            'code_norm' => $codeNorm,
        ]);

        // Szukaj produktu
        $productEngine = new ProductEngine($pdo);
        $lookup = $productEngine->findProductSmart($ownerId, $codeRaw);

        // Akceptuj wynik ProductEngine, chyba że mamy pewny dowód niezgodności
        $product = null;

        if (($lookup['ok'] ?? false) && !empty($lookup['product'])) {
            $p           = $lookup['product'];
            $sku         = (string)($p['sku']  ?? '');
            $codeField   = (string)($p['code'] ?? '');
            $skuNorm     = self::normCode($sku);
            $codeFieldNr = self::normCode($codeField);
            $hasIdFields = ($sku !== '' || $codeField !== '');

            // dopasowania pozytywne
            $skuMatch  = ($sku !== ''       && (strcasecmp($sku, $codeRaw) === 0 || $skuNorm === $codeNorm || str_ends_with(strtolower($sku), '-' . strtolower($codeNorm))));
            $codeMatch = ($codeField !== '' && (strcasecmp($codeField, $codeRaw) === 0 || $codeFieldNr === $codeNorm));

            if ($skuMatch || $codeMatch || !$hasIdFields) {
                $product = $p;
            } else {
                self::log('debug', 'parser', 'daj:strict_miss', [
                    'owner_id'   => $ownerId,
                    'client_id'  => $clientId,
                    'code_raw'   => $codeRaw,
                    'code_norm'  => $codeNorm,
                    'sku'        => $sku,
                    'code_field' => $codeField,
                ]);
            }
        }

        // ⛔ Błędny kod (brak produktu) → CW + fallback
        if (!$product) {
            self::log('warning', 'parser', 'daj:not_found', [
                'owner_id'  => $ownerId,
                'client_id' => $clientId,
                'code_raw'  => $codeRaw,
                'code_norm' => $codeNorm,
                'lookup_ok' => $lookup['ok'] ?? null,
            ]);

            // 1) spróbuj auto-reply po eventcie (jeśli masz szablon)
            $auto = ['ok' => false, 'error' => 'missing_sendAutoReply'];
            if (\method_exists(\Engine\CentralMessaging\CwHelper::class, 'sendAutoReply')) {
                $auto = \Engine\CentralMessaging\CwHelper::sendAutoReply($pdo, $ownerId, $clientId, [
                    'event_key' => 'parser.invalid_product_code',
                    'channel'   => 'messenger',
                    'source'    => 'parser.daj',
                    'context'   => ['query' => $code],
                ]);
            } else {
                self::log('warning', 'parser', 'daj:no_sendAutoReply', [
                    'owner_id'  => $ownerId,
                    'client_id' => $clientId,
                    'code'      => $code,
                ]);
            }



            // 2) twardy fallback — ZAWSZE wyślij plain text, żeby mieć 'direction=out'
            $fallbackMsg = "❌ Nie znaleziono produktu o kodzie {$codeRaw}. Sprawdź i spróbuj ponownie.";
            $sendRes = Cw::sendMessengerNow($pdo, $ownerId, $clientId, $fallbackMsg, [
                'event_key' => 'parser.invalid_product_code',
                'source'    => 'parser.daj',
                'owner_id'  => $ownerId,
                'client_id' => $clientId,
            ]);
            self::log(($sendRes['ok'] ?? false) ? 'info' : 'warning', 'parser', 'daj:invalid_code_fallback_send', [
                'owner_id' => $ownerId,
                'client_id' => $clientId,
                'res' => $sendRes
            ]);

            return ['status' => 'error', 'reason' => 'product_not_found'];
        }



        // Mamy produkt — budujemy payload i jedziemy dalej
        $payload = [
            'owner_id'    => $ownerId,
            'client_id'   => $clientId,
            'product_id'  => (int)$product['id'],
            'name'        => (string)($product['name'] ?? ''),
            'qty'         => $qty,
            'unit_price'  => (float)($product['unit_price'] ?? 0.0),
            'vat_rate'    => (float)($product['vat_rate'] ?? 23.0),
            'sku'         => (string)($product['sku'] ?? ''),
            'source_type' => 'parser',
            'channel'     => OrderItemChannel::MESSENGER,
        ];

        try {
            $orderEngine = new OrderEngine($pdo);
            $res = $orderEngine->addOrderItem($payload);
            self::log('debug', 'parser', 'daj:add_item_result', [
                'owner_id'  => $ownerId,
                'client_id' => $clientId,
                'res'       => $res,
            ]);

            if (!($res['ok'] ?? false)) {
                self::log('error', 'parser', 'daj:orderengine_failed', [
                    'owner_id'  => $ownerId,
                    'client_id' => $clientId,
                    'res'       => $res,
                ]);
                return ['status' => 'error', 'reason' => 'orderengine_failed', 'details' => $res];
            }

            $checkoutToken = (string)($res['checkout_token'] ?? '');
            $baseUrl       = SettingsHelper::getString($pdo, $ownerId, SettingKey::CHECKOUT_BASE_URL) ?? '';
            $checkoutUrl   = rtrim($baseUrl, '/') . '/moje.php?token=' . urlencode($checkoutToken);
            $qtyText       = rtrim(rtrim((string)$qty, '0'), '.');

            $tplPayload = [
                'product_name'   => (string)($product['name'] ?? ''),
                'qty'            => $qtyText,
                'qty_text'       => $qtyText,
                'checkout_url'   => $checkoutUrl,
                'cart_link'      => $checkoutUrl,
                'checkout_link'  => $checkoutUrl,
                'order_id'       => (int)($res['order_id'] ?? 0),
                'order_group_id' => (int)($res['order_group_id'] ?? 0),
                'order_item_id'  => (int)($res['order_item_id'] ?? 0),
                'product_id'     => (int)($product['id'] ?? 0),
                'sku'            => (string)($product['sku'] ?? ''),
            ];

            $tpl = CwTemplateResolver::getStructuredTemplate(
                $pdo,
                $ownerId,
                'cart.item_added',
                $tplPayload,
                ['language' => 'pl', 'channel' => 'messenger']
            );

            $meta = array_merge($tplPayload, [
                'event_key' => 'cart.item_added',
                'source'    => 'parser.daj',
                'channel'   => 'messenger',
                'owner_id'  => $ownerId,
                'client_id' => $clientId,
            ]);

            $enabled = CwHelper::isEventEnabled($pdo, $ownerId, 'cart.item_added');
            self::log('debug', 'parser', 'daj:event_enabled_check', [
                'owner_id' => $ownerId,
                'enabled'  => $enabled
            ]);

            if ($enabled) {
                if (!empty($tpl['buttons'])) {
                    $structured = [
                        'text'    => (string)($tpl['text'] ?? ''),
                        'buttons' => (array)$tpl['buttons'],
                    ];
                    $sendRes = Cw::sendStructuredMessage($pdo, $ownerId, $clientId, $structured, $meta);
                } else {
                    $msg = $tpl['text'] ?? sprintf(
                        "✅ Dodano do koszyka: %s × %s\n📦 Sprawdź: %s",
                        (string)$product['name'],
                        $qtyText,
                        $checkoutUrl
                    );
                    $sendRes = Cw::sendMessengerNow($pdo, $ownerId, $clientId, $msg, $meta);
                }

                self::log('debug', 'parser', 'daj:cw_send_attempt', [
                    'owner_id'  => $ownerId,
                    'client_id' => $clientId,
                    'res'       => $sendRes
                ]);

                if (!($sendRes['ok'] ?? false)) {
                    self::log('warning', 'parser', 'daj:cw_send_fail', [
                        'owner_id'  => $ownerId,
                        'client_id' => $clientId,
                        'res'       => $sendRes
                    ]);
                } else {
                    self::log('info', 'parser', 'daj:cw_sent', [
                        'owner_id'   => $ownerId,
                        'client_id'  => $clientId,
                        'message_id' => $sendRes['message_id'] ?? null
                    ]);
                }
            } else {
                self::log('info', 'parser', 'daj:cw_skipped', [
                    'owner_id' => $ownerId,
                    'reason'   => 'event_disabled',
                    'event'    => 'cart.item_added',
                ]);
            }

            return [
                'status'         => 'ok',
                'order_id'       => (int)$res['order_id'],
                'order_group_id' => (int)$res['order_group_id'],
                'order_item_id'  => (int)$res['order_item_id'],
                'checkout_token' => $checkoutToken,
                'product'        => [
                    'id'   => (int)$product['id'],
                    'name' => (string)($product['name'] ?? ''),
                    'sku'  => (string)($product['sku'] ?? ''),
                ],
                'qty'            => $qty,
            ];
        } catch (Throwable $e) {
            self::log('error', 'parser', 'daj:exception', [
                'owner_id'  => $ownerId,
                'client_id' => $clientId,
                'msg'       => $e->getMessage(),
                'trace'     => $e->getTraceAsString()
            ]);
            return ['status' => 'error', 'reason' => 'exception', 'message' => $e->getMessage()];
        }
    }

    private static function parseDaj(string $text, array $prefixes): array
    {
        $text = trim($text);
        foreach ($prefixes as $prefix) {
            // (.+?) — co najmniej 1 znak po prefiksie, obsługa x/×/* ilości
            $re = '/^' . preg_quote(trim($prefix), '/') . '\s+(.+?)(?:\s*(?:x|×|\*)\s*([0-9]+(?:[.,][0-9]{1,3})?)\s*)?$/iu';
            if (preg_match($re, $text, $m)) {
                $code = trim((string)$m[1]);
                $qty  = isset($m[2]) ? max(0.001, (float)str_replace(',', '.', $m[2])) : 1.0;
                return ['ok' => true, 'code' => $code, 'qty' => $qty];
            }
        }
        return ['ok' => false, 'error' => 'Niepoprawny format. Spróbuj: „daj KOD x2”'];
    }

    /** Normalizacja kodu: wywal spacje/kropki/myślniki, zrób upper. */
    private static function normCode(string $s): string
    {
        $s = preg_replace('/[\s\.\-]+/u', '', $s) ?? $s;
        return mb_strtoupper($s, 'UTF-8');
    }

    /** Twarde logowanie: globalne \logg() + fallback do error_log. */
    private static function log(string $level, string $channel, string $event, array $ctx = []): void
    {
        try {
            if (\function_exists('logg')) {
                \logg($level, $channel, $event, $ctx);
                return;
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[DAJ][logger-throw] %s %s %s %s :: %s',
                $level,
                $channel,
                $event,
                json_encode($ctx, JSON_UNESCAPED_UNICODE),
                $e->getMessage()
            ));
            return;
        }

        // Ostatnia deska ratunku
        error_log(sprintf(
            '[DAJ][fallback] %s %s %s %s',
            $level,
            $channel,
            $event,
            json_encode($ctx, JSON_UNESCAPED_UNICODE)
        ));
    }
}
