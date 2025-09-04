<?php
// admin/live/ajax/ajax_check_availability.php
require_once __DIR__ . '/__live_boot.php';

if (!function_exists('json_out')) {
    function json_out(array $payload, int $status = 200): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$q = trim($_GET['q'] ?? '');

// TODO: pobierz $rows z DB — ważne, by $rows było tablicą asocjacyjną
$rows = $db->searchProducts($q); // przykładowo

$results = array_map(function ($row) {
    $text = $row['name'] ?? $row['text'] ?? $row['code'] ?? 'Bez nazwy';
    return [
        'id'   => (string)$row['id'],  // string dla świętego spokoju z walidatorami
        'text' => $text,
        'data' => [
            'price'   => $row['price'] ?? null,
            'stock'   => $row['stock'] ?? null,
            'code'    => $row['code'] ?? null,
            'barcode' => $row['barcode'] ?? null,
        ]
    ];
}, $rows ?? []);

json_out([
    'results'    => $results,
    'pagination' => ['more' => false],
]);