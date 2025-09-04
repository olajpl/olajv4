<?php
function handle_status(PDO $pdo, int $client_id, int $owner_id): array
{
    $stmt = $pdo->prepare("SELECT p.name, i.quantity FROM orders o
        JOIN order_groups g ON g.order_id = o.id
        JOIN order_items i ON i.order_group_id = g.id
        LEFT JOIN products p ON p.id = i.product_id
        WHERE o.client_id = :client_id AND o.owner_id = :owner_id AND o.status = 'nowe'");
    $stmt->execute([
        ':client_id' => $client_id,
        ':owner_id' => $owner_id
    ]);

    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        sendClientMessage($client_id, "🔍 Obecnie nie masz żadnych produktów w swojej paczce.");
        return ['info' => 'Brak pozycji w paczce'];
    }

    $summary = "📦 *Twoja paczka:*\n";
    foreach ($items as $item) {
        $summary .= "• {$item['name']} x {$item['quantity']}\n";
    }
    sendClientMessage($client_id, $summary);
    return ['success' => true, 'info' => 'Wysłano status paczki'];
}
