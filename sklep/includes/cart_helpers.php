<?php
function getCartSessionId(): string {
    if (!isset($_COOKIE['cart_sid']) || !preg_match('/^[a-z0-9]{32}$/', $_COOKIE['cart_sid'])) {
        $sid = bin2hex(random_bytes(16));
        setcookie('cart_sid', $sid, time() + 60*60*24*30, '/', '', false, true);
        $_COOKIE['cart_sid'] = $sid;
    }
    return $_COOKIE['cart_sid'];
}

function getClientToken(): ?string {
    // jeśli masz swój mechanizm – podłącz tu
    return $_SESSION['client_token'] ?? null;
}
