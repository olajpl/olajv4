<?php
// admin/products/ajax_generate_desc.php — generowanie opisu AI (Ollama) + zapis
declare(strict_types=1);

define('OLAJ_JSON_API', true);
require_once __DIR__ . '/../../../bootstrap.php';

use Engine\Ai\AiEngine;
use Engine\Product\ProductEngine;

header('Content-Type: application/json; charset=utf-8');

try {
    // ── wejście ───────────────────────────────────────────────────────────────
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true) ?: [];

    $ownerId   = (int)($_SESSION['user']['owner_id'] ?? 0);
    $productId = (int)($in['product_id'] ?? 0);
    if ($ownerId <= 0) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'error'=>'auth_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($productId <= 0) {
        http_response_code(422);
        echo json_encode(['ok'=>false,'error'=>'product_id_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // (opcjonalnie) CSRF
    if (!empty($_SESSION['csrf_token'])) {
        $tok = (string)($in['csrf_token'] ?? '');
        if (!hash_equals($_SESSION['csrf_token'], $tok)) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'csrf'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // ── dane produktu (engine-first) ──────────────────────────────────────────
    $eng = ProductEngine::boot($pdo, $ownerId);
    $prod = $eng->getById($productId);
    if (!$prod) {
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'product_not_found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Dane z formularza jako „podpowiedź”
    $name   = trim((string)($in['name'] ?? $prod['name'] ?? ''));
    $code   = trim((string)($in['code'] ?? $prod['code'] ?? ''));
    $twelve = trim((string)($in['twelve_nc'] ?? $prod['twelve_nc'] ?? ''));
    $price  = (string)($in['price'] ?? ($prod['unit_price'] ?? $prod['price'] ?? ''));
    $vat    = (string)($in['vat_rate'] ?? ($prod['vat_rate'] ?? ''));
    $tags   = $in['tags'] ?? []; // lista labeli

    // ── prompt ────────────────────────────────────────────────────────────────
    $system = getenv('AI_SYSTEM_PROMPT')
        ?: 'Jesteś asystentem e-commerce Olaj.pl. Pisz po polsku, rzeczowo, bez przesady i bez obietnic zdrowotnych.';

    $lines  = [];
    $lines[] = "Napisz krótki opis produktu do sklepu internetowego.";
    $lines[] = "Styl: neutralny, konkretny. Długość: 600–900 znaków.";
    $lines[] = "Używaj krótkich zdań. Możesz dodać listę 3–5 wypunktowanych cech.";
    $lines[] = "Unikaj haseł typu „100%”, „cud”, „natychmiast”, „w naszych rękach”. Nie wymyślaj parametrów.";
    $lines[] = "";
    $lines[] = "Dane produktu:";
    $lines[] = "- Nazwa: {$name}";
    if ($code   !== '') $lines[] = "- Kod: {$code}";
    if ($twelve !== '') $lines[] = "- TwelveNC: {$twelve}";
    if ($price  !== '') $lines[] = "- Cena brutto (podgląd): {$price}";
    if ($vat    !== '') $lines[] = "- VAT (%): {$vat}";
    if (is_array($tags) && $tags) $lines[] = "- Tagi (kontekst): ".implode(', ', array_map('strval', $tags));
    $lines[] = "";
    $lines[] = "Zakończ zwięzłą, spokojną zachętą do zakupu.";

    $userPrompt = implode("\n", $lines);

    // ── payload do cache ──────────────────────────────────────────────────────
    $payload = [
        'kind'      => 'product.description',
        'productId' => $productId,
        'ownerId'   => $ownerId,
        'model'     => (defined('AI_DEFAULT_MODEL') ? AI_DEFAULT_MODEL : 'llama3:latest'),
        'system'    => $system,
        'prompt'    => $userPrompt,
        'v'         => 2 // zwiększ gdy zmienisz format promptu
    ];

    // ── compute: wołanie Ollamy ───────────────────────────────────────────────
    $res = AiEngine::cached($pdo, $ownerId, $payload, function () use ($payload) {
        $model  = (string)$payload['model'];
        $system = (string)$payload['system'];
        $prompt = (string)$payload['prompt'];

        $out = ollama_generate($model, $system, $prompt, [
            'temperature' => 0.2,
            'num_predict' => 768,
        ]);
        return [
            'text'     => $out['text'] ?? '',
            'model'    => $model,
            'metadata' => ['tokens' => $out['tokens'] ?? null]
        ];
    });

    $text = trim((string)($res['text'] ?? ''));
    if ($text === '') {
        http_response_code(502);
        echo json_encode(['ok'=>false,'error'=>'empty_ai_response'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // delikatna sanity (bez cenzury sensu)
    $blocklist = ['100%', 'cud', 'natychmiast', 'w naszych rękach'];
    $safe = str_ireplace($blocklist, '', $text);
    $safe = preg_replace('/\s+/', ' ', $safe);
    $saved = false;

    // ── zapis do DB ───────────────────────────────────────────────────────────
    // Tabela product_descriptions (zalecany UNIQUE(owner_id,product_id))
    $pdo->prepare("
        INSERT INTO product_descriptions (product_id, owner_id, generated_at, content)
        VALUES (:pid,:oid,NOW(),:c)
        ON DUPLICATE KEY UPDATE content=VALUES(content), generated_at=NOW()
    ")->execute([':pid'=>$productId, ':oid'=>$ownerId, ':c'=>$safe]);
    $saved = true;

    // jeśli products ma kolumnę description — uzupełnij też podgląd
    $hasDesc = (bool)$pdo->query("
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'products'
           AND COLUMN_NAME  = 'description'
         LIMIT 1
    ")->fetchColumn();
    if ($hasDesc) {
        $pdo->prepare("UPDATE products SET description=:d, updated_at=NOW() WHERE id=:pid AND owner_id=:oid")
            ->execute([':d'=>$safe, ':pid'=>$productId, ':oid'=>$ownerId]);
    }

    echo json_encode(['ok'=>true, 'description'=>$safe, 'saved'=>$saved, 'hash'=>$res['hash'] ?? null], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (function_exists('logg')) {
        logg('error', 'api.ai', 'ajax_generate_desc_exception', [
            'ex'=>$e->getMessage(),
            'file'=>$e->getFile().':'.$e->getLine()
        ]);
    }
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'exception','details'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/**
 * Proste wołanie Ollamy (non-stream). Zwraca ['text'=>..., 'tokens'=>int|null]
 */
function ollama_generate(string $model, string $system, string $prompt, array $opts = []): array
{
    $host = getenv('OLLAMA_HOST') ?: (defined('OLLAMA_HOST') ? OLLAMA_HOST : 'http://127.0.0.1:11434');
    $url  = rtrim($host, '/') . '/api/generate';

    $payload = [
        'model'  => $model,
        'prompt' => $prompt,
        'system' => $system,
        'stream' => false,
    ] + $opts;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch) ?: 'curl_error';
        curl_close($ch);
        throw new RuntimeException('ollama_http: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('ollama_http_status: ' . $code);
    }

    $data = json_decode($resp, true) ?: [];
    $txt  = (string)($data['response'] ?? '');
    $tok  = isset($data['eval_count']) ? (int)$data['eval_count'] : null;

    return ['text' => $txt, 'tokens' => $tok];
}
