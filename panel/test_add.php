<?php
// tester.php — CW tester dla szablonów (Olaj.pl V4)
declare(strict_types=1);

use Engine\Log\LogEngine;
use Engine\CentralMessaging\Cw;
use Engine\Notifications\NotificationEngine;

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/log.php';
require_once __DIR__ . '/engine/CentralMessaging/Cw.php';
require_once __DIR__ . '/engine/Notifications/NotificationEngine.php';
require_once __DIR__ . '/engine/Enum/NotificationEvent.php';

// Używamy $pdo z includes/db.php

$templateId = (int)($_GET['template_id'] ?? $_POST['template_id'] ?? 0);
$clientId   = (int)($_POST['client_id'] ?? 0);
$testMode   = isset($_POST['send_test']);

$template = null;
if ($templateId) {
    $stmt = $pdo->prepare("SELECT * FROM cw_templates WHERE id = ?");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
}

$testPayloadJson = $template['test_payload'] ?? '{}';
$rendered = null;
$error = null;

// Jeśli wysyłamy testowo
if ($testMode && $template && $clientId) {
    try {
        $payload = json_decode($testPayloadJson, true, 512, JSON_THROW_ON_ERROR);
        $payload['client_id'] = $clientId;

        $cw   = new Cw($pdo);
        $log = \Engine\Log\LogEngine::instance($pdo, (int)$template['owner_id']); // ✅ Konstruktor prywatny → używamy instance()
        $noti = new NotificationEngine($pdo, $cw, $log);

        $noti->dispatch([
            'owner_id'  => (int)$template['owner_id'],
            'event_key' => $template['event_key'],
            'context'   => $payload,
        ]);

        $rendered = "✅ Wiadomość testowa została wysłana.";
    } catch (Throwable $e) {
        $error = 'Błąd wysyłki: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Tester CW</title>
    <style>
        body { font-family: sans-serif; margin: 2rem; }
        textarea { width: 100%; height: 180px; font-family: monospace; }
        pre { background: #f3f3f3; padding: 1rem; border-radius: 5px; }
    </style>
</head>
<body>

<h2>🔬 Tester szablonów CW</h2>

<form method="get">
    <label>Wybierz Template ID: <input type="number" name="template_id" value="<?= htmlspecialchars((string)$templateId) ?>" /></label>
    <button type="submit">Załaduj</button>
</form>

<?php if ($template): ?>
    <hr>
    <h3>Szablon: <?= htmlspecialchars($template['template_name'] ?? '⛔ brak') ?></h3>
    <p><strong>event_key:</strong> <?= htmlspecialchars($template['event_key']) ?></p>
    <p><strong>kanał:</strong> <?= htmlspecialchars($template['channel']) ?></p>

    <form method="post">
        <input type="hidden" name="template_id" value="<?= $templateId ?>">
        <label>Testowy Client ID: <input type="number" name="client_id" required /></label><br><br>
        <label>Payload (JSON):</label><br>
        <textarea name="test_payload"><?= htmlspecialchars($testPayloadJson) ?></textarea><br><br>
        <button name="send_test" type="submit">📤 Wyślij testowo</button>
    </form>

    <?php if ($rendered): ?>
        <p style="color:green;"><strong><?= htmlspecialchars($rendered) ?></strong></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p style="color:red;"><strong><?= htmlspecialchars($error) ?></strong></p>
    <?php endif; ?>

    <hr>
    <h4>Podgląd tekstu:</h4>
    <pre><?= htmlspecialchars($template['body_text'] ?? '[Brak body_text]') ?></pre>
<?php endif; ?>

</body>
</html>
