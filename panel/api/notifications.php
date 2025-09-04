<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$notifications = [];

// 🆕 Ostatnie zamówienie
$st = $pdo->prepare("
    SELECT o.id, c.name 
    FROM orders o
    LEFT JOIN clients c ON o.client_id = c.id
    WHERE o.owner_id = :owner_id
    ORDER BY o.created_at DESC
    LIMIT 1
");
$st->execute(['owner_id' => $owner_id]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if ($order) {
    $notifications[] = "🆕 Nowe zamówienie od " . ($order['name'] ?: ('ID #' . $order['id']));
}

// 💬 Ostatnia wiadomość
$st = $pdo->prepare("
    SELECT c.name 
    FROM messages m
    LEFT JOIN clients c ON m.client_id = c.id
    WHERE m.owner_id = :owner_id
    ORDER BY m.created_at DESC
    LIMIT 1
");
$st->execute(['owner_id' => $owner_id]);
$message = $st->fetch(PDO::FETCH_ASSOC);

if ($message) {
    $notifications[] = "💬 Nowa wiadomość od " . ($message['name'] ?: 'nieznany klient');
}

// 👤 Ostatni klient (V2: registered_at → fallback updated_at → fallback last_seen)
$st = $pdo->prepare("
    SELECT name
    FROM clients
    WHERE owner_id = :owner_id
    ORDER BY registered_at DESC
    LIMIT 1
");
$st->execute(['owner_id' => $owner_id]);
$client = $st->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    $st = $pdo->prepare("
        SELECT name
        FROM clients
        WHERE owner_id = :owner_id
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $st->execute(['owner_id' => $owner_id]);
    $client = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$client) {
    $st = $pdo->prepare("
        SELECT name
        FROM clients
        WHERE owner_id = :owner_id
        ORDER BY last_seen DESC
        LIMIT 1
    ");
    $st->execute(['owner_id' => $owner_id]);
    $client = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($client) {
    $notifications[] = "👤 Nowy klient: " . $client['name'];
}

// Zwracamy wynik
echo json_encode($notifications, JSON_UNESCAPED_UNICODE);
