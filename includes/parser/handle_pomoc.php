<?php
// includes/parser/handle_pomoc.php

function handle_pomoc($owner_id, $platform_id) {
    return [
        'reply' => "👋 Hej! Oto jak możesz złożyć zamówienie:\n\n"
            . "🛒 Napisz `daj 1234+2` aby dodać 2 sztuki produktu o kodzie 1234\n"
            . "📦 Swoje zamówienia sprawdzisz tu: https://olaj.pl/moje.php\n"
            . "❓ W razie pytań – napisz, jesteśmy tu dla Ciebie 💬"
    ];
}
