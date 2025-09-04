<?php
// admin/live/ajax/__live_boot.php
declare(strict_types=1);

// 1) APP_ROOT — zdefiniuj tylko raz
if (!defined('APP_ROOT')) {
    define('APP_ROOT', realpath(__DIR__ . '/../../..'));
}

// 2) Sesja — bez ostrzeżeń o już uruchomionej
if (session_status() === PHP_SESSION_NONE) {
    // te ini_set robimy TYLKO przed startem sesji
    @ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// 3) Helpery JSON – pojedyncze definicje
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
if (!function_exists('json_ok')) {
    function json_ok($data = []): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $out = is_array($data) ? $data : ['data' => $data];
        if (!isset($out['success'])) $out['success'] = true;
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
if (!function_exists('json_err')) {
    function json_err(string $msg, int $code = 400): void {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// 4) DB bootstrap
require_once APP_ROOT . '/includes/db.php';
if (!isset($pdo) || !$pdo) {
    json_err('Brak połączenia z DB ($pdo).', 500);
}

// 5) Helper kontekstu: owner_id / live_id / operator_id
if (!function_exists('ctx')) {
    function ctx(): array {
        $ownerId    = (int)($_REQUEST['owner_id'] ?? ($_SESSION['user']['owner_id'] ?? 0));
        $liveId     = (int)($_REQUEST['live_id']  ?? 0);
        $operatorId = (int)($_REQUEST['operator_user_id'] ?? ($_SESSION['user']['id'] ?? 0));
        return [$ownerId, $liveId, $operatorId];
    }
}
