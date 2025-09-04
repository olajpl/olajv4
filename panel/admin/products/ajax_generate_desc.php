<?php
// admin/products/ajax_generate_desc.php — cache-first (ai_cache: input_text/response_text/status/metadata)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';
require_once __DIR__ . '/../../engine/Ai/AiEngine.php';

use Engine\Ai\AiEngine;

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();
$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$name  = trim((string)($input['name'] ?? ''));
$code  = trim((string)($input['code'] ?? ''));
$t12   = trim((string)($input['twelve_nc'] ?? ''));
$price = trim((string)($input['price'] ?? ''));
$vat   = (int)($input['vat_rate'] ?? 23);
$tags  = (array)($input['tags'] ?? []);

if ($name === '') {
  echo json_encode(['description' => '']);
  exit;
}

// payload -> hash
$payload = [
  'name'      => $name,
  'code'      => $code,
  'twelve_nc' => $t12,
  'price'     => $price,
  'vat_rate'  => $vat,
  'tags'      => array_values(array_map('strval', $tags)),
];

// cache-first
$res = AiEngine::cached(
  $pdo,
  $ownerId,
  $payload,
  function () use ($name, $code, $t12, $price, $vat, $tags): array {
    $bullets = [];
    if ($code) $bullets[] = "Kod produktu: $code";
    if ($t12)  $bullets[] = "12NC: $t12";
    if ($price !== '') $bullets[] = "Cena katalogowa: {$price} zł (VAT {$vat}%)";
    if (!empty($tags)) $bullets[] = "Kategoryzacja: " . implode(', ', array_map('strval', $tags));

    $desc  = "### {$name}\n\n";
    $desc .= "Praktyczny produkt do codziennego użytku. Sprawdzi się zarówno w domu, jak i w pracy. ";
    $desc .= "Wyróżnia się dobrym stosunkiem jakości do ceny oraz prostą obsługą.\n\n";
    if ($bullets) {
      $desc .= "**Parametry i informacje:**\n";
      foreach ($bullets as $b) $desc .= "- {$b}\n";
      $desc .= "\n";
    }
    $desc .= "Jeżeli masz pytania, napisz – chętnie doradzimy najlepszy wariant.";

    return [
      'text'     => $desc,
      'model'    => 'rule',         // tu wstawisz nazwę modelu, kiedy podepniesz prawdziwe AI
      'metadata' => ['kind' => 'desc_v1']
    ];
  },
  'desc_v1' // salt/wersja promptu/reguł
);

echo json_encode(['description' => (string)($res['text'] ?? '')]);
