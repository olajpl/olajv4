<?php
// admin/messages/index.php — Lista konwersacji klientów w stylu FB (Olaj.pl V4)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

$ownerId = (int)($_SESSION['user']['owner_id'] ?? 0);
if ($ownerId <= 0) {
    http_response_code(403);
    exit('❌ Brak owner_id w sesji.');
}

// Ostatnia wiadomość per klient
$stmt = $pdo->prepare("
  SELECT 
    c.id   AS client_id,
    c.name AS client_name,
    c.token,
    m.content AS message,
    m.created_at,
    m.platform,
    m.direction
  FROM clients c
  JOIN messages m 
    ON m.client_id = c.id AND m.owner_id = ?
  WHERE c.owner_id = ?
    AND (m.created_at, m.id) = (
      SELECT m2.created_at, m2.id
      FROM messages m2
      WHERE m2.owner_id = ?
        AND m2.client_id = c.id
      ORDER BY m2.created_at DESC, m2.id DESC
      LIMIT 1
    )
  ORDER BY m.created_at DESC
  LIMIT 300
");

$stmt->execute([$ownerId, $ownerId, $ownerId]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Platformy (ikony)
$PLATFORM = [
    'facebook' => '🟦 Facebook',
    'instagram' => '🟪 Instagram',
    'email'    => '✉️ E-mail',
    'sms'      => '📱 SMS',
    'mobile'   => '🟩 Mobile',
    'chat'     => '⬜ Chat',
];

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function starts_with(string $hay, string $needle): bool
{
    return str_starts_with($hay, $needle);
}

$page_title = "Wiadomości";
require_once __DIR__ . '/../../layout/layout_header.php';
?>
<style>
    /* Styl FB-style (desktop-first) */
    .wrap {
        max-width: 1200px;
        margin: 0 auto;
        padding: 1rem;
    }

    .card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: .75rem;
        overflow: hidden;
    }

    .card-head {
        padding: .75rem 1rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        gap: .75rem;
    }

    .search {
        flex: 1;
        display: flex;
        align-items: center;
        gap: .5rem;
        border: 1px solid #e5e7eb;
        border-radius: 999px;
        padding: .4rem .75rem;
    }

    .list {
        max-height: calc(100vh - 9rem);
        overflow: auto;
    }

    .row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        padding: .75rem 1rem;
        border-bottom: 1px solid #f1f5f9;
    }

    .row:hover {
        background: #f8fafc;
    }

    .client {
        font-weight: 600;
        color: #111827;
    }

    .snippet {
        font-size: .875rem;
        color: #4b5563;
        max-width: 55ch;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .meta {
        text-align: right;
        font-size: .75rem;
        color: #6b7280;
        white-space: nowrap;
    }

    .tag {
        display: inline-block;
        font-size: .75rem;
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        color: #374151;
        padding: .1rem .5rem;
        border-radius: 999px;
    }
</style>

<div class="wrap">
    <h1 class="text-2xl font-bold mb-4">💬 Wiadomości klientów</h1>

    <div class="card">
        <div class="card-head">
            <div class="search">
                🔎
                <input id="q" type="text" placeholder="Szukaj po nazwie, wiadomości, platformie…" class="w-full outline-none border-0" oninput="filterRows()">
            </div>
            <div class="hidden md:block text-sm text-gray-500">Łącznie: <?= count($conversations) ?></div>
        </div>

        <div id="list" class="list">
            <?php if (!$conversations): ?>
                <div class="p-4 text-gray-500">Brak wiadomości od klientów.</div>
                <?php else: foreach ($conversations as $conv):
                    $msg = $conv['message'] ?? '';
                    $isImg = starts_with($msg, '[img]');
                    $imgUrl = $isImg ? trim(substr($msg, 5)) : null;
                    $dirIcon = $conv['direction'] === 'out' ? '📤' : '📥';
                    $plat = $PLATFORM[$conv['platform']] ?? '❔';
                    $when = date('H:i d.m.Y', strtotime($conv['created_at'] ?? ''));
                    $initials = mb_strtoupper(mb_substr($conv['client_name'] ?? 'K', 0, 1));
                ?>
                    <a href="view.php?client_id=<?= (int)$conv['client_id'] ?>" class="row conv-row"
                        data-name="<?= h($conv['client_name']) ?>" data-msg="<?= h($msg) ?>" data-plat="<?= h($conv['platform']) ?>">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-blue-100 flex items-center justify-center text-sm font-bold"><?= $initials ?></div>
                            <div class="min-w-0">
                                <div class="client"><?= h($conv['client_name']) ?></div>
                                <div class="snippet" title="<?= h($isImg ? $imgUrl : $msg) ?>">
                                    <?= $dirIcon ?>
                                    <?= $isImg ? "📷 <a class='text-blue-600 hover:underline' href='$imgUrl' target='_blank'>obrazek</a>" : h(mb_strimwidth($msg, 0, 90, '…', 'UTF-8')) ?>
                                </div>
                            </div>
                        </div>
                        <div class="meta">
                            <div class="tag"><?= h($plat) ?></div>
                            <div><?= h($when) ?></div>
                        </div>
                    </a>
            <?php endforeach;
            endif; ?>
        </div>
    </div>
</div>

<script>
    function filterRows() {
        const q = (document.getElementById('q').value || '').toLowerCase();
        document.querySelectorAll('.conv-row').forEach(row => {
            const hay = [
                row.dataset.name || '',
                row.dataset.msg || '',
                row.dataset.plat || ''
            ].join(' ').toLowerCase();
            row.style.display = hay.indexOf(q) >= 0 ? '' : 'none';
        });
    }
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php'; ?>