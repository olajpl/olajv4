<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/helpers.php';
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 1);
$draw_id  = (int)($_GET['id'] ?? 0);

// Pobierz nagrody
$stmt = $pdo->prepare("
    SELECT * FROM draw_prizes
    WHERE draw_id = :draw_id AND owner_id = :oid
    ORDER BY id ASC
");
$stmt->execute(['draw_id' => $draw_id, 'oid' => $owner_id]);
$prizes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <div class="card-header">🏆 Nagrody</div>
  <div class="card-body p-0">
    <?php if ($prizes): ?>
      <table class="table table-sm table-hover mb-0">
        <thead>
          <tr>
            <th>Nazwa nagrody</th>
            <th>Ilość</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($prizes as $prize): ?>
            <tr>
              <td><?= h($prize['name'] ?: '—') ?></td>
              <td><?= (int)$prize['quantity'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="p-3 text-muted text-center">Brak nagród.</div>
    <?php endif; ?>
  </div>
</div>
