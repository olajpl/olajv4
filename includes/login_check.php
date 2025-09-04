<?php

declare(strict_types=1);
session_start();

if (empty($_SESSION['user']) || !isset($_SESSION['user']['id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
    header("Location: /auth/login.php?redirect=$redirect");
    exit;
}
