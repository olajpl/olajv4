<?php

declare(strict_types=1);

namespace Engine\Parser\Handlers;

use PDO;
use Olaj\CW\CwHelper;

/**
 * ZamknijHandler — kończy etap dodawania produktów.
 * Działa dla statusów PL i EN:
 *  - 'otwarta_paczka:add_products'  → 'otwarta_paczka:payment_only'
 *  - 'open_package:add_products'    → 'open_package:payment_only'
 *
 * Wysyła event 'cart.closed' z linkiem do checkoutu.
 */
final class ZamknijHandler
{
    public static function handle(PDO $pdo, int $ownerId, string $platform, string $platformId): array
    {
        if (!\function_exists('logg')) {
            function logg($l, $c, $e, $ctx = [])
            {
                error_log("$l $c $e " . json_encode($ctx, JSON_UNESCAPED_UNICODE));
            }
        }

        try {
            // 1) PSID → client_id (tworzy klienta jeśli brak) + mapowanie (owner_id-aware)
            $client = CwHelper::fetchOrCreateClient($pdo, $ownerId, $platform, $platformId);
            $clientId = (int)$client['id'];

            // 2) Odszukaj najświeższą „otwartą paczkę” tego klienta (owner + client)
            //    Uwaga: obsługujemy obie konwencje nazw statusów (PL/EN)
            $q = $pdo->prepare("
                SELECT
                    o.id                AS order_id,
                    o.order_status      AS order_status,
                    o.checkout_token    AS checkout_token,
                    og.id               AS order_group_id,
                    og.group_token      AS group_token,
                    og.checkout_completed AS checkout_completed
                FROM orders o
                LEFT JOIN order_groups og
                       ON og.order_id = o.id
                WHERE o.owner_id = :owner
                  AND o.client_id = :client
                  AND o.order_status IN (
                      'open_package:add_products',
                      'open_package:payment_only',
                      'otwarta_paczka:add_products',
                      'otwarta_paczka:payment_only'
                  )
                  AND (og.checkout_completed IS NULL OR og.checkout_completed = 0)
                ORDER BY o.id DESC
                LIMIT 1
            ");
            $q->execute(['owner' => $ownerId, 'client' => $clientId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                logg('info', 'parser_zamknij', 'no_open_package', [
                    'owner_id' => $ownerId,
                    'client_id' => $clientId
                ]);
                // Opcjonalnie: poinformuj użytkownika, że nie ma czego zamykać
                CwHelper::sendCartEvent($pdo, $ownerId, $clientId, 'cart.nothing_to_close', [
                    'reason' => 'no_open_package'
                ]);
                return ['error' => 'Brak otwartej paczki.'];
            }

            $orderId      = (int)$row['order_id'];
            $orderGroupId = (int)($row['order_group_id'] ?? 0);
            $status       = (string)$row['order_status'];
            $checkoutTok  = (string)($row['checkout_token'] ?? '');

            // 3) Jeśli nadal „dodajemy produkty”, przestaw na „payment_only” zachowując język prefixu
            if ($status === 'open_package:add_products' || $status === 'otwarta_paczka:add_products') {
                $newStatus = str_starts_with($status, 'otwarta_paczka')
                    ? 'otwarta_paczka:payment_only'
                    : 'open_package:payment_only';

                $pdo->prepare("
                    UPDATE orders
                       SET order_status = :new_status, updated_at = NOW()
                     WHERE id = :oid AND owner_id = :owner
                ")->execute([
                    'new_status' => $newStatus,
                    'oid'        => $orderId,
                    'owner'      => $ownerId,
                ]);

                $status = $newStatus;
            }

            // 4) Checkout link (z owner_settings jeśli jest, inaczej domyślny)
            $checkoutLink = self::buildCheckoutLink($pdo, $ownerId, $checkoutTok);

            // 5) Event do CW
            CwHelper::sendCartEvent($pdo, $ownerId, $clientId, 'cart.closed', [
                'order_id'       => $orderId,
                'order_group_id' => $orderGroupId ?: null,
                'checkout_link'  => $checkoutLink,
            ]);

            logg('info', 'parser_zamknij', 'closed', [
                'owner_id' => $ownerId,
                'client_id' => $clientId,
                'order_id' => $orderId,
                'order_group_id' => $orderGroupId,
                'status' => $status
            ]);

            return [
                'success'        => true,
                'order_id'       => $orderId,
                'order_group_id' => $orderGroupId ?: null,
                'checkout_token' => $checkoutTok ?: null,
                'new_status'     => $status,
                'checkout_link'  => $checkoutLink,
            ];
        } catch (\Throwable $e) {
            logg('error', 'parser_zamknij', 'exception', ['err' => $e->getMessage()]);
            return ['error' => 'Błąd: ' . $e->getMessage()];
        }
    }

    /**
     * Buduje URL do checkoutu:
     *  - próbuje owner_settings.key IN ('checkout.base_url','shop.base_url')
     *  - fallback: https://olaj.pl/checkout/{token}
     */
    private static function buildCheckoutLink(PDO $pdo, int $ownerId, ?string $token): ?string
    {
        if (!$token) return null;

        $base = null;
        try {
            $st = $pdo->prepare("
                SELECT value
                  FROM owner_settings
                 WHERE owner_id = :oid
                   AND `key` IN ('checkout.base_url','shop.base_url')
                 ORDER BY FIELD(`key`,'checkout.base_url','shop.base_url')
                 LIMIT 1
            ");
            $st->execute(['oid' => $ownerId]);
            $base = $st->fetchColumn() ?: null;
        } catch (\Throwable $__) {
        }

        $base = $base ? rtrim((string)$base, "/") : 'https://olaj.pl';
        return $base . '/checkout/' . rawurlencode($token);
    }
}
