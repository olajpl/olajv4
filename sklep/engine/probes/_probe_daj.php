<?php
// engine/probes/_probe_daj.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../Parser/Handlers/DajHandler.php';

use Engine\Parser\Handlers\DajHandler;

header('Content-Type: application/json; charset=utf-8');

try {
    $ownerId  = (int)($_GET['owner_id'] ?? 1);
    $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
    $psid     = isset($_GET['psid']) ? (string)$_GET['psid'] : '';
    $text     = (string)($_GET['text'] ?? 'daj 001 x2');

    // Jeśli nie ma client_id, spróbuj z PSID (mapuj lub utwórz klienta)
    if ($clientId <= 0 && $psid !== '') {
        $st = $pdo->prepare("
            SELECT client_id
            FROM client_platform_ids
            WHERE owner_id = :oid
              AND platform IN ('messenger','facebook')
              AND platform_user_id = :psid
            LIMIT 1
        ");
        $st->execute([':oid' => $ownerId, ':psid' => $psid]);
        $clientId = (int)($st->fetchColumn() ?: 0);

        if ($clientId <= 0) {
            // auto-create minimal client + mapping
            $pdo->beginTransaction();
            try {
                $pdo->prepare("
                    INSERT INTO clients (owner_id, token, registered_at)
                    VALUES (:oid, CONCAT('olaj-', LOWER(HEX(RANDOM_BYTES(8)))), NOW())
                ")->execute([':oid' => $ownerId]);
                $clientId = (int)$pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO client_platform_ids (client_id, owner_id, platform, platform_user_id, created_at)
                    VALUES (:cid, :oid, 'messenger', :psid, NOW())
                ")->execute([':cid' => $clientId, ':oid' => $ownerId, ':psid' => $psid]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }

    if ($clientId <= 0) {
        throw new RuntimeException('Brak client_id. Dodaj ?client_id=123 albo ?psid=PSID (zrobię mapping).');
    }

    // Sygnatura DajHandler: (PDO, int $ownerId, int $clientId, string $text)
    $res = DajHandler::handle($pdo, $ownerId, $clientId, $text);

    echo json_encode([
        'status' => 'ok',
        'input'  => compact('ownerId','clientId','psid','text'),
        'result' => $res,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error'  => $e->getMessage(),
        'file'   => $e->getFile(),
        'line'   => $e->getLine(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
