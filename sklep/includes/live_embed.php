<?php
// shop/includes/live_embed.php
function liveEmbedHtml(string $platform, string $streamUrl): string
{
    $platform = strtolower(trim($platform));
    $u = trim($streamUrl);

    // === YOUTUBE ===
    if ($platform === 'youtube') {
        // 1) konkretne video
        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/live/)([A-Za-z0-9_-]{6,})~i', $u, $m)) {
            $vid = $m[1];
            $src = "https://www.youtube-nocookie.com/embed/{$vid}?autoplay=1&mute=1";
            return '<iframe src="' . $src . '" width="100%" height="100%" title="YouTube live" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
        }
        // 2) kanał – ciągły live
        if (preg_match('~youtube\.com/(?:@[^/]+|channel/([A-Za-z0-9_-]+))~i', $u, $m)) {
            $chan = $m[1] ?? null;
            // jeśli adres w formie @handle, zostawiamy usera – embed kanałowy wymaga channelId, więc pokaż fallback:
            if ($chan) {
                $src = "https://www.youtube-nocookie.com/embed/live_stream?channel={$chan}&autoplay=1&mute=1";
                return '<iframe src="' . $src . '" width="100%" height="100%" title="YouTube live" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
            }
        }
        // fallback – jeśli nic nie rozpoznaliśmy, wstaw po prostu watch w nocookie, YT sobie poradzi albo pokaże błąd
        $src = "https://www.youtube-nocookie.com/embed/live_stream?autoplay=1&mute=1";
        return '<iframe src="' . $src . '" width="100%" height="100%" title="YouTube" frameborder="0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>';
    }

    // === FACEBOOK ===
    if ($platform === 'facebook') {
        // działa zarówno z pełnym linkiem do wideo, jak i linkiem do posta/live
        $href = urlencode($u);
        $src  = "https://www.facebook.com/plugins/video.php?href={$href}&show_text=false&autoplay=1&mute=1";
        return '<iframe src="' . $src . '" width="100%" height="100%" style="border:none;overflow:hidden" scrolling="no" frameborder="0" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share" allowfullscreen="true"></iframe>';
    }

    // === TIKTOK ===
    if ($platform === 'tiktok') {
        // Najstabilniej skorzystać z oficjalnego skryptu oEmbed
        $safe = htmlspecialchars($u, ENT_QUOTES);
        return '<blockquote class="tiktok-embed" cite="' . $safe . '" data-autoplay="true" data-muted="true" style="max-width:100%;min-width:300px;"><section></section></blockquote><script async src="https://www.tiktok.com/embed.js"></script>';
    }

    return ''; // nieobsługiwana platforma
}
