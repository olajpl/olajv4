<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/checkout_loader.php';

// W realu: tu możesz sprawdzić status u providera albo po prostu czekać na webhook
// i zawsze kierować na thank_you (z retry przy statusie nieopłaconym)
header("Location: /checkout/thank_you.php?token=" . urlencode((string)$checkout['token']) . "&locked=1");
exit;
