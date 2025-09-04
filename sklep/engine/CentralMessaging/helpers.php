<?php
// engine/centralMessaging/helpers.php

declare(strict_types=1);

use CentralMessaging\Cw;

require_once __DIR__ . '/Cw.php';
require_once __DIR__ . '/CwTemplateResolver.php';
require_once __DIR__ . '/../../includes/log.php';

/**
 * Wyślij prostą wiadomość z linkiem do checkoutu
 */
logg('debug', 'cw_helper', 'entered', ['client_id' => $client_id]);

function sendAutoReplyCheckoutWithToken(
    PDO $pdo,
    int $client_id,
    string $checkout_token,
    string $product_name,
    int $qty,
    float $price,
    int $owner_id
    
): void {
    logg('debug', 'cw_helper', 'entered', ['client_id' => $client_id]); // ← TU!

    $text = "✅ Dodano do paczki: {$product_name} ×{$qty}\n📦 Sprawdź: https://olaj.pl/checkout/{$checkout_token}";

    $msg_id = Cw::enqueue($pdo, [
        'owner_id'           => $owner_id,
        'client_id'          => $client_id,
        'channel'            => 'messenger',
        'context'            => 'parser_daj',
        'event_key'          => 'checkout_token',
        'platform_thread_id' => getPlatformThreadIdForClient($pdo, $client_id, $platform),
        'body_text'          => $text,
        'direction'          => 'out',
    ]);

    $pdo->prepare("UPDATE messages SET sender_type='system' WHERE id = ?")
        ->execute([$msg_id]);

    Cw::trySend($pdo, $msg_id);

    logg('info', 'parser_daj', 'reply.sent', [
        'message_id' => $msg_id,
        'client_id'  => $client_id,
        'token'      => $checkout_token,
        'text'       => $text,
    ]);
}

/**
 * Znajdź PSID klienta (Messenger thread ID)
 */
function getPlatformThreadIdForClient(PDO $pdo, int $client_id, string $platform = 'facebook'): ?string {
    $q = $pdo->prepare("SELECT platform_id FROM client_platform_ids WHERE client_id = ? AND platform = ? LIMIT 1");
    $q->execute([$client_id, $platform]);
    return $q->fetchColumn() ?: null;
}


/**
 * Wyślij odpowiedź z szablonu CW (jeśli istnieje)
 */
function sendCwTemplateIfExists(
    PDO $pdo,
    int $owner_id,
    int $client_id,
    string $event_key,
    array $replacements = []
): void {
    $platform_id = getPlatformThreadIdForClient($pdo, $client_id);
    if (!$platform_id) return;

    $text = CentralMessaging\CwTemplateResolver::resolveText($pdo, $owner_id, $event_key, 'messenger', $replacements);
    if (!$text) return;

    $msg_id = Cw::enqueue($pdo, [
        'owner_id'           => $owner_id,
        'client_id'          => $client_id,
        'channel'            => 'messenger',
        'context'            => 'auto_reply',
        'event_key'          => $event_key,
        'platform_thread_id' => $platform_id,
        'body_text'          => $text,
        'direction'          => 'out',
    ]);

    $pdo->prepare("UPDATE messages SET sender_type='system' WHERE id = ?")
        ->execute([$msg_id]);

    Cw::trySend($pdo, $msg_id);

    logg('info', 'cw', 'template.sent', [
        'client_id'   => $client_id,
        'event_key'   => $event_key,
        'message_id'  => $msg_id,
    ]);
}
function generate_client_token(int $owner_id): string {
    global $pdo;

    do {
        $token = 'olaj' . random_int(1000, 9999);
        $stmt  = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE token = :token");
        $stmt->execute([':token' => $token]);
        $exists = (int)$stmt->fetchColumn() > 0;
    } while ($exists);

    return $token;
}
