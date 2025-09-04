<?php

declare(strict_types=1);

namespace Engine\Shipping;

final class InPostClient
{
    public function __construct(
        private string $apiUrl,
        private string $token,           // ShipX bearer
        private int    $organizationId
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
    }

    public function createShipment(array $shipment): array
    {
        return $this->request('POST', "/v1/organizations/{$this->organizationId}/shipments", ['json' => $shipment]);
    }

    public function getShipment(int $shipmentId): array
    {
        return $this->request('GET', "/v1/shipments/{$shipmentId}");
    }

    public function getLabelBinary(int $shipmentId, string $format = 'Pdf', string $type = 'A6'): string
    {
        $q = http_build_query(['format' => $format, 'type' => $type]);
        return $this->requestRaw('GET', "/v1/shipments/{$shipmentId}/label?{$q}");
    }

    private function request(string $method, string $path, array $opt = []): array
    {
        [,, $body] = $this->doRequest($method, $path, $opt);
        $json = json_decode($body, true);
        if (!is_array($json)) throw new \RuntimeException('Invalid JSON from InPost');
        return $json;
    }

    private function requestRaw(string $method, string $path): string
    {
        [,, $body] = $this->doRequest($method, $path, [], true);
        return $body;
    }

    private function doRequest(string $method, string $path, array $opt = [], bool $raw = false): array
    {
        $url = $this->apiUrl . $path;
        $ch  = curl_init($url);
        $headers = ['Authorization: Bearer ' . $this->token, 'Accept: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER        => true,
            CURLOPT_TIMEOUT       => 30,
        ]);
        if (isset($opt['json'])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json'], JSON_UNESCAPED_UNICODE));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($err);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body   = substr($resp, $hsize);
        curl_close($ch);
        if (!$raw && $status >= 400) throw new \RuntimeException("ShipX HTTP {$status}: {$body}");
        return [$status, '', $body];
    }
}
