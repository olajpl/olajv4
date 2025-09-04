<?php
// includes/shipping_furgonetka.php
declare(strict_types=1);

/** Prosty logger do pliku (żeby nie było już "Call to undefined function ship_log()") */
function ship_log(string $tag, array $ctx = []): void {
    try {
        $line = date('Y-m-d H:i:s') . ' ' . $tag . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        @file_put_contents(__DIR__ . '/../logs/shipping.log', $line, FILE_APPEND);
    } catch (\Throwable $e) {}
}

/** Czyta aktualny access_token z tabeli shipping_integrations */
function getFurgonetkaToken(PDO $pdo, int $ownerId): string {
    $q = $pdo->prepare("
        SELECT oauth_access_token AS tok, oauth_expires_at AS exp
        FROM shipping_integrations
        WHERE owner_id = :oid AND broker = 'furgonetka'
        ORDER BY id DESC LIMIT 1
    ");
    $q->execute([':oid' => $ownerId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Brak konfiguracji Furgonetka dla owner_id=' . $ownerId);

    // Jeśli blisko końca ważności, pozwól mechanizmowi refresh się uruchomić
    if (!empty($row['exp']) && strtotime($row['exp']) <= time() + 60) {
        return refreshFurgonetkaToken($pdo, $ownerId);
    }
    if (empty($row['tok'])) throw new RuntimeException('Brak access_token w shipping_integrations');
    return (string)$row['tok'];
}

/**
 * Minimalny refresher:
 *  - tu tylko "przeładuj" token z DB (zakładamy, że osobny proces już go odświeżył),
 *  - jeśli chcesz realnie odświeżać via OAuth, dodaj tu call do endpointu tokenów i update DB.
 */
function refreshFurgonetkaToken(PDO $pdo, int $ownerId): string {
    ship_log('furgo.refresh.try', ['owner'=>$ownerId]);
    $q = $pdo->prepare("
        SELECT oauth_access_token AS tok
        FROM shipping_integrations
        WHERE owner_id = :oid AND broker = 'furgonetka'
        ORDER BY id DESC LIMIT 1
    ");
    $q->execute([':oid' => $ownerId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['tok'])) {
        throw new RuntimeException('Odświeżenie nieudane: brak nowego access_token w DB');
    }
    ship_log('furgo.refresh.ok', ['owner'=>$ownerId, 'len'=>strlen($row['tok'])]);
    return (string)$row['tok'];
}

/** Z listy usług REST wybierz `service_id` po kodzie (np. "dpd", "inpost", "gls") lub zwróć już podany */
function furgo_resolve_service_id(FurgonetkaAPI $api, ?int $serviceId, ?string $serviceCode): int {
    if ($serviceId && $serviceId > 0) return (int)$serviceId;

    $services = $api->listServices();
    // Oczekujemy tablicy "services": [...]
    $items = $services['services'] ?? $services ?? [];
    $serviceCode = $serviceCode ? strtolower(trim($serviceCode)) : '';

    foreach ($items as $it) {
        // próbujemy różne popularne klucze
        $id  = $it['id']   ?? $it['service_id'] ?? null;
        $svc = strtolower((string)($it['service'] ?? $it['code'] ?? $it['short'] ?? ''));
        if ($serviceCode !== '' && $svc === $serviceCode && $id) return (int)$id;
    }

    // jeżeli nie udało się po kodzie — spróbuj po pierwszej dostępnej usłudze
    foreach ($items as $it) {
        $id = $it['id'] ?? $it['service_id'] ?? null;
        if ($id) return (int)$id;
    }

    throw new RuntimeException('Nie mogę ustalić service_id (lista usług pusta lub niejednoznaczna)');
}

/** Minimalny builder payloadu REST /packages */
function furgo_build_rest_payload(array $pickup, array $receiver, int $serviceId, array $parcels, array $opts = []): array {
    // Upewnij się o wymaganych polach:
    $pickup   = array_change_key_case($pickup,   CASE_LOWER);
    $receiver = array_change_key_case($receiver, CASE_LOWER);

    $requiredAddr = ['name','street','postcode','city','country_code'];
    foreach ($requiredAddr as $rk) {
        if (empty($pickup[$rk]))   throw new InvalidArgumentException("pickup.$rk wymagane");
        if (empty($receiver[$rk])) throw new InvalidArgumentException("receiver.$rk wymagane");
    }
    if (empty($parcels) || !is_array($parcels)) {
        throw new InvalidArgumentException('parcels wymagane (co najmniej 1)');
    }

    // Normalizacja paczek: wymagane pola: weight, width, height, length
    $normParcels = [];
    foreach ($parcels as $p) {
        $p = array_change_key_case($p, CASE_LOWER);
        foreach (['weight','width','height','length'] as $dim) {
            if (!isset($p[$dim])) throw new InvalidArgumentException("parcel.$dim wymagane");
        }
        $normParcels[] = [
            'weight' => (float)$p['weight'],
            'width'  => (float)$p['width'],
            'height' => (float)$p['height'],
            'length' => (float)$p['length'],
            // opcjonalnie:
            'contents' => (string)($p['contents'] ?? ($opts['contents'] ?? 'Towary')),
            'value'    => isset($p['value']) ? (float)$p['value'] : null,
        ];
    }

    $payload = [
        'pickup'    => [
            'name'         => (string)$pickup['name'],
            'company'      => (string)($pickup['company'] ?? ''),
            'street'       => (string)$pickup['street'],
            'postcode'     => (string)$pickup['postcode'],
            'city'         => (string)$pickup['city'],
            'country_code' => (string)$pickup['country_code'],
            'county'       => (string)($pickup['county'] ?? ''),
            'email'        => (string)($pickup['email'] ?? ''),
            'phone'        => (string)($pickup['phone'] ?? ''),
            'point'        => (string)($pickup['point'] ?? ''), // jeśli nadanie w punkcie
        ],
        'receiver'  => [
            'name'         => (string)$receiver['name'],
            'company'      => (string)($receiver['company'] ?? ''),
            'street'       => (string)$receiver['street'],
            'postcode'     => (string)$receiver['postcode'],
            'city'         => (string)$receiver['city'],
            'country_code' => (string)$receiver['country_code'],
            'county'       => (string)($receiver['county'] ?? ''),
            'email'        => (string)($receiver['email'] ?? ''),
            'phone'        => (string)($receiver['phone'] ?? ''),
            'point'        => (string)($receiver['point'] ?? ''), // jeśli dostawa do punktu
        ],
        'service_id' => $serviceId,
        'type'       => (string)($opts['type'] ?? 'package'), // package|dox|pallet
        'parcels'    => $normParcels,
    ];

    // Usługi dodatkowe — tylko gdy podane (np. COD)
    if (!empty($opts['additional_services']) && is_array($opts['additional_services'])) {
        $payload['additional_services'] = $opts['additional_services'];
    }
    // Numer referencyjny na etykiecie
    if (!empty($opts['user_reference_number'])) {
        $payload['user_reference_number'] = (string)$opts['user_reference_number'];
    }

    return $payload;
}
