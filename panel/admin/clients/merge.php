<?php
// /admin/clients/merge.php
// 1. opis czynnosci lub funkcji
// - Scala dwa konta klienta w Olaj V4 (soft-merge).
// - Ustalany jest root-master (jeśli wskazany master sam jest aliasem, bierzemy jego mastera).
// - Ustawia clients.master_client_id dla duplikatu + wszystkich aliasów wskazujących na duplikat.
// - Jeśli master nie ma client_info, przenosi client_info z duplikatu (UPDATE client_info.client_id).
// - Uzupełnia puste pola mastera (name/email/phone) danymi z duplikatu (jeśli master ma NULL).
// - Logowanie przez centralny logger (olaj_v4_logger).
// - Zwraca JSON.

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

// CSRF
$csrf = $_POST['csrf'] ?? '';
if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'CSRF']);
  exit;
}

$ownerId   = (int)($_SESSION['user']['owner_id'] ?? 0);
$operator  = (int)($_SESSION['user']['id'] ?? 0);
$dupId     = (int)($_POST['duplicate_id'] ?? 0); // ID duplikatu
$masterId  = (int)($_POST['master_id'] ?? 0);    // ID klienta docelowego (może być alias — weźmiemy root)

// flaga: przenieś client_info jeżeli master nie ma (domyślnie: tak)
$moveClientInfoIfMissing = (isset($_POST['move_client_info']) ? (int)$_POST['move_client_info'] : 1) === 1;

if ($ownerId <= 0)                 { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Brak kontekstu właściciela']); exit; }
if ($dupId <= 0 || $masterId <= 0) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Podaj poprawne ID duplikatu i mastera']); exit; }
if ($dupId === $masterId)          { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Nie można scalić klienta z samym sobą']); exit; }

try {
  // Pomocnicze: odczyt klienta
  $getClient = function(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT id, owner_id, master_client_id, name, email, phone FROM clients WHERE id = :id LIMIT 1");
    $stmt->execute(['id'=>$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  };

  // Znajdź root-mastera (id bez master_client_id)
  $resolveRoot = function(PDO $pdo, int $id) use ($getClient): int {
    $seen = [];
    $currentId = $id;
    while (true) {
      if (isset($seen[$currentId])) {
        // pętla — awaryjnie przerwij
        return $currentId;
      }
      $seen[$currentId] = true;
      $c = $getClient($pdo, $currentId);
      if (!$c || empty($c['master_client_id'])) {
        return $currentId; // brak mastera => root
      }
      $currentId = (int)$c['master_client_id'];
    }
  };

  $dup    = $getClient($pdo, $dupId);
  $master = $getClient($pdo, $masterId);

  if (!$dup || !$master) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Nie znaleziono klienta (dup/master)']); exit; }
  if ((int)$dup['owner_id'] !== $ownerId || (int)$master['owner_id'] !== $ownerId) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Klienci nie należą do tego samego właściciela']); exit;
  }

  // Ustal root mastera dla wskazanego masterId
  $rootMasterId = $resolveRoot($pdo, $masterId);
  if ($rootMasterId === $dupId) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'Wybrany master jest aliasem duplikatu — niedozwolone']);
    exit;
  }

  // Zabezpiecz: duplikat nie może wskazywać (bezpośrednio/pośrednio) na siebie po operacji
  // (resolveRoot dla duplikatu)
  $dupRoot = $resolveRoot($pdo, $dupId);
  if ($dupRoot === $rootMasterId && $dup['master_client_id'] !== null) {
    // teoretycznie ok — duplikat i tak wpada pod tego samego root'a, ale wymuś bezpośrednie wskazanie na rootMaster
  }

  $pdo->beginTransaction();

  // 1) Uzupełnij puste pola kontaktowe u root-mastera danymi z duplikatu (tylko NULL -> wartość)
  $fieldsToFill = [];
  $params = ['id' => $rootMasterId];
  if (empty($master['name']) && !empty($dup['name']))   { $fieldsToFill[] = 'name = :name';   $params['name'] = $dup['name']; }
  if (empty($master['email']) && !empty($dup['email'])) { $fieldsToFill[] = 'email = :email'; $params['email'] = $dup['email']; }
  if (empty($master['phone']) && !empty($dup['phone'])) { $fieldsToFill[] = 'phone = :phone'; $params['phone'] = $dup['phone']; }
  if ($fieldsToFill) {
    $sql = "UPDATE clients SET " . implode(', ', $fieldsToFill) . " WHERE id = :id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
  }

  // 2) Jeśli master nie ma client_info, a duplikat ma — przenieś client_info na mastera
  if ($moveClientInfoIfMissing) {
    $hasMasterCI = (function(PDO $pdo, int $cid): bool {
      $s = $pdo->prepare("SELECT 1 FROM client_info WHERE client_id = :cid LIMIT 1");
      $s->execute(['cid'=>$cid]);
      return (bool)$s->fetchColumn();
    })($pdo, $rootMasterId);

    if (!$hasMasterCI) {
      // sprawdź, czy duplikat ma client_info
      $hasDupCI = (function(PDO $pdo, int $cid): bool {
        $s = $pdo->prepare("SELECT 1 FROM client_info WHERE client_id = :cid LIMIT 1");
        $s->execute(['cid'=>$cid]);
        return (bool)$s->fetchColumn();
      })($pdo, $dupId);

      if ($hasDupCI) {
        // UWAGA: client_info ma PK = client_id, więc zwykłe UPDATE wystarczy (zmienia PK)
        $stmt = $pdo->prepare("UPDATE client_info SET client_id = :masterId WHERE client_id = :dupId");
        $stmt->execute(['masterId'=>$rootMasterId, 'dupId'=>$dupId]);
      }
    }
  }

  // 3) Przełącz wszystkie aliasy wskazujące na duplikat -> na rootMaster (porządkowanie łańcucha)
  $stmt = $pdo->prepare("UPDATE clients SET master_client_id = :master WHERE master_client_id = :dup");
  $stmt->execute(['master'=>$rootMasterId, 'dup'=>$dupId]);

  // 4) Ustaw master_client_id duplikatu bezpośrednio na rootMaster
  $stmt = $pdo->prepare("UPDATE clients SET master_client_id = :master WHERE id = :dup LIMIT 1");
  $stmt->execute(['master'=>$rootMasterId, 'dup'=>$dupId]);

  // 5) Logi
  logg('info', 'clients', 'merge.start', [
    'duplicate_id' => $dupId,
    'master_param' => $masterId,
    'root_master'  => $rootMasterId,
    'operator_id'  => $operator,
    'owner_id'     => $ownerId
  ]);

  $pdo->commit();

  // 6) Podsumowanie
  $summary = [
    'merged_duplicate_id' => $dupId,
    'root_master_id'      => $rootMasterId,
    'moved_client_info'   => ($moveClientInfoIfMissing ? 'auto_if_missing' : 'no'),
    'filled_fields'       => array_values(array_map(fn($s)=>explode('=', $s)[0], $fieldsToFill ?: [])),
  ];

  logg('info', 'clients', 'merge.done', $summary);

  echo json_encode(['ok'=>true, 'summary'=>$summary]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  logg('error', 'clients', 'merge.failed', [
    'error' => $e->getMessage(),
    'dup'   => $dupId,
    'master'=> $masterId,
    'owner' => $ownerId
  ]);
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'Merge failed', 'message'=>$e->getMessage()]);
}
