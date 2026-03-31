<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Provider\Hetzner;

use JardisOps\Provisioning\Service\Http\HttpClient;
use RuntimeException;

/**
 * Low-level HTTP client for the Hetzner Cloud API.
 */
final class HetznerApiClient
{
    private const BASE_URL = 'https://api.hetzner.cloud/v1';

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $apiToken,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = self::BASE_URL . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $response = $this->httpClient->get($url, $this->headers());

        return $this->decodeResponse($response->body, $response->statusCode);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function post(string $path, array $data = []): array
    {
        $headers = $this->headers();
        $headers['Content-Type'] = 'application/json';

        $body = $data !== [] ? json_encode($data, JSON_THROW_ON_ERROR) : '';

        $response = $this->httpClient->post(self::BASE_URL . $path, $headers, $body);

        return $this->decodeResponse($response->body, $response->statusCode);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function put(string $path, array $data = []): array
    {
        $headers = $this->headers();
        $headers['Content-Type'] = 'application/json';

        $body = $data !== [] ? json_encode($data, JSON_THROW_ON_ERROR) : '';

        $response = $this->httpClient->request('PUT', self::BASE_URL . $path, $headers, $body);

        return $this->decodeResponse($response->body, $response->statusCode);
    }

    public function delete(string $path): void
    {
        $response = $this->httpClient->delete(self::BASE_URL . $path, $this->headers());

        if ($response->statusCode >= 400) {
            throw new RuntimeException(
                "Hetzner API error: DELETE {$path} returned {$response->statusCode}"
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $body, int $statusCode): array
    {
        if ($body === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        if ($statusCode >= 400) {
            $message = $decoded['error']['message'] ?? "HTTP {$statusCode}";
            $code = $decoded['error']['code'] ?? 'unknown';
            throw new RuntimeException("Hetzner API error: {$code} — {$message}");
        }

        return $decoded;
    }
}
