<?php
declare(strict_types=1);

/**
 * Prosta wyszukiwarka punktów (Paczkomaty InPost) przez Furgonetka REST.
 *
 * Wejście (GET/POST):
 *  - ownerId (int)   – wymagane
 *  - q (string)      – fraza (miasto/ulica/kod) – wymagane
 *  - limit (int)     – opcjonalnie (domyślnie 20)
 *  - page (int)      – opcjonalnie (domyślnie 1)
 *  - service (str)   – opcjonalnie (domyślnie 'inpost')
 *  - type (str)      – opcjonalnie (domyślnie 'apm' dla Paczkomatów)
 *
 * Wyjście: JSON z listą punktów w formacie ujednoliconym:
 *  [
 *    {
 *      "code": "WAW01A",
 *      "name": "InPost APM WAW01A",
 *      "service": "inpost",
 *      "type": "apm",
 *      "address": "ul. Testowa 1, 00-001 Warszawa",
 *      "city": "Warszawa",
 *      "postcode": "00-001",
 *      "street": "Testowa 1",
 *      "coordinates": {"lat": 52.2297, "lng": 21.0122},
 *    },
 *    ...
 *  ]
 */

$ROOT = dirname(__DIR__, 2);
$BOOT = $ROOT . '/_bootstrap.php';
if (!is_file($BOOT)) {
    http_response_code(500);
    echo "Brak pliku bootstrap: {$BOOT}";
    exit;
}
require_once $BOOT;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Brak połączenia PDO (upewnij się, że bootstrap został załadowany).";
    exit;
}

function must_int(mixed $v, string $name): int {
    if ($v === null || $v === '' || !is_numeric($v)) {
        if (!headers_sent()) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "Missing/invalid {$name}";
        exit;
    }
    return (int)$v;
}
function must_str(mixed $v, string $name): string {
    $s = trim((string)$v);
    if ($s === '') {
        if (!headers_sent()) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "Missing/invalid {$name}";
        exit;
    }
    return $s;
}
function jout(mixed $data, int $code = 200): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$ownerId = must_int($_REQUEST['ownerId'] ?? null, 'ownerId');
$q       = must_str($_REQUEST['q'] ?? null, 'q');
$limit   = (int)($_REQUEST['limit'] ?? 20);
$page    = (int)($_REQUEST['page']  ?? 1);
$service = strtolower(trim((string)($_REQUEST['service'] ?? 'inpost')));
$type    = strtolower(trim((string)($_REQUEST['type']    ?? 'apm'))); // apm = automat paczkowy

$API_FILE = $ROOT . '/engine/shipping/FurgonetkaAPI.php';
if (!is_file($API_FILE)) {
    jout(['ok'=>false,'error'=>"Brak pliku: {$API_FILE}"], 500);
}
require_once $API_FILE;

$logger = function(string $tag, array $ctx = []) {
    error_log('[furgo.points] '.$tag.' '.json_encode($ctx, JSON_UNESCAPED_UNICODE));
};

try {
    $api = new FurgonetkaAPI($pdo, ownerId: $ownerId, sandbox: false, logger: $logger);

    // Wywołanie REST
    $raw = $api->searchPoints([
        'service' => $service,
        'type'    => $type,
        'q'       => $q,
        'limit'   => $limit,
        'page'    => $page,
    ]);

    // Ujednolicenie odpowiedzi (API może różnić drobnymi polami)
    $out = [];
    $list = $raw['points'] ?? $raw['items'] ?? $raw; // elastycznie bierzemy kolekcję
    if (is_array($list)) {
        foreach ($list as $p) {
            $code  = (string)($p['code'] ?? $p['pointCode'] ?? $p['id'] ?? '');
            $name  = (string)($p['name'] ?? ("Punkt ".$code));
            $svc   = (string)($p['service'] ?? $service);
            $typ   = (string)($p['type'] ?? $type);

            $addr = $p['address'] ?? [];
            $street   = trim((string)($addr['street'] ?? $p['street'] ?? ''));
            $postcode = trim((string)($addr['postcode'] ?? $p['postcode'] ?? ''));
            $city     = trim((string)($addr['city'] ?? $p['city'] ?? ''));
            $fullAddr = trim(($street ? $street.', ' : '').($postcode ? $postcode.' ' : '').$city);

            $coords = $p['coordinates'] ?? $p['location'] ?? [];
            $lat = isset($coords['latitude'])  ? (float)$coords['latitude']  : (isset($coords['lat']) ? (float)$coords['lat'] : null);
            $lng = isset($coords['longitude']) ? (float)$coords['longitude'] : (isset($coords['lng']) ? (float)$coords['lng'] : null);

            $out[] = [
                'code'        => $code,
                'name'        => $name,
                'service'     => $svc,
                'type'        => $typ,
                'address'     => $fullAddr,
                'city'        => $city,
                'postcode'    => $postcode,
                'street'      => $street,
                'coordinates' => ($lat !== null && $lng !== null) ? ['lat'=>$lat,'lng'=>$lng] : null,
            ];
        }
    }

    jout([
        'ok'       => true,
        'query'    => ['q'=>$q,'service'=>$service,'type'=>$type,'limit'=>$limit,'page'=>$page],
        'count'    => count($out),
        'points'   => $out,
        'raw_hint' => isset($raw['pagination']) ? ['pagination'=>$raw['pagination']] : null,
    ]);
} catch (\Throwable $e) {
    $logger('error.exception', ['msg'=>$e->getMessage()]);
    jout(['ok'=>false,'error'=>$e->getMessage()], 502);
}
    /**
     * Szukanie punktów odbioru/nadania.
     * Typowe użycie dla Paczkomatów: service=inpost, type=apm, q="Warszawa Testowa 1"
     *
     * @param array $params ['service'=>'inpost','type'=>'apm','q'=>'...', 'limit'=>20, 'page'=>1]
     * @return array
     * @throws \RuntimeException
     */
    public function searchPoints(array $params = []): array
    {
        // Ustal domyślne parametry — jeśli nie podano, celujemy w Paczkomaty InPost
        $query = [
            'service' => $params['service'] ?? 'inpost',
            'type'    => $params['type']    ?? 'apm',      // apm = automaty paczkowe
            'q'       => $params['q']       ?? '',         // fraza: miasto/ulica/kod
            'limit'   => (int)($params['limit'] ?? 20),
            'page'    => (int)($params['page']  ?? 1),
        ];

        // Nie wszystkie konta mają dostęp do pełnego wyszukiwania punktów.
        // Jeśli API zwróci 404/400 — rzuć czytelny wyjątek.
        try {
            return $this->get('/points', $query);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '404') || str_contains($msg, '400')) {
                throw new \RuntimeException(
                    'Wyszukiwanie punktów nie jest dostępne lub parametry są nieprawidłowe. '.
                    'Spróbuj zawęzić frazę (np. miasto + ulica) albo sprawdź uprawnienia konta.'
                );
            }
            throw $e;
        }
    }
