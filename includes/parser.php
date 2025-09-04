<?php
require_once __DIR__ . '/parser/handle_pomoc.php';
require_once __DIR__ . '/parser/handle_daj.php';
require_once __DIR__ . '/parser/handle_waitlist.php';
require_once __DIR__ . '/parser/handle_zamknij.php';

function parse_message($owner_id, $platform, $platform_id, $text)
{
    $logFile = __DIR__ . '/../logs/webhook_log.txt';
    file_put_contents($logFile, "\n🧠 [parse_message] START\n", FILE_APPEND);
    file_put_contents($logFile, "🔢 Parametry: owner_id=$owner_id | platform=$platform | platform_id=$platform_id | text=$text\n", FILE_APPEND);

    if (!is_string($text) || trim($text) === '') {
        file_put_contents($logFile, "⚠️ Pusta wiadomość\n", FILE_APPEND);
        return ['error' => 'Pusta wiadomość'];
    }

    $text = trim(mb_strtolower($text));
    file_put_contents($logFile, "✂️ Po normalizacji tekst: $text\n", FILE_APPEND);

    // 🆘 POMOC
    if (
        str_contains($text, '#pomoc') ||
        preg_match('/\b(pomoc|jak|zamówić|co mogę|gdzie.*paczka|jak kupić|kontakt)\b/i', $text)
    ) {
        file_put_contents($logFile, "🆘 Rozpoznano komendę POMOC\n", FILE_APPEND);
        $result = handle_pomoc($owner_id, $platform_id);
        file_put_contents($logFile, "📤 Wynik handle_pomoc: " . json_encode($result) . "\n", FILE_APPEND);
        return $result;
    }

    // 🧠 DAJ
if (preg_match('/^daj\s*(\d{3,})(?:\s*[+x×]\s*(\d+))?$/i', $text, $matches)) {
    $code = $matches[1];
    $qty = isset($matches[2]) ? (int)$matches[2] : 1;
    $result = handle_daj($owner_id, $platform_id, $text, $platform);
    file_put_contents($logFile, "📤 Wynik handle_daj: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    return $result;
}
// 🧳 ZAMKNIJ
if (
    str_contains($text, '#zamknij') ||
    preg_match('/\b(zamknij|koniec|skończone|to wszystko|zamyka)\b/i', $text)
) {
    file_put_contents($logFile, "🧳 Rozpoznano komendę ZAMKNIJ\n", FILE_APPEND);
    $result = handle_zamknij($owner_id, $platform_id);
    file_put_contents($logFile, "📤 Wynik handle_zamknij: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    return $result;
}

    // ❓ Nic nie pasuje
    file_put_contents($logFile, "❓ Nie rozpoznano komendy\n", FILE_APPEND);
    return ['error' => 'Nie rozpoznano wiadomości.'];
}
