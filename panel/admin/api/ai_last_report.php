<?php
// admin/api/ai_last_report.php — zwraca ostatni raport AI (owner-safe, JSON clean)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Nagłówek ustawiamy od razu, zanim cokolwiek potencjalnie wypluje output
header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
    if ($ownerId <= 0) {
        echo json_encode(['error' => 'owner_not_set'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Baza może nie mieć jeszcze tabeli — w takim wypadku zwróć pusty obiekt
    $chk = $pdo->query("SHOW TABLES LIKE 'ai_reports'");
    if (!$chk || $chk->rowCount() === 0) {
        echo json_encode(['data' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Bierzemy najnowszy raport (preferencyjnie 'ready'), z aliasem data_json
    $sql = "
        SELECT
          id, owner_id, user_id, context, scope, ref_id, status,
          title, summary, content, insights_json, metrics_json, error_msg,
          flags, request_id, created_at, updated_at, deleted_at,
          COALESCE(insights_json, metrics_json, JSON_OBJECT()) AS data_json
        FROM ai_reports
        WHERE owner_id = :owner_id AND (deleted_at IS NULL)
        ORDER BY (status = 'ready') DESC, created_at DESC, id DESC
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute(['owner_id' => $ownerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['data' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Bezpieczne dekodowanie data_json (może być NULL lub string)
    $dataArr = null;
    if (isset($row['data_json']) && $row['data_json'] !== null && $row['data_json'] !== '') {
        $decoded = json_decode((string)$row['data_json'], true);
        if (is_array($decoded)) {
            $dataArr = $decoded;
        }
    }

    // Zbuduj ładną odpowiedź; NIE odwołujemy się do nieistniejącego $row['data']
    $resp = [
        'id'           => (int)$row['id'],
        'owner_id'     => (int)$row['owner_id'],
        'user_id'      => $row['user_id'] !== null ? (int)$row['user_id'] : null,
        'context'      => (string)$row['context'],
        'scope'        => (string)$row['scope'],
        'ref_id'       => $row['ref_id'] !== null ? (int)$row['ref_id'] : null,
        'status'       => (string)$row['status'],
        'title'        => $row['title'],
        'summary'      => $row['summary'],
        'content'      => $row['content'],
        'insights_json' => $row['insights_json'],
        'metrics_json' => $row['metrics_json'],
        'error_msg'    => $row['error_msg'],
        'flags'        => $row['flags'],
        'request_id'   => $row['request_id'],
        'created_at'   => $row['created_at'],
        'updated_at'   => $row['updated_at'],
        'deleted_at'   => $row['deleted_at'],
        'data'         => $dataArr, // ← tutaj masz gotową tablicę z wnioskami
    ];

    echo json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    // Odpowiedź błędu w JSON (bez wysypywania warningów do outputu)
    http_response_code(500);
    echo json_encode([
        'error' => 'internal_error',
        'msg'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
