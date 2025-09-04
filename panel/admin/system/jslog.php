<?php
// admin/system/jslog.php
// 1. opis czynności lub funkcji
// Przyjmuje POST JSON {level, channel, message, details} i loguje przez logg()

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/log.php';

header('Content-Type: application/json; charset=utf-8');
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$level   = strtoupper($data['level'] ?? 'INFO');
$channel = (string)($data['channel'] ?? 'browser');
$message = (string)($data['message'] ?? 'jslog');
$details = is_array($data['details'] ?? null) ? $data['details'] : ['raw'=>$data['details'] ?? null];

try {
  logg($level, 'browser.' . $channel, $message, $details);
  echo json_encode(['ok'=>1]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>0,'err'=>$e->getMessage()]);
}
