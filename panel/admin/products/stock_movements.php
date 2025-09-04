<?php
// admin/products/stock_movements.php — Historia ruchów magazynowych (Olaj V4, schema V2)
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../layout/layout_header.php';
require_once __DIR__ . '/../../layout/top_panel.php';

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);

// ── Filtry ─────────────────────────────────────────────────────
$q         = trim((string)($_GET['q'] ?? ''));               // nazwa/kod produktu
$type      = trim((string)($_GET['type'] ?? ''));            // in|out|adjust|return
$source    = trim((string)($_GET['source'] ?? ''));          // manual|live|order|admin|import
$range     = trim((string)($_GET['range'] ?? '7dni'));
$start     = trim((string)($_GET['start'] ?? ''));
$end       = trim((string)($_GET['end'] ?? ''));
$limitIn   = (string)($_GET['limit'] ?? '200');
$limit     = in_array($limitIn, ['50', '100', '200', '500'], true) ? (int)$limitIn : 200;

// Zakres dat
switch ($range) {
    case 'dzisiaj':
        $start = $end = date('Y-m-d');
        break;
    case 'wczoraj':
        $start = $end = date('Y-m-d', strtotime('-1 day'));
        break;
    case '7dni':
        $start = date('Y-m-d', strtotime('-7 days'));
        $end = date('Y-m-d');
        break;
    case '30dni':
        $start = date('Y-m-d', strtotime('-30 days'));
        $end = date('Y-m-d');
        break;
    case 'wszystkie':
        $start = '2000-01-01';
        $end = date('Y-m-d');
        break;
    default:
        $start = $start ?: date('Y-m-d');
        $end = $end ?: date('Y-m-d');
}

// ── WHERE + paramy (UWAGA: bez powielania nazw placeholderów) ──
$where  = [];
$params = [];

$where[]           = 'p.owner_id  = :oid_p';
$params[':oid_p']  = $owner_id;
$where[]           = 'sm.owner_id = :oid_sm';
$params[':oid_sm'] = $owner_id;

$where[]            = 'sm.created_at BETWEEN :from AND :to';
$params[':from']    = $start . ' 00:00:00';
$params[':to']      = $end   . ' 23:59:59';

if ($q !== '') {
    $where[]         = '(p.name LIKE :q OR p.code LIKE :q)';
    $params[':q']    = '%' . $q . '%';
}
if ($type !== '') {
    $where[]         = 'sm.movement_type = :mt';
    $params[':mt']   = $type;
}
if ($source !== '') {
    $where[]         = 'sm.source_type = :src';
    $params[':src']  = $source;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// ── SQL ────────────────────────────────────────────────────────
$sql = "
  SELECT
    sm.id,
    sm.product_id,
    sm.qty,
    sm.movement_type,
    sm.source_type,
    sm.note,
    sm.created_at,
    sm.created_by,
    sm.warehouse_id,
    p.name  AS product_name,
    p.code  AS product_code,
    u.email AS created_by_email
  FROM stock_movements sm
  JOIN products p ON p.id = sm.product_id
  LEFT JOIN users u ON u.id = sm.created_by
  $whereSql
  ORDER BY sm.created_at DESC, sm.id DESC
  LIMIT :limit
";

$st = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$st->bindValue(':limit', $limit, PDO::PARAM_INT);
$st->execute();
$movements = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ── Helpery ────────────────────────────────────────────────────
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function pretty_qty($v): string
{
    $s = number_format((float)$v, 3, '.', '');
    return rtrim(rtrim($s, '0'), '.') ?: '0';
}
function type_badge(string $t): string
{
    return match ($t) {
        'in'     => 'bg-emerald-100 text-emerald-800',
        'out'    => 'bg-red-100 text-red-800',
        'adjust' => 'bg-amber-100 text-amber-800',
        'return' => 'bg-blue-100 text-blue-800',
        default  => 'bg-gray-100 text-gray-700',
    };
}
function source_label(?string $s): string
{
    return match ($s) {
        'manual' => '🖐️ Manual',
        'live'   => '📺 LIVE',
        'order'  => '🧾 Zamówienie',
        'admin'  => '🛠️ Panel',
        'import' => '📥 Import',
        default  => '—',
    };
}
function buildQuery(array $extra = []): string
{
    $base = $_GET;
    foreach ($extra as $k => $v) {
        if ($v === null) unset($base[$k]);
        else $base[$k] = $v;
    }
    return http_build_query($base);
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css" />
<script src="https://cdn.jsdelivr.net/npm/litepicker/dist/litepicker.js"></script>

<style>
    .tbl {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0
    }

    .tbl th,
    .tbl td {
        padding: .5rem .6rem;
        border-bottom: 1px solid #e5e7eb;
        font-size: .925rem
    }

    .tbl thead th {
        background: #f8fafc;
        color: #475569;
        text-transform: uppercase;
        font-size: .75rem;
        letter-spacing: .04em
    }

    .badge {
        display: inline-block;
        padding: .15rem .45rem;
        border-radius: .375rem;
        font-size: .7rem;
        font-weight: 600
    }

    .kpi {
        display: flex;
        gap: .5rem;
        flex-wrap: wrap;
        margin: .5rem 0
    }

    .kpi .box {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: .5rem;
        padding: .35rem .55rem
    }

    .mono {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace
    }

    .hint {
        font-size: .8rem;
        color: #64748b
    }

    .controls {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
        align-items: center;
        margin: .6rem 0 1rem
    }

    .controls input,
    .controls select {
        border: 1px solid #e5e7eb;
        border-radius: .5rem;
        padding: .38rem .5rem
    }

    .pill {
        padding: .2rem .45rem;
        border: 1px solid #e5e7eb;
        border-radius: 999px;
        font-size: .75rem;
        color: #334155;
        background: #fff
    }
</style>

<div class="max-w-7xl mx-auto px-4 py-5">
    <div class="mb-3">
        <a href="/admin/products/index.php" class="text-sm text-gray-500 hover:underline">← Wróć do produktów</a>
    </div>

    <h2 class="text-2xl font-bold mb-2">♻ Historia ruchów magazynowych</h2>
    <div class="hint mb-3">Zgodnie z V2: kolumny <span class="mono">qty, movement_type, source_type</span>.</div>

    <form method="get" class="controls">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Szukaj produktu: nazwa / kod">
        <select name="type" title="Typ ruchu">
            <option value="">Typ: wszystkie</option>
            <?php foreach (['in' => 'Przychód', 'out' => 'Rozchód', 'adjust' => 'Korekta', 'return' => 'Zwrot'] as $v => $label): ?>
                <option value="<?= $v ?>" <?= $type === $v ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <select name="source" title="Źródło ruchu">
            <option value="">Źródło: wszystkie</option>
            <?php foreach (['manual' => 'Manual', 'live' => 'LIVE', 'order' => 'Zamówienie', 'admin' => 'Panel', 'import' => 'Import'] as $v => $label): ?>
                <option value="<?= $v ?>" <?= $source === $v ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>

        <select name="range" title="Zakres dat">
            <?php
            $ranges = ['dzisiaj' => 'Dzisiaj', 'wczoraj' => 'Wczoraj', '7dni' => '7 dni', '30dni' => '30 dni', 'wszystkie' => 'Wszystkie', 'wlasny' => 'Własny'];
            foreach ($ranges as $k => $label) echo '<option value="' . $k . '"' . ($k === $range ? ' selected' : '') . '>' . $label . '</option>';
            ?>
        </select>
        <input type="text" id="daterange" placeholder="Zakres dat" readonly>
        <input type="hidden" name="start" id="start-date" value="<?= e($start) ?>">
        <input type="hidden" name="end" id="end-date" value="<?= e($end) ?>">

        <select name="limit" title="Limit">
            <?php foreach (['50', '100', '200', '500'] as $l): ?>
                <option value="<?= $l ?>" <?= (string)$limit === $l ? 'selected' : '' ?>><?= $l ?> / str</option>
            <?php endforeach; ?>
        </select>
        <button class="pill" type="submit">🔍 Filtruj</button>
        <a class="pill" href="?<?= buildQuery(['export' => 'csv']) ?>">⬇️ Eksport CSV</a>
    </form>

    <?php
    // Eksport CSV aktualnych wyników
    if ((isset($_GET['export']) && $_GET['export'] === 'csv')) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment;filename=stock_movements.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Data', 'Produkt', 'Kod', 'Typ', 'Źródło', 'Ilość', 'Użytkownik', 'Notatka']);
        foreach ($movements as $m) {
            fputcsv($out, [
                (string)($m['created_at'] ?? ''),
                (string)($m['product_name'] ?? ''),
                (string)($m['product_code'] ?? ''),
                (string)($m['movement_type'] ?? ''),
                (string)($m['source_type'] ?? ''),
                pretty_qty($m['qty'] ?? 0),
                (string)($m['created_by_email'] ?? ''),
                (string)($m['note'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }
    ?>

    <div class="kpi">
        <div class="box">Wyników: <strong><?= number_format(count($movements), 0, ',', ' ') ?></strong></div>
        <div class="box">Zakres: <strong><?= e($start) ?></strong> → <strong><?= e($end) ?></strong></div>
        <?php if ($q): ?><div class="box">Szukaj: <span class="mono"><?= e($q) ?></span></div><?php endif; ?>
        <?php if ($type): ?><div class="box">Typ: <span class="mono"><?= e($type) ?></span></div><?php endif; ?>
        <?php if ($source): ?><div class="box">Źródło: <span class="mono"><?= e($source) ?></span></div><?php endif; ?>
    </div>

    <div class="overflow-auto">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Produkt</th>
                    <th>Typ</th>
                    <th>Ilość</th>
                    <th>Źródło</th>
                    <th>Użytkownik</th>
                    <th>Uwagi</th>
                    <th>Data</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$movements): ?>
                    <tr>
                        <td colspan="7" class="text-center text-gray-500">Brak ruchów dla wybranych filtrów.</td>
                    </tr>
                    <?php else: foreach ($movements as $m): ?>
                        <tr>
                            <td>
                                <div><strong><?= e($m['product_name'] ?? '') ?></strong></div>
                                <div class="mono text-gray-500"><?= e($m['product_code'] ?? '') ?></div>
                            </td>
                            <td><span class="badge <?= type_badge((string)($m['movement_type'] ?? '')) ?>"><?= e((string)($m['movement_type'] ?? '')) ?></span></td>
                            <td class="mono"><?= pretty_qty($m['qty'] ?? 0) ?></td>
                            <td><span class="badge bg-indigo-100 text-indigo-800"><?= source_label($m['source_type'] ?? null) ?></span></td>
                            <td class="mono"><?= e($m['created_by_email'] ?? '') ?></td>
                            <td><?= e($m['note'] ?? '') ?></td>
                            <td class="mono"><?= e($m['created_at'] ?? '') ?></td>
                        </tr>
                <?php endforeach;
                endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const picker = new Litepicker({
        element: document.getElementById('daterange'),
        singleMode: false,
        numberOfMonths: 2,
        numberOfColumns: 2,
        format: 'YYYY-MM-DD',
        startDate: '<?= $start ?>',
        endDate: '<?= $end ?>',
        dropdowns: {
            minYear: 2023,
            maxYear: new Date().getFullYear(),
            months: true,
            years: true
        },
        setup: (p) => {
            p.on('selected', (start, end) => {
                if (start) document.getElementById('start-date').value = start.format('YYYY-MM-DD');
                if (end) document.getElementById('end-date').value = end.format('YYYY-MM-DD');
            });
        },
    });
</script>

<?php require_once __DIR__ . '/../../layout/layout_footer.php';
