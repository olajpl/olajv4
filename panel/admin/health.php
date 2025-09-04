<?php
// admin/health.php — OLAJ_V4 healthcheck (logs + fallback audit_logs, CW/messages dry-run)
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
// require_once __DIR__ . '/../includes/log.php'; // opcjonalnie

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$now        = (new DateTimeImmutable())->format('c');
$requestId  = bin2hex(random_bytes(16));
$ownerId    = (int)($_SESSION['user']['owner_id'] ?? 1);
$userId     = (int)($_SESSION['user']['id'] ?? 0);
$userAgent  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$ipStr      = $_SERVER['REMOTE_ADDR'] ?? null;
$ipBin      = $ipStr ? @inet_pton($ipStr) : null;

function tableExists(PDO $pdo, string $table): bool {
  $s = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
  $s->execute([':t'=>$table]);
  return (bool)$s->fetchColumn();
}

$out = [
  'ok'          => false,
  'ts'          => $now,
  'request_id'  => $requestId,
  'logger_ok'   => false,
  'webhook_ok'  => false,
  'details'     => []
];

/** ─────────────────────────────────────────────────────────────
 *  1) LOGGER: preferuj `logs`, fallback na `audit_logs`
 *  - dla `logs` używamy msg_hash + pełnego zestawu pól
 *  - dla `audit_logs` proste wstawienie + SELECT po tokenie
 *  ────────────────────────────────────────────────────────────*/
try {
  if (tableExists($pdo, 'logs')) {
    // Nowa tabela logs
    $channel = 'diag';
    $level   = 'info';
    $context = 'monitor';
    $message = 'admin/health logger smoke test';
    $msgHash = hash('sha256', $channel.'|'.$level.'|'.$message.'|'.$requestId);

    $sql = "INSERT INTO logs
      (owner_id,user_id,client_id,order_id,context,order_group_id,live_id,
       level,channel,event,message,context_json,trace,request_id,source,ip,
       user_agent,flags,created_at,msg_hash)
     VALUES
      (:owner_id,:user_id,NULL,NULL,:context,NULL,NULL,
       :level,:channel,:event,:message,:context_json,NULL,:request_id,:source,:ip,
       :user_agent,:flags,NOW(),:msg_hash)";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':owner_id',    $ownerId, PDO::PARAM_INT);
    $stmt->bindValue(':user_id',     $userId,  PDO::PARAM_INT);
    $stmt->bindValue(':context',     $context);
    $stmt->bindValue(':level',       $level);
    $stmt->bindValue(':channel',     $channel);
    $stmt->bindValue(':event',       'logger_smoke');
    $stmt->bindValue(':message',     $message);
    $stmt->bindValue(':context_json', json_encode(['request_id'=>$requestId,'ua'=>$userAgent], JSON_UNESCAPED_UNICODE));
    $stmt->bindValue(':request_id',  $requestId);
    $stmt->bindValue(':source',      'api'); // enum('panel','shop','webhook','cron','cli','api')
    $stmt->bindValue(':ip',          $ipBin, is_null($ipBin)?PDO::PARAM_NULL:PDO::PARAM_LOB);
    $stmt->bindValue(':user_agent',  $userAgent);
    $stmt->bindValue(':flags',       'pii_redacted');
    $stmt->bindValue(':msg_hash',    $msgHash);
    $stmt->execute();

    // weryfikacja po msg_hash
    $check = $pdo->prepare("SELECT id, created_at FROM logs WHERE msg_hash = :h ORDER BY id DESC LIMIT 1");
    $check->execute([':h'=>$msgHash]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $out['logger_ok'] = true;
      $out['details']['logger'] = ['table'=>'logs','id'=>(int)$row['id'],'created_at'=>$row['created_at']];
    } else {
      $out['details']['logger'] = ['table'=>'logs','error'=>'no_row_with_msg_hash'];
    }
  } elseif (tableExists($pdo, 'audit_logs')) {
    // Fallback: stara tabela audit_logs
    $token  = 'health:'.$requestId;
    $ins    = $pdo->prepare("
      INSERT INTO audit_logs (channel, level, message, context, source, owner_id, user_id, created_at)
      VALUES ('diag','info',:msg,JSON_OBJECT('request_id',:rid,'ua',:ua),'api',:owner_id,:user_id,NOW())
    ");
    $ins->execute([
      ':msg'      => "admin/health logger smoke test {$token}",
      ':rid'      => $requestId,
      ':ua'       => $userAgent,
      ':owner_id' => $ownerId,
      ':user_id'  => $userId,
    ]);
    $sel = $pdo->prepare("SELECT id, created_at FROM audit_logs WHERE message LIKE :m ORDER BY id DESC LIMIT 1");
    $sel->execute([':m'=>"%{$token}%"]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $out['logger_ok'] = true;
      $out['details']['logger'] = ['table'=>'audit_logs','id'=>(int)$row['id'],'created_at'=>$row['created_at']];
    } else {
      $out['details']['logger'] = ['table'=>'audit_logs','error'=>'insert_not_visible'];
    }
  } else {
    $out['details']['logger'] = 'no_logs_table_found';
  }
} catch (Throwable $e) {
  $out['details']['logger_error'] = $e->getMessage();
}

/** ─────────────────────────────────────────────────────────────
 *  2) CW/webhook: dry-run INSERT do `messages` (ROLLBACK)
 *  - u Ciebie messages ma sender_type NOT NULL → ustawiamy 'system'
 *  ────────────────────────────────────────────────────────────*/
try {
  if (!tableExists($pdo, 'messages')) {
    $out['details']['webhook'] = 'messages_table_missing';
  } else {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
      INSERT INTO messages (
        owner_id, client_id, operator_user_id, order_id, order_group_id, live_id, campaign_id, related_id,
        direction, channel, sender_type, platform, platform_user_id, platform_msg_id, platform_thread_id,
        status, content, error, flags, metadata, created_at
      ) VALUES (
        :owner_id, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
        'out','manual','system','monitor',NULL,NULL,NULL,
        'pending', :content, NULL, :flags, :meta, NOW()
      )
    ");
    $stmt->execute([
      ':owner_id' => $ownerId,
      ':content'  => "admin/health messages dry-run {$requestId}",
      ':flags'    => 'diag_monitor',
      ':meta'     => json_encode(['request_id'=>$requestId], JSON_UNESCAPED_UNICODE),
    ]);
    $insertId = (int)$pdo->lastInsertId();
    $pdo->rollBack();

    $out['webhook_ok'] = $insertId > 0;
    $out['details']['webhook'] = ['dry_run_insert_id'=>$insertId,'rolled_back'=>true];
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $out['details']['webhook_error'] = $e->getMessage();
}

$out['ok'] = $out['logger_ok'] && $out['webhook_ok'];
http_response_code($out['ok'] ? 200 : 500);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
