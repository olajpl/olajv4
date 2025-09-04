<?php
// NIE ustawiamy tu nagłówków! (różne endpointy zwracają JSON/HTML)
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/** Proste helpery JSON (używaj tylko w endpointach JSON) */
function json_ok($data = []) {
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_err($msg, $code = 400) {
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  http_response_code($code);
  echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

// PODŁĄCZ projektowy DB bootstrap → daje $pdo
require_once __DIR__ . '/../../../includes/db.php';

if (!isset($pdo)) {
  json_err('Brak połączenia z DB ($pdo).', 500);
}
