<?php
// engine/ai/AiOrderAssistant.php — Olaj V4
declare(strict_types=1);

namespace Engine\Ai;

use PDO;
use Throwable;
use Engine\Log\LogEngine;
use Engine\Orders\OrderEngine;
use Engine\Product\ProductEngine;

final class AiOrderAssistant
{
    public function __construct(
        private PDO $pdo,
        private int $ownerId,
        private int $userId,
        private ?string $model = null
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        LogEngine::boot($this->pdo, $this->ownerId)
            ->debug('ai.order', 'AiOrderAssistant.boot', ['user_id'=>$this->userId, 'model'=>$this->model ?? 'auto']);
    }

    /** Publiczny punkt wejścia: wyciąga → rozwiązuje → (opcjonalnie) aplikuje */
    public function handleTextForGroup(int $orderId, int $groupId, string $text, bool $apply = true): array
    {
        $itemsRaw   = $this->extractItems($text);
        $itemsReady = $this->resolveItems($itemsRaw);

        if (!$apply) {
            return ['ok'=>true,'applied'=>false,'items'=>$itemsReady];
        }
        $result = $this->applyItemsToGroup($orderId, $groupId, $itemsReady);
        return ['ok'=>true,'applied'=>true,'result'=>$result,'items'=>$itemsReady];
    }

    /** Krok 1: parsujemy tekst z pomocą AI + fallbacku regexowego */
    public function extractItems(string $text): array
    {
        $log = LogEngine::boot($this->pdo, $this->ownerId);
        $log->info('ai.order', 'extract.start', ['len'=>mb_strlen($text), 'user_id'=>$this->userId]);

        // 1) Spróbuj AI (ustrukturyzowany JSON)
        $aiItems = [];
        try {
            $prompt = <<<PROMPT
Zamien polecenie klienta na listę pozycji JSON:
Wejście: "{$text}"
Zwróć tablicę obiektów { "ref": "kod/słowo-kluczowe/sku/ean/12nc/nazwa", "qty": liczba }.
Bez komentarzy, wyłącznie JSON.
PROMPT;
            $response = AiEngine::boot($this->pdo, $this->ownerId)
                ->chatJson($prompt, model: $this->model);
            // Oczekujemy tablicy [['ref'=>'Daj 817','qty'=>1], ...]
            if (\is_array($response)) {
                foreach ($response as $row) {
                    $ref = trim((string)($row['ref'] ?? ''));
                    $qty = (float)($row['qty'] ?? 0);
                    if ($ref !== '' && $qty > 0) {
                        $aiItems[] = ['ref'=>$ref, 'qty'=>$qty];
                    }
                }
            }
        } catch (Throwable $e) {
            $log->warning('ai.order', 'extract.ai_error', ['err'=>$e->getMessage()]);
        }

        // 2) Fallback regex: rozpoznaj „daj XXX [x ILOŚĆ]”, liczby, itp.
        $fbItems = [];
        $t = mb_strtolower($text);
        // „daj 817 x2” / „daj 817 2 szt” / „817 2”
        if (preg_match_all('/(?:(?:daj)\s*)?([\w\-\.]{2,})\s*(?:x|\*|\s)?\s*([0-9]+(?:[\,\.][0-9]+)?)?/u', $t, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $ref = trim($mm[1] ?? '');
                $qty = isset($mm[2]) ? (float)str_replace(',', '.', $mm[2]) : 1.0;
                if ($ref !== '') $fbItems[] = ['ref'=>$ref, 'qty'=> max(0.001, $qty)];
            }
        }

        $items = !empty($aiItems) ? $aiItems : $fbItems;
        $log->info('ai.order', 'extract.done', ['items'=>$items]);
        return $items;
    }

    /** Krok 2: dopinamy produkty po ref: code→sku→ean→12nc→name (korzystamy z ProductEngine) */
    public function resolveItems(array $items): array
    {
        $pe  = \Engine\Product\ProductEngine::boot($this->pdo, $this->ownerId);
        $log = LogEngine::boot($this->pdo, $this->ownerId);

        $out = [];
        foreach ($items as $row) {
            $ref = trim((string)($row['ref'] ?? ''));
            $qty = (float)($row['qty'] ?? 0);
            if ($ref === '' || $qty <= 0) continue;

            $product = $this->findProductSmart($pe, $ref);
            if ($product) {
                $out[] = [
                    'product_id' => (int)$product['id'],
                    'name'       => (string)($product['name'] ?? 'Produkt'),
                    'sku'        => (string)($product['sku'] ?? ''),
                    'code'       => (string)($product['code'] ?? ''),
                    'qty'        => $qty,
                    'unit_price' => (float)($product['unit_price'] ?? 0.0),
                    'vat_rate'   => (float)($product['vat_rate'] ?? 23.0),
                ];
            } else {
                $log->warning('ai.order', 'resolve.not_found', ['ref'=>$ref]);
                $out[] = [
                    'product_id' => null,
                    'name'       => $ref,
                    'sku'        => '',
                    'code'       => '',
                    'qty'        => $qty,
                    'unit_price' => 0.0,
                    'vat_rate'   => 23.0,
                    'unresolved' => true,
                ];
            }
        }
        $log->info('ai.order', 'resolve.done', ['resolved'=>$out]);
        return $out;
    }

    /** Krok 3: commit do order_items przez OrderEngine (atomowo) */
    public function applyItemsToGroup(int $orderId, int $groupId, array $items): array
    {
        $oe  = OrderEngine::boot($this->pdo, $this->ownerId);
        $log = LogEngine::boot($this->pdo, $this->ownerId);

        $this->pdo->beginTransaction();
        try {
            $added = [];
            foreach ($items as $it) {
                if (!empty($it['unresolved'])) continue; // pominąć nierozwiązane
                $name = (string)$it['name'];
                $qty  = (float)$it['qty'];
                $price= (float)$it['unit_price'];
                $vat  = (float)$it['vat_rate'];
                $sku  = (string)$it['sku'];
                $sourceType = 'ai'; // sugeruję mieć to w ENUM-ach (order_item_source_type)

                $itemId = $oe->addOrderItem(
                    orderId: $orderId,
                    groupId: $groupId,
                    name: $name,
                    qty: $qty,
                    unitPrice: $price,
                    vatRate: $vat,
                    sku: $sku,
                    sourceType: $sourceType
                );
                $added[] = $itemId;
            }
            $this->pdo->commit();
            $log->info('ai.order', 'apply.done', ['order_id'=>$orderId, 'group_id'=>$groupId, 'added'=>$added]);
            return ['ok'=>true,'added'=>$added];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $log->error('ai.order', 'apply.error', ['err'=>$e->getMessage(), 'trace'=>$e->getTraceAsString()]);
            return ['ok'=>false,'error'=>$e->getMessage()];
        }
    }

    /** Przeszukaj produkt „mądrze”: code→sku→ean→12nc→name */
    private function findProductSmart(ProductEngine $pe, string $ref): ?array
    {
        // Zostawiamy „smart” na engine — tu tylko przykładowe wywołania.
        // W twoim ProductEngine istnieją metody search/get — użyj realnych.
        // Poniżej minimalny przykład z SQL-em awaryjnym:
        $sqls = [
            "SELECT * FROM products WHERE owner_id=:oid AND deleted_at IS NULL AND code=:q LIMIT 1",
            "SELECT * FROM products WHERE owner_id=:oid AND deleted_at IS NULL AND sku=:q LIMIT 1",
            "SELECT * FROM products WHERE owner_id=:oid AND deleted_at IS NULL AND ean=:q LIMIT 1",
            "SELECT p.* FROM twelve_nc_map t JOIN products p ON p.id=t.product_id AND p.owner_id=:oid WHERE t.twelve_nc=:q LIMIT 1",
            "SELECT * FROM products WHERE owner_id=:oid AND deleted_at IS NULL AND name LIKE :qlike LIMIT 1",
        ];
        foreach ($sqls as $i=>$sql) {
            $st = $this->pdo->prepare($sql);
            $params = [':oid'=>$this->ownerId];
            if ($i === 4) { $params[':qlike'] = '%'.$ref.'%'; }
            else { $params[':q'] = $ref; }
            $st->execute($params);
            $row = $st->fetch();
            if ($row) return $row;
        }
        return null;
    }

    public static function boot(PDO $pdo, int $ownerId, int $userId, ?string $model=null): self
    {
        return new self($pdo, $ownerId, $userId, $model);
    }
}
