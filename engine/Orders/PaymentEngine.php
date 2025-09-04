<?php
// engine/orders/PaymentEngine.php — Olaj.pl V4
declare(strict_types=1);

namespace Engine\Orders;

use PDO;
use Throwable;
use RuntimeException;

final class PaymentEngine
{
    public function __construct(private PDO $pdo) {}

    /* ───────────────────────────── Helpers ───────────────────────────── */

    private function log(string $level, string $channel, string $msg, array $ctx = []): void
    {
        try {
            if (function_exists('logg')) {
                logg($level, $channel, $msg, $ctx);
            }
        } catch (Throwable $e) {
            // miękko – brak twardych wyjątków z loggera
        }
    }

    /** Mapowanie sumy wpłat do order_groups.paid_status */
    private function mapPaidStatus(float $paid, float $due): string
    {
        if ($paid <= 0.00001) return 'nieopłacona';
        if ($paid + 0.00001 < $due) return 'częściowa';
        if (abs($paid - $due) <= 0.00001) return 'opłacona';
        return 'nadpłata';
    }

    /** Suma pozycji grupy (bez ryzykownych założeń o kolumnach shippingu). */
    private function calcGroupItemsTotal(int $groupId, int $ownerId): float
    {
        $sql = "SELECT SUM(oi.qty * oi.unit_price) AS total
                  FROM order_items oi
                  JOIN order_groups og ON og.id = oi.order_group_id
                 WHERE oi.order_group_id = :gid AND og.owner_id = :oid";
        $st = $this->pdo->prepare($sql);
        $st->execute(['gid' => $groupId, 'oid' => $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return (float)($row['total'] ?? 0.0);
    }

    /** Suma zaksięgowanych wpłat (payments.status='paid') dla grupy. */
    private function calcGroupPaidSum(int $groupId, int $ownerId): float
    {
        $sql = "SELECT COALESCE(SUM(p.amount),0) AS paid
                  FROM payments p
                  JOIN order_groups og ON og.id = p.order_group_id
                 WHERE p.order_group_id = :gid
                   AND og.owner_id = :oid
                   AND p.status = 'paid'";
        $st = $this->pdo->prepare($sql);
        $st->execute(['gid' => $groupId, 'oid' => $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return (float)($row['paid'] ?? 0.0);
    }

    /** Ustaw agregat paid_status w order_groups na podstawie sum. */
    public function recalcPaidStatus(int $ownerId, int $groupId, ?float $expectedTotal = null): string
    {
        // Wyznacz „due” – jeśli nie podane, licz z pozycji
        $due  = $expectedTotal ?? $this->calcGroupItemsTotal($groupId, $ownerId);
        $paid = $this->calcGroupPaidSum($groupId, $ownerId);
        $status = $this->mapPaidStatus($paid, $due);

        $st = $this->pdo->prepare("UPDATE order_groups SET paid_status = :ps, updated_at = NOW() WHERE id = :gid AND owner_id = :oid");
        $st->execute(['ps' => $status, 'gid' => $groupId, 'oid' => $ownerId]);

        $this->log('info', 'payments', 'recalc.paid_status', [
            'owner_id' => $ownerId,
            'order_group_id' => $groupId,
            'due' => $due,
            'paid' => $paid,
            'paid_status' => $status,
        ]);

        return $status;
    }

    /* ───────────────────────────── CRUD/State ───────────────────────────── */

    /**
     * Utwórz draft płatności.
     * Minimalny zestaw kolumn: payments(owner_id, order_id, order_group_id, method_id?, amount, status, created_at, updated_at).
     */
    public function createDraft(
        int $ownerId,
        int $orderId,
        ?int $groupId,
        float $amount,
        ?int $methodId = null,
        ?string $currency = 'PLN',
        ?string $externalId = null,
        ?array $meta = null
    ): int {
        $this->pdo->beginTransaction();
        try {
            $sql = "INSERT INTO payments
                      (owner_id, order_id, order_group_id, method_id, amount, currency, status, external_id, meta_json, created_at, updated_at)
                    VALUES
                      (:oid, :ord, :grp, :mid, :amt, :cur, 'draft', :ext, :meta, NOW(), NOW())";
            $st = $this->pdo->prepare($sql);
            $st->execute([
                'oid'  => $ownerId,
                'ord'  => $orderId,
                'grp'  => $groupId,
                'mid'  => $methodId,
                'amt'  => $amount,
                'cur'  => $currency,
                'ext'  => $externalId,
                'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]);
            $pid = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();

            $this->log('info', 'payments', 'draft.created', [
                'owner_id' => $ownerId,
                'order_id' => $orderId,
                'order_group_id' => $groupId,
                'payment_id' => $pid,
                'amount' => $amount,
                'currency' => $currency
            ]);

            return $pid;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->log('error', 'payments', 'draft.create_error', [
                'owner_id' => $ownerId,
                'order_id' => $orderId,
                'order_group_id' => $groupId,
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /** Oznacz rozpoczęcie procesu płatności (klik „Zapłać”). */
    public function markStarted(int $paymentId, int $ownerId, ?string $externalId = null, ?array $meta = null): void
    {
        $sql = "UPDATE payments
                   SET status='started', external_id = COALESCE(:ext, external_id),
                       meta_json = COALESCE(:meta, meta_json), updated_at = NOW()
                 WHERE id = :pid AND owner_id = :oid";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            'pid' => $paymentId,
            'oid' => $ownerId,
            'ext' => $externalId,
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
        $this->log('info', 'payments', 'status.started', ['owner_id' => $ownerId, 'payment_id' => $paymentId]);
    }

    /** Kanały wymagające oczekiwania (np. BLIK push). */
    public function markPending(int $paymentId, int $ownerId, ?array $meta = null): void
    {
        $sql = "UPDATE payments SET status='pending', meta_json = COALESCE(:meta, meta_json), updated_at = NOW()
                 WHERE id = :pid AND owner_id = :oid";
        $st = $this->pdo->prepare($sql);
        $st->execute(['pid' => $paymentId, 'oid' => $ownerId, 'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
        $this->log('info', 'payments', 'status.pending', ['owner_id' => $ownerId, 'payment_id' => $paymentId]);
    }

    /**
     * Księgowanie wpłaty (status='paid') + rekalkulacja `order_groups.paid_status`.
     * Jeśli płatność bez grupy, tylko zmienia status płatności.
     */
    public function markPaid(int $paymentId, int $ownerId, ?string $providerTxnId = null, ?array $meta = null, ?float $expectedTotal = null): void
    {
        $this->pdo->beginTransaction();
        try {
            // Podnieś status
            $sql = "UPDATE payments
                       SET status='paid', paid_at = NOW(),
                           external_id = COALESCE(:txn, external_id),
                           meta_json = COALESCE(:meta, meta_json),
                           updated_at = NOW()
                     WHERE id = :pid AND owner_id = :oid";
            $st = $this->pdo->prepare($sql);
            $st->execute([
                'pid' => $paymentId,
                'oid' => $ownerId,
                'txn' => $providerTxnId,
                'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]);

            // Pobierz group_id (jeśli jest)
            $g = $this->pdo->prepare("SELECT order_group_id FROM payments WHERE id = :pid AND owner_id = :oid");
            $g->execute(['pid' => $paymentId, 'oid' => $ownerId]);
            $groupId = (int)($g->fetchColumn() ?: 0);

            if ($groupId > 0) {
                $status = $this->recalcPaidStatus($ownerId, $groupId, $expectedTotal);
                $this->log('info', 'payments', 'paid.recalc_done', [
                    'owner_id' => $ownerId,
                    'payment_id' => $paymentId,
                    'order_group_id' => $groupId,
                    'paid_status' => $status
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->log('error', 'payments', 'paid.error', [
                'owner_id' => $ownerId,
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function markFailed(int $paymentId, int $ownerId, ?string $reason = null, ?array $meta = null): void
    {
        $sql = "UPDATE payments
                   SET status='failed', fail_reason = COALESCE(:r, fail_reason),
                       meta_json = COALESCE(:meta, meta_json), updated_at = NOW()
                 WHERE id = :pid AND owner_id = :oid";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            'pid' => $paymentId,
            'oid' => $ownerId,
            'r' => $reason,
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
        $this->log('warning', 'payments', 'status.failed', ['owner_id' => $ownerId, 'payment_id' => $paymentId, 'reason' => $reason]);
    }

    public function markCancelled(int $paymentId, int $ownerId, ?array $meta = null): void
    {
        $sql = "UPDATE payments
                   SET status='cancelled', meta_json = COALESCE(:meta, meta_json), updated_at = NOW()
                 WHERE id = :pid AND owner_id = :oid";
        $st = $this->pdo->prepare($sql);
        $st->execute(['pid' => $paymentId, 'oid' => $ownerId, 'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null]);
        $this->log('info', 'payments', 'status.cancelled', ['owner_id' => $ownerId, 'payment_id' => $paymentId]);
    }

    /* ───────────────────────────── Convenience ───────────────────────────── */

    /** Skrót: utwórz draft i od razu ustaw started. */
    public function startNew(
        int $ownerId,
        int $orderId,
        ?int $groupId,
        float $amount,
        ?int $methodId = null,
        ?string $currency = 'PLN',
        ?string $externalId = null,
        ?array $meta = null
    ): int {
        $pid = $this->createDraft($ownerId, $orderId, $groupId, $amount, $methodId, $currency, $externalId, $meta);
        $this->markStarted($pid, $ownerId, $externalId, $meta);
        return $pid;
    }
}
