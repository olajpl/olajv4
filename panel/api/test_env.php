<?php
require_once __DIR__ . '/../includes/env.php';
echo "FB_APP_ID=" . env('FB_APP_ID', '(brak)') . "\n";
echo "FB_APP_SECRET=" . (env('FB_APP_SECRET') ? '***' : '(brak)') . "\n";
