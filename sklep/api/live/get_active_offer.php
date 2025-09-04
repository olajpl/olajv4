<?php

declare(strict_types=1);

/**
 * /api/live/get_active_offer.php
 * Zwraca JSON z aktualnie promowaną ofertą w trakcie LIVE.
 *
 * GET: live_id (int)
 * OUT: { success, offer?: { id, name, price, price_formatted, image_url }, message? }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// >> DODAJ NA SAMĄ GÓRĘ, po headerach:
if (session_status() === PHP_SESSION_NONE) {
    // nie startuj sesji wcale – to endpoint publiczny tylko do odczytu
} elseif (session_status() === PHP_SESSION_ACTIVE) {
    // jeśli jakaś biblioteka jednak ją uruchomiła, NATYCHMIAST ją zwolnij
    session_write_close();
}

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

function out(array $p): void
{
    http_response_code(200);
    echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$liveId = (int)($_GET['live_id'] ?? 0);
if ($liveId < 1) out(['success' => false, 'message' => 'Brak live_id']);

try {
    // 1) Pobierz LIVE
    $ls = $pdo->prepare("
    SELECT id, owner_id, status, active_offer_product_id, current_product_id,
           current_title, current_price, current_vat, current_updated_at
    FROM live_streams
    WHERE id = :id
    LIMIT 1
  ");
    $ls->execute([':id' => $liveId]);
    $live = $ls->fetch(PDO::FETCH_ASSOC);
    if (!$live) out(['success' => false, 'message' => 'Nie znaleziono LIVE']);

    $ownerId  = (int)$live['owner_id'];
    $pickPid  = (int)($live['active_offer_product_id'] ?? 0);
    $fallback = (int)($live['current_product_id'] ?? 0);

    // 2) Jeśli brak active_offer_product_id, wybierz prezentację:
    $pres = null;
    if ($pickPid < 1) {
        // najpierw przypięta
        $ps = $pdo->prepare("
      SELECT lp.id, lp.product_id, lp.title, lp.price, lp.vat_rate
      FROM live_presentations lp
      WHERE lp.owner_id = :oid AND lp.live_id = :lid AND lp.is_pinned = 1
      ORDER BY lp.updated_at DESC, lp.id DESC
      LIMIT 1
    ");
        $ps->execute([':oid' => $ownerId, ':lid' => $liveId]);
        $pres = $ps->fetch(PDO::FETCH_ASSOC) ?: null;

        // jeśli brak przypiętej, weź najnowszą
        if (!$pres) {
            $ps = $pdo->prepare("
        SELECT lp.id, lp.product_id, lp.title, lp.price, lp.vat_rate
        FROM live_presentations lp
        WHERE lp.owner_id = :oid AND lp.live_id = :lid
        ORDER BY lp.updated_at DESC, lp.id DESC
        LIMIT 1
      ");
            $ps->execute([':oid' => $ownerId, ':lid' => $liveId]);
            $pres = $ps->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($pres && (int)$pres['product_id'] > 0) {
            $pickPid = (int)$pres['product_id'];
        } elseif ($fallback > 0) {
            $pickPid = $fallback;
        }
    }

    if ($pickPid < 1) out(['success' => false, 'message' => 'Brak aktywnej oferty']);

    // 3) Produkt + główne zdjęcie
    $pp = $pdo->prepare("
    SELECT p.id, p.name, p.price, COALESCE(pi.image_path,'') AS image_path
    FROM products p
    LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.is_main = 1
    WHERE p.id = :pid AND p.owner_id = :oid
    LIMIT 1
  ");
    $pp->execute([':pid' => $pickPid, ':oid' => $ownerId]);
    $prod = $pp->fetch(PDO::FETCH_ASSOC);
    if (!$prod) out(['success' => false, 'message' => 'Produkt nie istnieje']);

    // 4) Ustawienia (waluta/CDN)
    $settings = [];
    try {
        $settings = getShopSettings($ownerId) ?? [];
    } catch (\Throwable $e) {
    }
    $currency = $settings['currency'] ?? 'PLN';
    $cdn      = rtrim($settings['cdn_url'] ?? 'https://panel.olaj.pl', '/');

    // 5) Złożenie oferty: tytuł/cena z prezentacji > z live.current_* > z produktu
    $name  = trim((string)($pres['title'] ?? '')) ?: trim((string)($live['current_title'] ?? '')) ?: (string)$prod['name'];
    $price = null;

    if (isset($pres['price']) && $pres['price'] !== null) {
        $price = (float)$pres['price'];
    } elseif (isset($live['current_price']) && $live['current_price'] !== null) {
        $price = (float)$live['current_price'];
    } else {
        $price = (float)$prod['price'];
    }

    $imageUrl = $prod['image_path']
        ? $cdn . '/uploads/products/' . ltrim((string)$prod['image_path'], '/')
        : 'https://via.placeholder.com/400x300?text=Brak+zdjęcia';

    // Format ceny
    $priceFormatted = number_format($price, 2, ',', ' ') . ' ' . $currency;
    if (class_exists('NumberFormatter')) {
        try {
            $fmt = new NumberFormatter('pl_PL', NumberFormatter::CURRENCY);
            $priceFormatted = $fmt->formatCurrency($price, $currency);
        } catch (\Throwable $e) {
        }
    }

    out([
        'success' => true,
        'offer' => [
            'id'              => (int)$prod['id'],
            'name'            => $name,
            'price'           => $price,
            'price_formatted' => $priceFormatted,
            'image_url'       => $imageUrl,
            'live_id'         => $liveId,
        ],
    ]);
} catch (\Throwable $e) {
    out(['success' => false, 'message' => 'Błąd serwera', 'code' => 'LIVE_OFFER_' . $e->getCode()]);
}
