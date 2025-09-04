<?php
// engine/shipping/FurgonetkaAPI.php
declare(strict_types=1);

namespace Engine\Shipping;

use PDO;
use RuntimeException;

class FurgonetkaAPI
{
    private PDO $pdo;
    private int $ownerId;
    private ?string $apiKey = null;
    private ?string $oauthAccessToken = null;
    private ?string $oauthTokenType = 'Bearer';
    private ?string $baseUrl = 'https://api.furgonetka.pl/api';

    public function __construct(PDO $pdo, int $ownerId)
    {
        $this->pdo = $pdo;
        $this->ownerId = $ownerId;

        // Wczytaj klucze z shipping_integrations (broker='furgonetka')
        $stmt = $pdo->prepare("
            SELECT api_key, oauth_access_token, oauth_token_type
            FROM shipping_integrations
            WHERE owner_id = :oid AND broker = 'furgonetka'
            LIMIT 1
        ");
        $stmt->execute(['oid' => $ownerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Furgonetka: brak konfiguracji dla ownera');
        }

        $this->apiKey          = $row['api_key'] ?: null;
        $this->oauthAccessToken = $row['oauth_access_token'] ?: null;
        $this->oauthTokenType   = $row['oauth_token_type'] ?: 'Bearer';

        if (!$this->apiKey && !$this->oauthAccessToken) {
            throw new RuntimeException('Furgonetka: brak api_key i oauth_access_token');
        }
    }

    /**
     * Tworzy przesyłkę. $payload to już gotowy model "universal".
     * Zwraca: ['external_id','label_url','tracking_number','price','tracking_url']
     */
    public function createShipment(array $payload): array
    {
        $url = $this->baseUrl . '/shipments';

        $headers = [
            'Content-Type: application/json',
            // Dwie opcje autoryzacji – preferuj OAuth, inaczej legacy api_key
            $this->oauthAccessToken
                ? "Authorization: {$this->oauthTokenType} {$this->oauthAccessToken}"
                : "Authorization: ApiKey {$this->apiKey}",
        ];

        $res = $this->curlJson('POST', $url, $payload, $headers);
        $code = $res['status'] ?? 0;

        // Idempotencja: 409 = już istnieje – spróbuj pobrać szczegóły po reference
        if ($code === 409) {
            // Jeśli daliśmy reference / order_number, pobierz po nim
            $reference = $payload['reference'] ?? null;
            if ($reference) {
                return $this->getShipmentByReference($reference);
            }
        }

        if ($code < 200 || $code >= 300) {
            $msg = (string)($res['body']['message'] ?? 'Furgonetka: błąd tworzenia przesyłki');
            throw new RuntimeException($msg);
        }

        $body = $res['body'] ?? [];
        return [
            'external_id'     => (string)($body['id'] ?? ''),
            'label_url'       => (string)($body['label_url'] ?? ''),
            'tracking_number' => (string)($body['tracking_number'] ?? ''),
            'price'           => isset($body['price']) ? (float)$body['price'] : null,
            'tracking_url'    => (string)($body['tracking_url'] ?? ''),
        ];
    }

    /**
     * Pobiera przesyłkę po reference (nasz order_number / label_id).
     */
    public function getShipmentByReference(string $reference): array
    {
        $url = $this->baseUrl . '/shipments?reference=' . rawurlencode($reference);
        $headers = [
            'Accept: application/json',
            $this->oauthAccessToken
                ? "Authorization: {$this->oauthTokenType} {$this->oauthAccessToken}"
                : "Authorization: ApiKey {$this->apiKey}",
        ];
        $res  = $this->curlJson('GET', $url, null, $headers);
        $code = $res['status'] ?? 0;
        if ($code !== 200) {
            throw new RuntimeException('Furgonetka: nie udało się pobrać przesyłki po reference');
        }
        $items = $res['body']['data'] ?? [];
        $first = $items[0] ?? [];
        return [
            'external_id'     => (string)($first['id'] ?? ''),
            'label_url'       => (string)($first['label_url'] ?? ''),
            'tracking_number' => (string)($first['tracking_number'] ?? ''),
            'price'           => isset($first['price']) ? (float)$first['price'] : null,
            'tracking_url'    => (string)($first['tracking_url'] ?? ''),
        ];
    }

    private function curlJson(string $method, string $url, ?array $json, array $headers): array
    {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $body = json_decode($raw ?: 'null', true);
        return [
            'status' => $status ?: 0,
            'error'  => $err ?: null,
            'raw'    => $raw,
            'body'   => is_array($body) ? $body : [],
        ];
    }
}
