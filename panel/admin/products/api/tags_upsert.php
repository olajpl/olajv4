<?php
// admin/products/api/tags_upsert.php — tworzy brakujące tagi po nazwie i przypina zestaw tagów do produktu
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../bootstrap.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$owner_id = (int)($_SESSION['user']['owner_id'] ?? 0);
$user_id  = (int)($_SESSION['user']['id'] ?? 0);

if ($owner_id <= 0 || $user_id <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

// JSON body
$raw = file_get_contents('php://input');
$in  = json_decode($raw ?: '[]', true) ?: [];

$csrf_form   = (string)($in['csrf_token'] ?? '');
$csrf_sess   = (string)($_SESSION['csrf_token'] ?? '');
$product_id  = (int)($in['product_id'] ?? 0);
$tag_names   = is_array($in['tags'] ?? null) ? $in['tags'] : [];      // np. ["bestseller","promocja"]
$tag_ids_in  = is_array($in['tag_ids'] ?? null) ? $in['tag_ids'] : []; // np. [3,9]

if ($csrf_form === '' || $csrf_sess === '' || !hash_equals($csrf_sess, $csrf_form)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf_fail']);
    exit;
}
if ($product_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_product']);
    exit;
}

// helpers
function slugify(string $s): string
{
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $s = preg_replace('~[^\p{L}\p{N}]+~u', '-', $s);
    $s = trim($s, '-');
    if ($s === '') $s = bin2hex(random_bytes(4));
    return mb_substr($s, 0, 64);
}

// normalizuj wejście
$norm_names = [];
foreach ($tag_names as $t) {
    $t = trim((string)$t);
    if ($t !== '') $norm_names[] = $t;
}
$norm_ids = [];
foreach ($tag_ids_in as $id) {
    $id = (int)$id;
    if ($id > 0) $norm_ids[] = $id;
}

try {
    $pdo->beginTransaction();

    // 1) Upsert po NAZWIE → product_tags (unikalność po (owner_id, slug))
    $all_ids = $norm_ids;
    $created_tags = [];

    if ($norm_names) {
        // sprawdź co już istnieje
        $slugs = array_map('slugify', $norm_names);
        $ph    = implode(',', array_fill(0, count($slugs), '?'));
        $st    = $pdo->prepare("SELECT id, slug, name, color FROM product_tags WHERE owner_id=? AND slug IN ($ph)");
        $i = 1;
        $st->bindValue($i++, $owner_id, PDO::PARAM_INT);
        foreach ($slugs as $s) $st->bindValue($i++, $s, PDO::PARAM_STR);
        $st->execute();
        $existing = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $existing[$r['slug']] = (int)$r['id'];
        }

        foreach ($norm_names as $name) {
            $slug = slugify($name);
            if (isset($existing[$slug])) {
                $all_ids[] = $existing[$slug];
                continue;
            }
            $all_ids = array_filter(array_unique(array_map('intval', $all_ids)), fn($id) => $id > 0);

            // nie ma — wstaw
            $ins = $pdo->prepare("INSERT INTO product_tags (owner_id, name, slug, color, active, created_at) VALUES (:oid,:name,:slug,:color,1,NOW())");
            $ok = $ins->execute([
                ':oid'   => $owner_id,
                ':name'  => $name,
                ':slug'  => $slug,
                ':color' => '#666666'
            ]);
            $newId = (int)$pdo->lastInsertId();
            if (!$ok || $newId <= 0) {
                throw new RuntimeException("Nie udało się utworzyć tagu: $name (slug: $slug)");
            }
            $all_ids[] = $newId;
            $created_tags[] = ['id' => $newId, 'name' => $name, 'color' => '#666666'];
        }
    }

    // unikalizuj ID
    $all_ids = array_values(array_unique(array_map('intval', $all_ids)));

    // 2) Przypięcie do produktu → product_tag_links (czyścimy i zakładamy na nowo)
    //    (u Ciebie w schemacie jest owner_id w linkach — pilnujemy go)
    $pdo->prepare("DELETE FROM product_tag_links WHERE product_id=:pid AND owner_id=:oid")
        ->execute([':pid' => $product_id, ':oid' => $owner_id]);

    if ($all_ids) {
        $insL = $pdo->prepare("INSERT INTO product_tag_links (product_id, tag_id, owner_id) VALUES (:pid,:tid,:oid)");
        foreach ($all_ids as $tid) {
            $insL->execute([':pid' => $product_id, ':tid' => $tid, ':oid' => $owner_id]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok'   => true,
        'ids'  => $all_ids,
        'tags' => $created_tags, // tylko nowo utworzone (dla ewentualnego dorysowania UI)
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
