<?php
// engine/Shipping/ShippingEngine.php — Olaj V4 (shipping & logistics)
declare(strict_types=1);

namespace Engine\Shipping;

use PDO;
use Throwable;
use Engine\Orders\PaymentEngine;

final class ShippingEngine
{
  private PDO $pdo;
  private PaymentEngine $payment;

  public function __construct(PDO $pdo, PaymentEngine $payment)
  {
    $this->pdo = $pdo;
    $this->payment = $payment;
  }

  /** 1. Kalkulacja oferty — waga → koszt (multi-package support) */
  public function quoteForGroup(int $ownerId, int $orderGroupId): array
  {
    $sql = "
            SELECT SUM(p.weight * oi.qty) AS total_weight
            FROM order_items oi
            JOIN products p ON p.id = oi.product_id
           WHERE oi.order_group_id = :gid AND p.owner_id = :oid
        ";
    $st = $this->pdo->prepare($sql);
    $st->execute(['gid' => $orderGroupId, 'oid' => $ownerId]);
    $totalWeight = (float)($st->fetchColumn() ?: 0.0);

    // Fetch weight rules
    $rules = $this->pdo->prepare("
            SELECT id, name, base_price, max_weight
              FROM shipping_weight_rules
             WHERE owner_id = :oid
             ORDER BY max_weight ASC
        ");
    $rules->execute(['oid' => $ownerId]);
    $options = [];

    foreach ($rules->fetchAll(PDO::FETCH_ASSOC) as $rule) {
      $packages = (int)ceil($totalWeight / (float)$rule['max_weight']);
      $cost = $packages * (float)$rule['base_price'];

      $options[] = [
        'method_id' => (int)$rule['id'],
        'method_name' => $rule['name'],
        'total_weight' => $totalWeight,
        'packages' => $packages,
        'cost' => $cost,
      ];
    }

    return $options;
  }

  /** 2. Zapis metody + adresu lub paczkomatu */
  public function selectMethod(
    int $orderId,
    int $orderGroupId,
    int $shippingMethodId,
    array $addr
  ): array {
    // INSERT or UPDATE shipping_addresses (normalized)
    $sql = "
            INSERT INTO shipping_addresses (order_id, order_group_id, street, postcode, city, phone, email, locker_code, locker_type)
            VALUES (:oid, :gid, :street, :zip, :city, :phone, :email, :locker, :locker_type)
            ON DUPLICATE KEY UPDATE
              street = VALUES(street),
              postcode = VALUES(postcode),
              city = VALUES(city),
              phone = VALUES(phone),
              email = VALUES(email),
              locker_code = VALUES(locker_code),
              locker_type = VALUES(locker_type)
        ";
    $this->pdo->prepare($sql)->execute([
      'oid' => $orderId,
      'gid' => $orderGroupId,
      'street' => $addr['street'] ?? null,
      'zip' => $addr['postcode'] ?? null,
      'city' => $addr['city'] ?? null,
      'phone' => $addr['phone'] ?? null,
      'email' => $addr['email'] ?? null,
      'locker' => $addr['locker_code'] ?? null,
      'locker_type' => $addr['locker_type'] ?? null,
    ]);

    // Update orders.shipping_id
    $this->pdo->prepare("UPDATE orders SET shipping_id = :sm WHERE id = :id")->execute([
      'sm' => $shippingMethodId,
      'id' => $orderId,
    ]);

    return ['ok' => true];
  }

  /** 3. Integracja z brokerem (placeholder) */
  public function createShipment(int $orderId, int $orderGroupId): array
  {
    // Placeholder — simulate broker response
    $tracking = 'OLAJ-' . strtoupper(bin2hex(random_bytes(4)));
    $labelUrl = '/labels/' . $tracking . '.pdf';

    // Update orders table
    $this->pdo->prepare("
            UPDATE orders
               SET tracking_number = :trk,
                   shipping_label_url = :url,
                   shipping_created_at = NOW()
             WHERE id = :id
        ")->execute([
      'trk' => $tracking,
      'url' => $labelUrl,
      'id' => $orderId,
    ]);

    return [
      'ok' => true,
      'tracking_number' => $tracking,
      'label_url' => $labelUrl,
    ];
  }

  /** 4. Pobranie etykiety z bazy (URL) */
  public function getLabel(int $orderId, int $orderGroupId): ?string
  {
    $st = $this->pdo->prepare("SELECT shipping_label_url FROM orders WHERE id = :id LIMIT 1");
    $st->execute(['id' => $orderId]);
    return $st->fetchColumn() ?: null;
  }

  /** 5. Tracking number + data */
  public function track(int $orderId, int $orderGroupId): array
  {
    $st = $this->pdo->prepare("SELECT tracking_number, shipping_created_at FROM orders WHERE id = :id");
    $st->execute(['id' => $orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return [
      'tracking_number' => $row['tracking_number'] ?? null,
      'shipped_at' => $row['shipping_created_at'] ?? null,
    ];
  }
}
