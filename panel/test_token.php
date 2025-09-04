<?php
require_once __DIR__ . '/includes/db.php';         // ⬅️ to jest kluczowe – inicjuje $pdo
require_once __DIR__ . '/includes/helpers.php';

echo generate_client_token(1);