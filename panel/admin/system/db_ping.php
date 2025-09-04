<?php
// admin/system/db_ping.php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';
header('Content-Type: application/json; charset=utf-8');

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
  $one = $pdo->query('SELECT 1')->fetchColumn();
  logg('info','system.db_ping','DB ok',['version'=>$ver]);
  echo json_encode(['ok'=>1,'version'=>$ver,'one'=>(int)$one]);
} catch (Throwable $e) {
  logg('error','system.db_ping','DB fail',['error'=>$e->getMessage()]);
  echo json_encode(['ok'=>0,'error'=>$e->getMessage()]);
}
