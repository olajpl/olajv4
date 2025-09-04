<?php
// engine/Notifications/NotificationEngine.php — Olaj.pl V4
declare(strict_types=1);

namespace Engine\Notifications;

use PDO;
use Engine\CentralMessaging\Cw;
use Engine\Log\LogEngine;
use Throwable;

final class NotificationEngine
{
    public function __construct(
        private PDO $pdo,
        private Cw $cw,
        private LogEngine $log
    ) {}

    /**
     * Główna metoda: wyślij powiadomienie (jeśli spełnia warunki).
     *
     * @param array $event ['owner_id', 'event_key', 'context'=>[...] ]
     */
    public function dispatch(array $event): void
    {
        try {
            $ownerId = (int)($event['owner_id'] ?? 0);
            $eventKey = (string)($event['event_key'] ?? '');
            $ctx = $event['context'] ?? [];

            if (!$ownerId || !$eventKey) {
                $this->log->error('notifications.dispatch', 'Missing owner_id or event_key', compact('event'));
                return;
            }

            $audience = $this->resolveAudience($ownerId, $eventKey, $ctx);
            $policy = $this->resolvePolicy($ownerId, $eventKey);
            $channels = $this->orderedChannels($policy['channels_set'] ?? '');

            foreach ($audience as $target) {
                foreach ($channels as $channel) {
                    if (!$this->shouldSend($ownerId, $eventKey, $target, $channel, $policy, $ctx)) {
                        $this->recordSend($ownerId, $eventKey, $target, $channel, 'skipped', 'policy');
                        continue;
                    }

                    $payload = $this->renderPayload($ownerId, $eventKey, $channel, $ctx);
                    $this->cw->enqueue($ownerId, $channel, 'out', $payload, [
                        'event_key' => $eventKey,
                        'context' => $ctx,
                        'target' => $target,
                    ]);

                    $this->recordSend($ownerId, $eventKey, $target, $channel, 'sent', null);
                    break; // nie próbujemy dalej jeśli 1 kanał przejdzie
                }
            }
        } catch (Throwable $e) {
            $this->log->error('notifications.dispatch', 'Exception in dispatch', ['error'=>$e->getMessage(), 'trace'=>$e->getTraceAsString()]);
        }
    }

    /** Kto jest odbiorcą (tylko klient na razie) */
    private function resolveAudience(int $ownerId, string $eventKey, array $ctx): array
    {
        if (!empty($ctx['client_id'])) {
            return [['type' => 'client', 'id' => (int)$ctx['client_id']]];
        }
        if (!empty($ctx['order_id'])) {
            $stmt = $this->pdo->prepare("SELECT client_id FROM orders WHERE id = :id AND owner_id = :owner_id");
            $stmt->execute([':id' => (int)$ctx['order_id'], ':owner_id' => $ownerId]);
            $clientId = (int)($stmt->fetchColumn() ?? 0);
            if ($clientId) {
                return [['type' => 'client', 'id' => $clientId]];
            }
        }
        return [];
    }

    /** Zwraca kanały jako array w kolejności */
    private function orderedChannels(string $raw): array
    {
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    /** Czy powinien wysłać (anti-spam) */
    private function shouldSend(
        int $ownerId,
        string $eventKey,
        array $target,
        string $channel,
        array $policy,
        array $ctx
    ): bool {
        $throttleSec = (int)($policy['throttle_seconds'] ?? 3600);
        $dedupeKey = $this->dedupeKey($eventKey, $ctx);
        $sql = "SELECT COUNT(*) FROM notification_sends
                WHERE owner_id = :o AND event_key = :e AND target_type = :tt AND target_id = :ti
                AND channel = :c AND created_at >= NOW() - INTERVAL :s SECOND
                AND dedupe_key = :dk";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':o' => $ownerId,
            ':e' => $eventKey,
            ':tt'=> $target['type'],
            ':ti'=> $target['id'],
            ':c' => $channel,
            ':s' => $throttleSec,
            ':dk'=> $dedupeKey,
        ]);
        return ((int)$stmt->fetchColumn()) === 0;
    }

    /** Zwraca politykę wysyłki */
    private function resolvePolicy(int $ownerId, string $eventKey): array
    {
        $sql = "SELECT * FROM notification_policies WHERE owner_id = :o AND event_key = :e AND active = 1
                ORDER BY updated_at DESC, id DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':o' => $ownerId, ':e' => $eventKey]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'channels_set' => 'messenger,email',
            'throttle_scope' => 'client',
            'throttle_seconds' => 3600,
        ];
    }

    /** Renderuj payload (placeholdery, template) */
    private function renderPayload(int $ownerId, string $eventKey, string $channel, array $ctx): array
    {
        // Można to podpiąć do CwTemplateResolver + TemplateRenderer
        return [
            'text' => "[Dev] Event: $eventKey",
            'placeholders' => $ctx,
            'event_key' => $eventKey,
        ];
    }

    /** Zapisz audyt (queued/skipped) */
    private function recordSend(
        int $ownerId,
        string $eventKey,
        array $target,
        string $channel,
        string $status,
        ?string $reason
    ): void {
        $stmt = $this->pdo->prepare("INSERT INTO notification_sends
            (owner_id, event_key, target_type, target_id, channel, status, reason, dedupe_key, created_at)
            VALUES (:o, :e, :tt, :ti, :c, :s, :r, :dk, NOW())");
        $stmt->execute([
            ':o' => $ownerId,
            ':e' => $eventKey,
            ':tt'=> $target['type'],
            ':ti'=> $target['id'],
            ':c' => $channel,
            ':s' => $status,
            ':r' => $reason,
            ':dk'=> $this->dedupeKey($eventKey, $target),
        ]);
    }

    /** Dedupe key – unikalny na event+target */
    private function dedupeKey(string $eventKey, array $ctx): string
    {
        if (!empty($ctx['order_id'])) {
            return 'order:' . $ctx['order_id'] . '#' . $eventKey;
        }
        if (!empty($ctx['client_id'])) {
            return 'client:' . $ctx['client_id'] . '#' . $eventKey;
        }
        return $eventKey;
    }
}
