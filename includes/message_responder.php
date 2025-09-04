<?php
// includes/facebook.php

function fb_send_message($recipient_id, $text, $page_token) {
    $payload = [
        'recipient' => ['id' => $recipient_id],
        'message' => ['text' => $text]
    ];

    $ch = curl_init('https://graph.facebook.com/v18.0/me/messages?access_token=' . $page_token);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}
