<?php
function fb_send_message(string $recipient_id, string $message, string $page_token): string
{
    $url = "https://graph.facebook.com/v18.0/me/messages?access_token=" . urlencode($page_token);

    $payload = [
        'recipient' => ['id' => $recipient_id],
        'message'   => ['text' => $message]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "❌ CURL ERROR: $error\n", FILE_APPEND);
    } else {
        file_put_contents(__DIR__ . '/../logs/webhook_log.txt', "📬 fb_send_message response: $response\n", FILE_APPEND);
    }

    curl_close($ch);

    return $response ?: '';
}
