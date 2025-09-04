<?php
// admin/system/healthcheck.php
// 1. opis czynności lub funkcji
// Sprawdza środowisko: PHP, PDO, połączenie z DB, uprawnienia, shell_exec, narzędzia PDF.
// Zgłasza wynik i loguje przez logg().

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

header('Content-Type: text/html; charset=utf-8');

$rows = [];
function ok($name, $val){ return ["name"=>$name,"status"=>"OK","detail"=>$val]; }
function bad($name, $val){ return ["name"=>$name,"status"=>"FAIL","detail"=>$val]; }
function warn($name, $val){ return ["name"=>$name,"status"=>"WARN","detail"=>$val]; }

$rows[] = ok('PHP version', PHP_VERSION);
$rows[] = ok('Session', session_status() === PHP_SESSION_ACTIVE ? 'active' : 'not active');

$disabled = ini_get('disable_functions');
$rows[] = ok('disable_functions', $disabled ?: '—');

$hasShell = function_exists('shell_exec');
$rows[] = $hasShell ? ok('shell_exec', 'enabled') : warn('shell_exec', 'disabled');

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
  $rows[] = ok('MySQL connect', 'OK ('.$ver.')');
  $test = $pdo->query('SELECT 1')->fetchColumn();
  $rows[] = ok('SELECT 1', (string)$test);
} catch (Throwable $e) {
  $rows[] = bad('DB connect', $e->getMessage());
}

$logDir = __DIR__ . '/../../storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$canWrite = is_writable($logDir);
$rows[] = $canWrite ? ok('storage/logs writable', 'yes') : bad('storage/logs writable', 'no');

$pdftotext = $hasShell ? trim((string)@shell_exec('command -v pdftotext')) : '';
$rows[] = $pdftotext ? ok('pdftotext', $pdftotext) : warn('pdftotext', 'not found');

$tesseract = $hasShell ? trim((string)@shell_exec('command -v tesseract')) : '';
$rows[] = $tesseract ? ok('tesseract', $tesseract) : warn('tesseract', 'not found');

logg('info','system.healthcheck','Healthcheck run',[
  'shell_exec'=>$hasShell,'pdftotext'=>$pdftotext?true:false,'tesseract'=>$tesseract?true:false,'writable_logs'=>$canWrite
]);
?>
<!doctype html><meta charset="utf-8">
<title>Healthcheck</title>
<style>
body{font-family:ui-sans-serif,system-ui;max-width:900px;margin:24px auto;padding:0 16px}
table{width:100%;border-collapse:collapse}
td,th{padding:10px;border-bottom:1px solid #eee;text-align:left}
.bad{color:#b91c1c}.ok{color:#047857}.warn{color:#b45309}
</style>
<h1>Healthcheck</h1>
<table>
  <thead><tr><th>Test</th><th>Status</th><th>Szczegóły</th></tr></thead>
  <tbody>
  <?php foreach($rows as $r): ?>
    <tr>
      <td><?=htmlspecialchars($r['name'])?></td>
      <td class="<?=strtolower($r['status'])?>"><?=htmlspecialchars($r['status'])?></td>
      <td><code><?=htmlspecialchars(is_scalar($r['detail'])?$r['detail']:json_encode($r['detail']))?></code></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
