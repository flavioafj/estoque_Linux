<?php
namespace Services;

use RuntimeException;

final class SyncHttpClient
{
    private string $url;
    private string $token;
    public function __construct(string $url, string $token) { $this->url = $url; $this->token = $token; }

    public function request(string $method, array $query = [], ?array $payload = null): array
    {
        $url = $this->url . ($query ? (strpos($this->url, '?') === false ? '?' : '&') . http_build_query($query) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Token: Bearer ' . $this->token]]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($response === false || $errno) throw new RuntimeException('Falha de conexão com a API (cURL ' . $errno . ').');
        return self::validarResposta($status, $response);
    }

    public static function validarResposta(int $status, string $body): array
    {
        if ($status !== 200) throw new RuntimeException('API de sincronização retornou HTTP ' . $status . '.');
        $data = json_decode($body, true);
        if (!is_array($data) || ($data['success'] ?? null) !== true) throw new RuntimeException('API não confirmou sucesso em uma resposta JSON válida.');
        return $data;
    }
}
