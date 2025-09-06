<?php
// engine/Orders/PaymentEngine.php — Olaj.pl V4 (ENUM + race-safe + SaaS-ready)
declare(strict_types=1);

namespace Engine\Orders;

use PDO;
use Throwable;
use RuntimeException;
use Engine\Enums\PaidStatus;
use Engine\Enums\PaymentStatus;

final class PaymentEngine
{
    private PDO $pdo;
    private ?int $ownerId;

    /** Jeśli true, recalc sumy opiera się na payment_transactions (net_pln, status='zaksięgowana'). */
    private bool $preferTransactions = true;

    public function __construct(PDO $pdo, ?int $ownerId = null, bool $preferTransactions = true)
    {
        $this->pdo = $pdo;
        $this->ownerId = $ownerId;
        $this->preferTransactions = $preferTransactions;
    }

    /* ───────────────────────────── Helpers ───────────────────────────── */

    private function log(string $level, string $channel, string $msg, array $ctx = []): void
    {
        try { if (\function_exists('logg')) { logg($level, $channel, $msg, $ctx); } } catch (Throwable) {}
    }

    private function ensureOwnerId(?int $ownerId = null): int
    {
        $oid = $ownerId ?? $this->ownerId;
        if (!$oid) throw new RuntimeException('ownerId is required (pass in constructor or as method argument).');
        return (int)$oid;
    }

    private function j(?array $a): ?string
    {
        return $a ? json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    /** Zaokrągla/formatuje kwotę do 2 miejsc, by uniknąć float-owych artefaktów. */
    private function money(float $x): string
    {
        return number_format($x, 2, '.', '');
    }

    /** Sprytny fallback: sprawdza istnienie tabeli. */
    private function tableExists(string $table): bool
    {
        try {
            $q = $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote($table));
            return (bool)$q->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function mapPaidStatus(float $paid, float $due): PaidStatus
    {
        // mała tolerancja na ułamki grosza
        if ($paid <= 0.00001) return PaidStatus::NIEOPLACONA;
        if ($paid + 0.00001 < $due) return PaidStatus::CZESCIOWA;
        if (abs($paid - $due) <= 0.00001) return PaidStatus::OPLACONA;
        return PaidStatus::NADPLATA;
    }

    /* ─────────────────── Kalkulacje sum / due / paid ─────────────────── */

    private function calcGroupItemsTotal(int $groupId, int $ownerId): float
    {
        $sql = "SELECT COALESCE(SUM(oi.qty * oi.unit_price),0) AS total
                  FROM order_items oi
                  JOIN order_groups og ON og.id = oi.order_group_id
                 WHERE oi.order_group_id = :gid AND og.owner_id = :oid";
        $st = $this->pdo->prepare($sql);
        $st->execute(['gid' => $groupId, 'oid' => $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return (float)($row['total'] ?? 0.0);
    }

    /** Wariant A: źródłem prawdy jest księga transakcji (zalecane). */
    private function calcGroupPaidSumFromTransactions(int $groupId, int $ownerId): float
    {
        $sql = "SELECT COALESCE(SUM(
                    CASE
                      WHEN transaction_type='wpłata' THEN net_pln
                      WHEN transaction_type='zwrot'  THEN -net_pln
                      ELSE 0
                    END
                 ),0) AS paid_net
                FROM payment_transactions
               WHERE owner_id = :oid
                 AND order_group_id = :gid
                 AND status = 'zaksięgowana'";
        $st = $this->pdo->prepare($sql);
        $st->execute(['gid' => $groupId, 'oid' => $ownerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return (float)($row['paid_net'] ?? 0.0);
    }

    /** Wariant B: suma z payments.status='paid' (prosty fallback). */
    private function calcGroupPaidSumFromPayments(int $groupId, int $ownerId): float
    {
        $sql = "SELECT COALESCE(SUM(p.amount),0) AS paid
                  FROM payments p
                  JOIN order_groups og ON og.id = p.order_group_id
                 WHERE p.order_group_id = :gid
                   AND og.owner_id = :oid
                   AND p.status = :st";
        $st = $this->pdo->prepare($sql);
        $st->execute(['gid' => $groupId, 'oid' => $ownerId, 'st' => PaymentStatus::PAID->value]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return (float)($row['paid'] ?? 0.0);
    }

    private function calcGroupPaidSum(int $groupId, int $ownerId): float
    {
        if ($this->preferTransactions && $this->tableExists('payment_transactions')) {
            return $this->calcGroupPaidSumFromTransactions($groupId, $ownerId);
        }
        return $this->calcGroupPaidSumFromPayments($groupId, $ownerId);
    }

    /** Przelicza aggregated paid_status w order_groups. */
    public function recalcPaidStatus(int $ownerId, int $groupId, ?float $expectedTotal = null): string
    {
        $due  = $expectedTotal ?? $this->calcGroupItemsTotal($groupId, $ownerId);
        $paid = $this->calcGroupPaidSum($groupId, $ownerId);
        $statusEnum = $this->mapPaidStatus($paid, $due);

        $st = $this->pdo->prepare(
            "UPDATE order_groups SET paid_status = :ps, updated_at = NOW()
              WHERE id = :gid AND owner_id = :oid"
        );
        $st->execute([
            'ps'  => $statusEnum->value,
            'gid' => $groupId,
            'oid' => $ownerId
        ]);

        $this->log('info', 'payments.engine', 'recalc.paid_status', [
            'owner_id' => $ownerId,
            'order_group_id' => $groupId,
            'due' => $this->money($due),
            'paid' => $this->money($paid),
            'paid_status' => $statusEnum->value,
            'prefer_transactions' => $this->preferTransactions,
        ]);

        return $statusEnum->value;
    }

    /* ─────────────────────── CRUD / STATE TRANSITIONS ─────────────────────── */

    public function createDraft(
        int $ownerId,
        int $orderId,
        ?int $groupId,
        float $amount,
        ?int $methodId = null,
        ?string $currency = 'PLN',
        ?string $providerPaymentId = null, // provider_payment_id
        ?array $meta = null                // metadata (JSON)
    ): int {
        $ownerId = $this->ensureOwnerId($ownerId);
        $this->pdo->beginTransaction();
        try {
            $sql = "INSERT INTO payments
                      (owner_id, order_id, order_group_id, payment_method_id, amount, currency, status, provider_payment_id, metadata, created_at, updated_at)
                    VALUES
                      (:oid, :ord, :grp, :mid, :amt, :cur, :st, :ppid, :meta, NOW(), NOW())";
            $st = $this->pdo->prepare($sql);
            $st->execute([
                'oid'  => $ownerId,
                'ord'  => $orderId,
                'grp'  => $groupId,
                'mid'  => $methodId,
                'amt'  => $this->money($amount),
                'cur'  => $currency,
                'st'   => PaymentStatus::DRAFT->value,
                'ppid' => $providerPaymentId,
                'meta' => $this->j($meta),
            ]);
            $pid = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();

            $this->log('info', 'payments.engine', 'draft.created', [
                'owner_id' => $ownerId, 'order_id' => $orderId, 'order_group_id' => $groupId,
                'payment_id' => $pid, 'amount' => $this->money($amount), 'currency' => $currency
            ]);

            return $pid;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->log('error', 'payments.engine', 'draft.create_error', [
                'owner_id' => $ownerId, 'order_id' => $orderId, 'order_group_id' => $groupId,
                'amount' => $this->money($amount), 'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function markStarted(int $paymentId, int $ownerId, ?string $providerPaymentId = null, ?array $meta = null): void
    {
        $sql = "UPDATE payments
                   SET status=:st,
                       provider_payment_id = COALESCE(:ppid, provider_payment_id),
                       metadata = COALESCE(:meta, metadata),
                       status_changed_at = NOW(),
                       updated_at = NOW()
                 WHERE id = :pid AND owner_id = :oid";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            'pid'  => $paymentId,
            'oid'  => $ownerId,
            'ppid' => $providerPaymentId,
            'meta' => $this->j($meta),
            'st'   => PaymentStatus::STARTED->value,
        ]);
        $this->log('info', 'payments.engine', 'status.started', ['owner_id' => $ownerId, 'payment_id' => $paymentId]);
    }

    public function markPending(int $paymentId, int $ownerId, ?array $meta = null): void
    {
        $sql = "UPDATE payments
                   SET status=:st,
                       metadata = COALESCE(:meta, metadata),
                       status_changed_at = NOW(),
                       updated_at = NOW()
                 WHERE id = :pid AND owner_id = :oid";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            'pid'  => $paymentId,
            'oid'  => $ownerId,
            'meta' => $this->j($meta),
            'st'   => PaymentStatus::PENDING->value,
        ]);
        $this->log('info', 'payments.engine', 'status.pending', ['owner_id' => $ownerId, 'payment_id' => $paymentId]);
    }

    public function markPaid(
        int $paymentId,
        int $ownerId,
        ?string $providerPaymentId = null,
        ?array $meta = null,
        ?float $expectedTotal = null
    ): void {
        $this->pdo->beginTransaction();
        try {
            // lock konkretnej płatności by uniknąć wyścigów
            $lock = $this->pdo->prepare("SELECT id FROM payments WHERE id = :pid AND owner_id = :oid FOR UPDATE");
            $lock->execute(['pid' => $paymentId, 'oid' => $ownerId]);

            $sql = "UPDATE payments
                       SET status=:st,
                           paid_at = NOW(),
                           provider_payment_id = COALESCE(:ppid, provider_payment_id),
                           metadata = COALESCE(:meta, metadata),
                           status_changed_at = NOW(),
                           updated_at = NOW()
                     WHERE id = :pid AND owner_id = :oid";
            $st = $this->pdo->prepare($sql);
            $st->execute([
                'pid'  => $paymentId,
                'oid'  => $ownerId,
                'ppid' => $providerPaymentId,
                'meta' => $this->j($meta),
                'st'   => PaymentStatus::PAID->value,
            ]);

            // pobierz group_id do przeliczenia agregatu
            $g = $this->pdo->prepare("SELECT order_group_id FROM payments WHERE id = :pid AND owner_id = :oid");
            $g->execute(['pid' => $paymentId, 'oid' => $ownerId]);
            $groupId = (int)($g->fetchColumn() ?: 0);

            if ($groupId > 0) {
                $status = $this->recalcPaidStatus($ownerId, $groupId, $expectedTotal);
                $this->log('info', 'payments.engine', 'paid.recalc_done', [
                    'owner_id' => $ownerId, 'payment_id' => $paymentId,
                    'order_group_id' => $groupId, 'paid_status' => $status
                ]);
            } else {
                $this->log('warning', 'payments.engine', 'paid.no_group', [
                    'owner_id' => $ownerId, 'payment_id' => $paymentId
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->log('error', 'payments.engine', 'paid.error', [
                'owner_id' => $ownerId, 'payment_id' => $paymentId, 'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function markFailed(int $paymentId, int $ownerId, ?string $reason = null, ?array $meta = null): void
    {
        $sql = "UPDATE payments
                   SET status=:st,
                       failure_message = COALESCE(:msg, failure_message),
                       metadata = COALESCE(:meta, metadata),
                       status_changed_at = NOW(),
                       updated_at = NOW()
                 WHERE id = :pid AND owner_id = :oid";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            'pid' => $paymentId,
            'oid' => $ownerId,
            'msg' => $reason,
            'meta'=> $this->j($meta),
            'st'  => PaymentStatus::FAILED->value,
        ]);
        $this->log('warning', 'payments.engine', 'status.failed', [
            'owner_id' => $ownerId, 'payment_id' => $paymentId, 'reason' => $reason
        ]);
    }

    public function markCancelled(int $paymentId, int $ownerId, ?array $meta = null): void
    {
        $sql = "UPDATE payments
                   SET status=:st,
                       metadata = COALESCE(:meta, metadata),
                       status_changed_at = NOW(),
                       updated_at = NOW()
                 WHERE id = :pid AND owner_id = :oid";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            'pid' => $paymentId,
            'oid' => $ownerId,
            'meta'=> $this->j($meta),
            'st'  => PaymentStatus::CANCELLED->value,
        ]);
        $this->log('info', 'payments.engine', 'status.cancelled', ['owner_id' => $ownerId, 'payment_id' => $paymentId]);
    }

    /* ───────────────────────────── Convenience ───────────────────────────── */

    public function startNew(
        int $ownerId,
        int $orderId,
        ?int $groupId,
        float $amount,
        ?int $methodId = null,
        ?string $currency = 'PLN',
        ?string $providerPaymentId = null,
        ?array $meta = null
    ): int {
        $pid = $this->createDraft($ownerId, $orderId, $groupId, $amount, $methodId, $currency, $providerPaymentId, $meta);
        $this->markStarted($pid, $ownerId, $providerPaymentId, $meta);
        return $pid;
    }

    /** Ustaw provider, provider_payment_id, opcjonalnie paid_at i tekstowy method (jeśli kolumna istnieje). */
    public function setProviderData(
        int $paymentId,
        int $ownerId,
        ?string $provider,
        ?string $providerPaymentId,
        ?string $bookedAt = null,
        ?string $methodText = null
    ): void {
        $set = ['provider = :p', 'provider_payment_id = :ppid'];
        $params = [
            'p'    => ($provider !== '' ? $provider : null),
            'ppid' => ($providerPaymentId !== '' ? $providerPaymentId : null),
            'pid'  => $paymentId,
            'oid'  => $ownerId,
        ];
        if ($bookedAt !== null && $bookedAt !== '') { $set[] = 'paid_at = :pa';  $params['pa'] = $bookedAt; }
        if ($methodText !== null && $methodText !== '') { $set[] = 'method = :m'; $params['m']  = $methodText; }

        $sql = "UPDATE payments SET ".implode(', ', $set).", updated_at = NOW()
                 WHERE id = :pid AND owner_id = :oid";
        $st  = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    /** Skrót: draft → paid (+meta). Dla płatności manualnych (np. przelew/BLIK na telefon). */
    public function addManualPayment(
        int $orderId,
        ?int $groupId,
        int $methodId,
        float $amount,
        string $currency = 'PLN',
        ?string $note = null,
        ?int $userId = null
    ): int {
        $ownerId = $this->ensureOwnerId();

        $pid = $this->createDraft(
            ownerId:  $ownerId,
            orderId:  $orderId,
            groupId:  $groupId,
            amount:   $amount,
            methodId: $methodId,
            currency: $currency,
            providerPaymentId: null,
            meta: ['note' => $note, 'created_by' => $userId]
        );

        $this->markPaid(
            paymentId: $pid,
            ownerId:   $ownerId,
            providerPaymentId: null,
            meta: ['note' => $note, 'created_by' => $userId],
            expectedTotal: null
        );

        return $pid;
    }
}
