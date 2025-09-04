<?php

declare(strict_types=1);

namespace Engine\Shipping;

use PDO;
use Throwable;

if (!\function_exists('logg')) {
    function logg(string $level, string $channel, string $message, array $ctx = []): void
    {
        error_log('[logg] ' . json_encode(compact('level', 'channel', 'message', 'ctx'), JSON_UNESCAPED_UNICODE));
    }
}

/**
 * Kompat z Twoim schematem:
 * - shipping_addresses: postal_code, street, building_no, apartment_no, locker_id, type
 * - shipping_labels:    external_id, tracking_number, carrier, status(enum), label_url, metadata(json)
 * - shipping_methods:   id, carrier ('inpost'), type ('locker'|'courier'...)
 */
final class InPostService
{
    public function __construct(
        private PDO          $pdo,
        private InPostClient $client,
        private array        $cfg = [] // ['label_format'=>'Pdf','label_type'=>'A6','public_label_base'=>'/uploads/labels/inpost']
    ) {}

    /**
     * Tworzy przesyłkę dla danej grupy, zapisuje wiersz w shipping_labels i plik etykiety.
     * Zwraca ['ok'=>bool,'label_id'=>int,'tracking_number'=>?string,'label_url'=>?string,'status'=>string]
     */
    public function createShipmentForGroup(int $ownerId, int $orderId, int $orderGroupId, ?int $shippingMethodId = null): array
    {
        $this->pdo->beginTransaction();
        try {
            $og   = $this->fetchOrderGroup($ownerId, $orderGroupId);
            $addr = $this->fetchShippingAddress((int)$og['shipping_address_id']);
            $sm   = $shippingMethodId ? $this->fetchShippingMethod($shippingMethodId) : $this->guessShippingMethod($ownerId, $og);

            if (!$sm || ($sm['carrier'] ?? '') !== 'inpost') {
                throw new \RuntimeException('Shipping method is not InPost');
            }

            $serviceCode = $this->detectService((string)$sm['type'], $addr);
            $sender      = $this->resolveSender($ownerId);
            $receiver    = $this->buildReceiver($addr, $serviceCode);
            $parcels     = $this->resolveParcels((int)$og['id']); // TODO: real size/weight
            $custom      = $this->buildCustom($serviceCode, $addr);

            $payload = [
                'service'           => $serviceCode,
                'reference'         => 'grp-' . $orderGroupId,
                'sender'            => $sender,
                'receiver'          => $receiver,
                'parcels'           => $parcels,
                'custom_attributes' => $custom,
            ];

            $api   = $this->client->createShipment($payload);
            $sid   = (int)($api['id'] ?? 0);
            if ($sid <= 0) throw new \RuntimeException('No shipment id');

            $statusRes  = $this->client->getShipment($sid);
            $tracking   = $statusRes['tracking_number'] ?? null;
            $shipxStat  = (string)($statusRes['status'] ?? '');
            $ourStatus  = $this->mapStatus($shipxStat);

            // shipping_labels insert
            $labelId = $this->insertLabelRow([
                'owner_id'         => $ownerId,
                'order_id'         => (int)$og['order_id'],
                'order_group_id'   => (int)$og['id'],
                'shipping_method_id' => $sm['id'] ?? null,
                'external_id'      => (string)$sid,
                'tracking_number'  => $tracking,
                'status'           => $ourStatus,
                'carrier'          => 'inpost',
                'price'            => null,
                'label_url'        => null,
                'metadata'         => ['shipx_status' => $shipxStat, 'payload' => $payload],
            ]);

            // label (jeśli już dostępna)
            $labelUrl = null;
            if (in_array($shipxStat, ['confirmed', 'created', 'ready_to_send', 'collected', 'adopted_at_source_branch', 'dispatched'], true)) {
                $fmt  = $this->cfg['label_format'] ?? 'Pdf';
                $type = $this->cfg['label_type']   ?? 'A6';
                $bin  = $this->client->getLabelBinary($sid, $fmt, $type);
                $labelUrl = $this->storeLabelBinary($ownerId, $sid, $fmt, $bin);
                $this->updateLabelFile($labelId, $labelUrl, $ourStatus);
            }

            $this->pdo->commit();
            return ['ok' => true, 'label_id' => $labelId, 'tracking_number' => $tracking, 'label_url' => $labelUrl, 'status' => $ourStatus];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            logg('error', 'shipping.inpost', 'createShipmentForGroup.fail', ['err' => $e->getMessage(), 'group' => $orderGroupId]);
            // spróbuj zapisać błąd do shipping_labels (pending->error)
            try {
                $labelId = $this->insertLabelRow([
                    'owner_id' => $ownerId,
                    'order_id' => $orderId,
                    'order_group_id' => $orderGroupId,
                    'shipping_method_id' => $shippingMethodId,
                    'external_id' => null,
                    'tracking_number' => null,
                    'status' => 'error',
                    'carrier' => 'inpost',
                    'price' => null,
                    'label_url' => null,
                    'metadata' => ['error' => $e->getMessage()],
                ]);
            } catch (\Throwable) {
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /* ===================== helpers ===================== */

    private function fetchOrderGroup(int $ownerId, int $groupId): array
    {
        $s = $this->pdo->prepare("SELECT og.id, og.order_id, og.shipping_address_id, og.shipping_method_id
                                  FROM order_groups og
                                  JOIN orders o ON o.id=og.order_id AND o.owner_id=:o
                                  WHERE og.id=:g LIMIT 1");
        $s->execute([':o' => $ownerId, ':g' => $groupId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('order_group not found');
        return $row;
    }

    private function fetchShippingAddress(int $id): array
    {
        $s = $this->pdo->prepare("SELECT * FROM shipping_addresses WHERE id=:id LIMIT 1");
        $s->execute([':id' => $id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('shipping_address not found');
        return $row;
    }

    private function fetchShippingMethod(int $id): ?array
    {
        $s = $this->pdo->prepare("SELECT id, carrier, type, name FROM shipping_methods WHERE id=:id LIMIT 1");
        $s->execute([':id' => $id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function guessShippingMethod(int $ownerId, array $og): ?array
    {
        // proste: weź z og.shipping_method_id; jeśli null – pierwsza aktywna InPost locker gdy locker_id != ''
        if (!empty($og['shipping_method_id'])) return $this->fetchShippingMethod((int)$og['shipping_method_id']);
        $addr = $this->fetchShippingAddress((int)$og['shipping_address_id']);
        if (!empty($addr['locker_id'])) {
            $s = $this->pdo->prepare("SELECT id, carrier, type, name FROM shipping_methods
                                      WHERE owner_id=:o AND carrier='inpost' AND type='locker' AND active=1
                                      ORDER BY sort_order LIMIT 1");
            $s->execute([':o' => $ownerId]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $s = $this->pdo->prepare("SELECT id, carrier, type, name FROM shipping_methods
                                  WHERE owner_id=:o AND carrier='inpost' AND type='courier' AND active=1
                                  ORDER BY sort_order LIMIT 1");
        $s->execute([':o' => $ownerId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function detectService(string $methodType, array $addr): string
    {
        if ($methodType === 'locker' || !empty($addr['locker_id'])) return 'inpost_locker_standard';
        return 'inpost_courier_standard';
    }

    private function resolveSender(int $ownerId): array
    {
        // minimal — pobierz z owners (możesz przenieść do owner_settings)
        $q = $this->pdo->prepare("SELECT name, email, phone, street, building_no, city, postal_code
                                  FROM owners WHERE id=:o LIMIT 1");
        $q->execute([':o' => $ownerId]);
        $o = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'company_name' => $o['name']   ?? 'Olaj.pl',
            'email'        => $o['email']  ?? 'no-reply@olaj.pl',
            'phone'        => $o['phone']  ?? '600000000',
            'address'      => [
                'street'        => $o['street'] ?? 'Prosta',
                'building_number' => $o['building_no'] ?? '1',
                'city'          => $o['city'] ?? 'Warszawa',
                'post_code'     => $o['postal_code'] ?? '00-001',
                'country_code'  => 'PL',
            ],
        ];
    }

    private function buildReceiver(array $a, string $service): array
    {
        $fullName = trim((string)($a['name'] ?? ''));
        $first = $last = '';
        if ($fullName !== '') {
            $parts = preg_split('/\s+/', $fullName, 2);
            $first = $parts[0] ?? '';
            $last  = $parts[1] ?? '';
        }
        $r = [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => (string)($a['email'] ?? ''),
            'phone'      => (string)($a['phone'] ?? ''),
        ];
        if ($service === 'inpost_courier_standard') {
            $r['address'] = [
                'street'         => (string)($a['street'] ?? ''),
                'building_number' => (string)($a['building_no'] ?? ''),
                'city'           => (string)($a['city'] ?? ''),
                'post_code'      => (string)($a['postal_code'] ?? ''),
                'country_code'   => 'PL',
            ];
        }
        return $r;
    }

    private function buildCustom(string $service, array $a): array
    {
        $c = [];
        if ($service === 'inpost_locker_standard') {
            $c['target_point']  = (string)($a['locker_id'] ?? '');
            $c['sending_method'] = 'dispatch_order';
        }
        return $c;
    }

    private function resolveParcels(int $groupId): array
    {
        // TODO: policz pozycje i wymiary; teraz stały szablon
        return [['template' => 'small']];
    }

    private function mapStatus(string $shipx): string
    {
        // Twoje ENUM: pending|processing|shipped|error|cancelled
        return match ($shipx) {
            'created', 'confirmed', 'ready_to_send', 'adopted_at_source_branch', 'collected', 'dispatched' => 'processing',
            'delivered' => 'shipped',
            'cancelled' => 'cancelled',
            default     => 'pending',
        };
    }

    private function storeLabelBinary(int $ownerId, int $shipId, string $fmt, string $binary): string
    {
        $publicBase = rtrim($this->cfg['public_label_base'] ?? '/uploads/labels/inpost', '/');
        $fsBase     = rtrim($this->cfg['fs_label_base']     ?? (__DIR__ . '/../../uploads/labels/inpost'), '/');
        $dirFs      = "{$fsBase}/{$ownerId}";
        if (!is_dir($dirFs)) @mkdir($dirFs, 0775, true);
        $ext = strtolower($fmt) === 'pdf' ? 'pdf' : strtolower($fmt);
        $fileFs  = "{$dirFs}/label-{$shipId}.{$ext}";
        file_put_contents($fileFs, $binary);
        return "{$publicBase}/{$ownerId}/label-{$shipId}.{$ext}";
    }

    private function insertLabelRow(array $data): int
    {
        $sql = "INSERT INTO shipping_labels
                (owner_id, order_id, order_group_id, shipping_method_id, external_id, tracking_number, status, price, carrier, label_url, error, note, flags, metadata, created_at)
                VALUES (:owner_id,:order_id,:order_group_id,:shipping_method_id,:external_id,:tracking_number,:status,:price,:carrier,:label_url,NULL,NULL,'',:metadata, NOW())";
        $s = $this->pdo->prepare($sql);
        $s->execute([
            ':owner_id'          => $data['owner_id'],
            ':order_id'          => $data['order_id'],
            ':order_group_id'    => $data['order_group_id'],
            ':shipping_method_id' => $data['shipping_method_id'],
            ':external_id'       => $data['external_id'],
            ':tracking_number'   => $data['tracking_number'],
            ':status'            => $data['status'],
            ':price'             => $data['price'],
            ':carrier'           => $data['carrier'],
            ':label_url'         => $data['label_url'],
            ':metadata'          => json_encode($data['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function updateLabelFile(int $labelId, string $labelUrl, string $status): void
    {
        $s = $this->pdo->prepare("UPDATE shipping_labels SET label_url=:u, status=:st WHERE id=:id LIMIT 1");
        $s->execute([':u' => $labelUrl, ':st' => $status, ':id' => $labelId]);
    }
}
