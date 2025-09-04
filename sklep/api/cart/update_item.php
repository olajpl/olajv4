<?php
// api/update_item.php — unified cart update endpoint (add/update/remove)
// Works with cart_items(owner_id, session_id, client_token, product_id, quantity, unit_price, weight_kg)
declare(strict_types=1);

ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function getCartSessionId(): string {
  if (!isset($_COOKIE['cart_sid']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['cart_sid'])) {
    $sid = bin2hex(random_bytes(16));
    setcookie('cart_sid', $sid, time()+60*60*24*30, '/', '', false, true);
    $_COOKIE['cart_sid'] = $sid;
  }
  return $_COOKIE['cart_sid'];
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit;
  }

  // Inputs
  $ownerId   = (int)($_POST['owner_id'] ?? ($_SESSION['owner_id'] ?? 0));
  $productId = (int)($_POST['product_id'] ?? 0);
  $quantity  = (int)($_POST['quantity'] ?? 0);

  if ($ownerId <= 0 || $productId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Brak owner_id lub product_id']); exit;
  }

  $clientToken = (string)($_SESSION['client_token'] ?? '');
  $sessionId   = getCartSessionId();

  // Fetch product snapshot (price/weight/stock)
  $p = $pdo->prepare("
    SELECT price AS unit_price, weight AS weight_kg, stock, active
    FROM products
    WHERE id = ? AND owner_id = ?
    LIMIT 1
  ");
  $p->execute([$productId, $ownerId]);
  $prod = $p->fetch(PDO::FETCH_ASSOC);

  if (!$prod || (int)$prod['active'] !== 1) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Produkt niedostępny']); exit;
  }

  // Clamp to stock if given (>0 means limit)
  $stock = isset($prod['stock']) ? (int)$prod['stock'] : null;
  if ($stock !== null && $stock > 0 && $quantity > $stock) {
    $quantity = $stock;
  }

  // Remove when qty <= 0
  if ($quantity <= 0) {
    if ($clientToken) {
      $del = $pdo->prepare("DELETE FROM cart_items WHERE owner_id=? AND client_token=? AND product_id=? LIMIT 1");
      $del->execute([$ownerId, $clientToken, $productId]);
    } else {
      $del = $pdo->prepare("DELETE FROM cart_items WHERE owner_id=? AND session_id=? AND product_id=? LIMIT 1");
      $del->execute([$ownerId, $sessionId, $productId]);
    }
    echo json_encode(['ok'=>true,'removed'=>true]); exit;
  }

  // Try update first; if not existing → insert (UPSERT)
  if ($clientToken) {
    $upd = $pdo->prepare("
      UPDATE cart_items
      SET quantity=?, updated_at=NOW()
      WHERE owner_id=? AND client_token=? AND product_id=?
      LIMIT 1
    ");
    $upd->execute([$quantity, $ownerId, $clientToken, $productId]);

    if ($upd->rowCount() === 0) {
      $ins = $pdo->prepare("
        INSERT INTO cart_items (owner_id, client_token, session_id, product_id, quantity, unit_price, weight_kg, created_at)
        VALUES (:o, :t, NULL, :p, :q, :price, :w, NOW())
        ON DUPLICATE KEY UPDATE
          quantity   = VALUES(quantity),
          unit_price = VALUES(unit_price),
          weight_kg  = VALUES(weight_kg),
          updated_at = NOW()
      ");
      $ins->execute([
        ':o'=>$ownerId, ':t'=>$clientToken, ':p'=>$productId, ':q'=>$quantity,
        ':price'=>(float)$prod['unit_price'], ':w'=>isset($prod['weight_kg'])?(float)$prod['weight_kg']:null
      ]);
    }
  } else {
    $upd = $pdo->prepare("
      UPDATE cart_items
      SET quantity=?, updated_at=NOW()
      WHERE owner_id=? AND session_id=? AND product_id=?
      LIMIT 1
    ");
    $upd->execute([$quantity, $ownerId, $sessionId, $productId]);

    if ($upd->rowCount() === 0) {
      $ins = $pdo->prepare("
        INSERT INTO cart_items (owner_id, client_token, session_id, product_id, quantity, unit_price, weight_kg, created_at)
        VALUES (:o, NULL, :s, :p, :q, :price, :w, NOW())
        ON DUPLICATE KEY UPDATE
          quantity   = VALUES(quantity),
          unit_price = VALUES(unit_price),
          weight_kg  = VALUES(weight_kg),
          updated_at = NOW()
      ");
      $ins->execute([
        ':o'=>$ownerId, ':s'=>$sessionId, ':p'=>$productId, ':q'=>$quantity,
        ':price'=>(float)$prod['unit_price'], ':w'=>isset($prod['weight_kg'])?(float)$prod['weight_kg']:null
      ]);
    }
  }

  echo json_encode(['ok'=>true,'qty'=>$quantity]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Server error']);
}
