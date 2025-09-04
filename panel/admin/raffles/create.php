<?php
// admin/raffles/create.php — formularz tworzenia losowania (widok)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/log.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 1);

// Załaduj listę LIVE (opcjonalnie)
$live = [];
try {
    $st = $pdo->prepare("SELECT id, COALESCE(NULLIF(TRIM(title),''), CONCAT('LIVE #',id)) AS title FROM live_streams WHERE owner_id=:oid ORDER BY id DESC");
    $st->execute([':oid' => $ownerId]);
    $live = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $__) { /* brak tabeli? Trudno, pole będzie ukryte */
}

require_once __DIR__ . '/../../layout/layout_header.php';
?>
<div class="max-w-2xl mx-auto p-4">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">🎲 Nowe losowanie</h1>
        <a href="index.php" class="text-blue-600 hover:underline">← Wróć</a>
    </div>

    <form method="post" action="api/create.php" class="space-y-4">
        <?php
        // prościutki CSRF (jeśli nie masz globalnego)
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        ?>
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">

        <div>
            <label class="block text-sm font-medium mb-1">Tytuł *</label>
            <input name="title" required class="w-full border rounded px-3 py-2" placeholder="np. Giveaway z LIVE #42">
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Opis</label>
            <textarea name="description" rows="3" class="w-full border rounded px-3 py-2" placeholder="Krótki opis, zasady, itp."></textarea>
        </div>

        <?php if (!empty($live)): ?>
            <div>
                <label class="block text-sm font-medium mb-1">Powiązany LIVE</label>
                <select name="live_stream_id" class="w-full border rounded px-3 py-2">
                    <option value="">— brak —</option>
                    <?php foreach ($live as $l): ?>
                        <option value="<?= (int)$l['id'] ?>"><?= h($l['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">Duplikaty</label>
                <select name="allow_duplicates" class="w-full border rounded px-3 py-2">
                    <option value="0">Nie — 1 los na uczestnika</option>
                    <option value="1">Tak — pozwól na wiele losów</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Cooldown (dni)</label>
                <input name="cooldown_days" type="number" min="0" value="7" class="w-full border rounded px-3 py-2">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Słowo kluczowe (opcjonalnie)</label>
            <input name="keyword" class="w-full border rounded px-3 py-2" placeholder="np. #giveaway">
        </div>

        <div class="pt-2">
            <button class="inline-flex items-center rounded px-4 py-2 bg-blue-600 text-white hover:bg-blue-700">
                ✅ Utwórz losowanie
            </button>
        </div>
    </form>
</div>
<?php require_once __DIR__ . '/../../layout/layout_footer.php';
