<?php
// engine/CentralMessaging/CwHelper.php — Olaj V4 (refaktor + SettingKey + FeatureGate)

declare(strict_types=1);

namespace Engine\CentralMessaging;

use PDO;
use Throwable;
use Engine\Enum\SettingKey;
use Engine\Enum\PlanTier;
use Engine\Settings\FeatureGate;
use Engine\Enum\EnumRepo;

require_once __DIR__ . '/../../includes/log.php';

final class CwHelper
{
    /**
     * Znajdź albo utwórz klienta powiązanego z platformą (PSID/ID usera).
     * @return int client_id
     */
    public static function fetchOrCreateClient(PDO $pdo, int $ownerId, string $platform, string $platformUserId): int
    {
        $platform = self::normalizePlatform($platform);
        $platformUserId = trim($platformUserId);

        if ($ownerId <= 0 || $platform === '' || $platformUserId === '') {
            self::safelog('warning', 'cw', 'fetchOrCreateClient.invalid_args', compact('ownerId', 'platform', 'platformUserId'));
            return 0;
        }

        try {
            EnumRepo::ensureAllowed($pdo, 'client_platforms', $platform, $ownerId);
        } catch (Throwable $__) {
            self::safelog('warning', 'cw', 'fetchOrCreateClient.platform_not_allowed', ['platform' => $platform]);
        }

        // spróbuj znaleźć
        try {
            $st = $pdo->prepare("
                SELECT client_id
                FROM client_platform_ids
                WHERE owner_id = :oid AND platform = :p AND platform_user_id = :uid
                LIMIT 1
            ");
            $st->execute([':oid' => $ownerId, ':p' => $platform, ':uid' => $platformUserId]);
            $cid = $st->fetchColumn();
            if ($cid !== false) return (int)$cid;
        } catch (Throwable $e) {
            self::safelog('error', 'cw', 'fetchOrCreateClient.lookup_fail', ['err' => $e->getMessage()]);
        }

        // create klienta
        try {
            $pdo->beginTransaction();
            $token = self::randToken();

            $pdo->prepare("
                INSERT INTO clients (owner_id, token, registered_at)
                VALUES (:oid, :tok, NOW())
            ")->execute([':oid' => $ownerId, ':tok' => $token]);

            $clientId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO client_platform_ids (owner_id, client_id, platform, platform_user_id)
                VALUES (:oid,:cid,:p,:uid)
                ON DUPLICATE KEY UPDATE client_id=VALUES(client_id)
            ")->execute([
                ':oid' => $ownerId,
                ':cid' => $clientId,
                ':p'   => $platform,
                ':uid' => $platformUserId
            ]);

            $pdo->commit();

            self::safelog('info', 'cw', 'fetchOrCreateClient.created', [
                'owner_id'   => $ownerId,
                'client_id'  => $clientId,
                'platform'   => $platform,
                'uid'        => $platformUserId
            ]);
            return $clientId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();

            try {
                $st = $pdo->prepare("
                    SELECT client_id FROM client_platform_ids
                    WHERE owner_id=:oid AND platform=:p AND platform_user_id=:uid
                ");
                $st->execute([':oid' => $ownerId, ':p' => $platform, ':uid' => $platformUserId]);
                $cid = $st->fetchColumn();
                if ($cid !== false) return (int)$cid;
            } catch (Throwable $__) {
            }

            self::safelog('error', 'cw', 'fetchOrCreateClient.fail', ['err' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Auto-zwrotka z linkiem do checkoutu.
     */
    public static function sendAutoReplyCheckoutWithToken(PDO $pdo, int $ownerId, string $checkoutToken, string $platformUserId): void
    {
        $base = self::getOwnerSetting($pdo, $ownerId, SettingKey::CHECKOUT_BASE_URL->value)
            ?? self::getOwnerSetting($pdo, $ownerId, SettingKey::SHOP_BASE_URL->value)
            ?? '/';

        $url  = rtrim($base, '/') . '/checkout?token=' . urlencode($checkoutToken);
        $text = "Dodano do paczki ✔️\nDokończ tutaj: {$url}";

        $clientId = self::fetchOrCreateClient($pdo, $ownerId, 'messenger', $platformUserId);
        if ($clientId <= 0) return;

        try {
            if (method_exists(Cw::class, 'enqueueMessenger')) {
                Cw::enqueueMessenger($pdo, $ownerId, $clientId, $text);
            }
            if (method_exists(Cw::class, 'sendMessengerNow')) {
                Cw::sendMessengerNow($pdo, $ownerId, $clientId, $text);
            }
            self::safelog('info', 'cw', 'autoReply.ok', compact('ownerId', 'clientId', 'checkoutToken'));
        } catch (Throwable $e) {
            self::safelog('error', 'cw', 'autoReply.fail', ['err' => $e->getMessage()]);
        }
    }

    /* ───────────────────── Utils ───────────────────── */

    private static function getOwnerSetting(PDO $pdo, int $ownerId, string $key): ?string
    {
        try {
            $st = $pdo->prepare("SELECT `value` FROM owner_settings WHERE owner_id=? AND `key`=? LIMIT 1");
            $st->execute([$ownerId, $key]);
            $v = $st->fetchColumn();
            return $v !== false ? (string)$v : null;
        } catch (Throwable $__) {
            return null;
        }
    }

    private static function normalizePlatform(string $p): string
    {
        $p = strtolower(trim($p));
        return ($p === 'facebook' || $p === 'fb') ? 'messenger' : $p;
    }

    public static function randToken(int $bytes = 16): string
    {
        return 'olaj-' . bin2hex(random_bytes($bytes));
    }

    private static function safelog(string $level, string $channel, string $event, array $ctx = []): void
    {
        if (function_exists('logg')) {
            try {
                logg($level, $channel, $event, $ctx, ['context' => $channel, 'source' => 'cw']);
                return;
            } catch (Throwable $__) {
            }
        }
        error_log("[$level][$channel] $event " . json_encode($ctx, JSON_UNESCAPED_UNICODE));
    }

   public static function isEventEnabled(PDO $pdo, int $ownerId, string $eventKey): bool
{
    try {
        $stmt = $pdo->prepare("SELECT enabled FROM cw_events WHERE owner_id = ? AND event = ? LIMIT 1");
        $stmt->execute([$ownerId, $eventKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            logg('warning', 'cw', 'event_not_found', [
                'owner_id' => $ownerId,
                'event' => $eventKey
            ]);
            return false;
        }

        return (int)$row['enabled'] === 1;
    } catch (\Throwable $e) {
        logg('error', 'cw', 'isEventEnabled:exception', [
            'msg' => $e->getMessage(),
            'event' => $eventKey,
            'owner_id' => $ownerId,
        ]);
        return false;
    }
}







}
