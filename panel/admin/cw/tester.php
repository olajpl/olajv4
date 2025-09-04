<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';

echo "<h1>CW Tester</h1>
<form method='post' action='api/render_preview.php'>
Event: <input name='event_key'><br>
Kanał: <select name='channel'><option>sms</option><option>messenger</option><option>email</option></select><br>
Client ID: <input name='client_id'><br>
Order ID: <input name='order_id'><br>
Tracking URL: <input name='tracking_url'><br>
<button>Preview</button>
</form>";

$rows = $pdo->query("SELECT id,owner_id,client_id,event_key,channel,status,sent_at FROM messages ORDER BY id DESC LIMIT 20")->fetchAll();
echo "<h2>Ostatnie wiadomości</h2><table border=1><tr><th>ID</th><th>Event</th><th>Kanał</th><th>Status</th><th>Sent</th></tr>";
foreach ($rows as $r) {
    echo "<tr><td>{$r['id']}</td><td>{$r['event_key']}</td><td>{$r['channel']}</td><td>{$r['status']}</td><td>{$r['sent_at']}</td></tr>";
}
echo "</table>";
require_once __DIR__ . '/../../layout/layout_footer.php';
