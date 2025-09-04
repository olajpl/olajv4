<?php
// api/furgonetka/furgonetka_api.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/shipping_furgonetka.php'; // getFurgonetkaToken(...)
require_once __DIR__ . '/../../includes/log.php'; // logg() jeżeli jest

if (!function_exists('flog')) {
    function flog(string $msg, string $lvl = 'info', array $ctx = []): void
    {
        $meta = ['event' => 'furgonetka', 'source' => 'api_client'];
        if (function_exists('logg')) {
            logg($lvl, 'shipping.furgonetka', $msg, $ctx, $meta);
            return;
        }
        if (class_exists('OlajV4Logger')) {
            OlajV4Logger::log("{$lvl}.shipping.furgonetka.{$msg}", $ctx + $meta);
            return;
        }
        error_log('[shipping.furgonetka][' . $lvl . '][' . $msg . '] ' . json_encode($ctx + $meta, JSON_UNESCAPED_UNICODE));
    }
}

class FurgonetkaAPI
{
    private string $accessToken;
    private string $apiBase = 'https://api.furgonetka.pl/v2/';

    public function __construct(array $opts = [])
    {
        // Token – zawsze przez helper (z odświeżeniem jeśli trzeba)
        $pdo      = $opts['pdo'] ?? ($GLOBALS['pdo'] ?? null);
        $ownerId  = $opts['owner_id'] ?? ($_SESSION['user']['owner_id'] ?? null);

        if ($pdo instanceof PDO && $ownerId) {
            $this->accessToken = getFurgonetkaToken($pdo, (int)$ownerId);
        } else {
            $this->accessToken = getFurgonetkaToken(); // stary styl (global $pdo + sesja)
        }
    }

    private function http(string $method, string $endpoint, ?array $data = null, array $extraHeaders = []): array
    {
        $url = rtrim($this->apiBase, '/') . '/' . ltrim($endpoint, '/');
        $isPost = strtoupper($method) === 'POST';

        $ch = curl_init($url);
        $headers = array_merge([
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json',
            // niektóre bramki są czułe na UA
            'User-Agent: OlajV4/1.0 (+panel.olaj.pl)',
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($isPost) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST'); // niektóre reverse proxy lubią mieć jawnie
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : '{}');
        }

        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        // Log request/response skrócony
        flog('api.http', $http >= 400 ? 'error' : 'debug', [
            'method' => $method,
            'url' => $url,
            'status' => $http,
            'payload' => $data,
            'error' => $err ?: null,
            'raw' => mb_substr((string)$raw, 0, 5000),
        ]);

        if ($raw === false) {
            throw new RuntimeException('CURL error: ' . $err);
        }

        $decoded = json_decode((string)$raw, true);
        if ($http >= 400) {
            // Zwrot z treścią błędu
            throw new RuntimeException("Błąd API Furgonetki ($http): " . ($decoded ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : 'Brak odpowiedzi'));
        }

        return is_array($decoded) ? $decoded : ['raw' => $raw];
    }

    // Listowanie usług – użyjemy do znalezienia właściwego service_id po nazwie/slug
    public function listServices(): array
    {
        return $this->http('GET', 'services');
    }

    // Prosta pomoc: znajdź service_id po fragmencie nazwy (np. 'InPost Paczkomat')
    public function findServiceIdByName(string $needle): ?int
    {
        $needle = mb_strtolower($needle);
        $list = $this->listServices();
        foreach ($list as $svc) {
            $name = mb_strtolower((string)($svc['name'] ?? ''));
            if ($name !== '' && mb_strpos($name, $needle) !== false) {
                return (int)$svc['id'];
            }
        }
        return null;
    }

    // Utworzenie przesyłki (twardo /v2/shipments)
    public function createShipment(array $payload): array
    {
        return $this->http('POST', 'shipments', $payload);
    }
}
