<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Hetzner;

use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Service\Http\Response;

/**
 * Mock HTTP client that returns pre-configured responses.
 */
final class MockHttpClient extends HttpClient
{
    /** @var Response[] */
    private array $queue = [];

    /** @var array<int, array{method: string, url: string, body: string}> */
    private array $history = [];

    public function queueResponse(int $statusCode, string $body): void
    {
        $this->queue[] = new Response($statusCode, $body);
    }

    public function get(string $url, array $headers = []): Response
    {
        return $this->nextResponse('GET', $url);
    }

    public function post(string $url, array $headers = [], string $body = ''): Response
    {
        return $this->nextResponse('POST', $url, $body);
    }

    public function delete(string $url, array $headers = []): Response
    {
        return $this->nextResponse('DELETE', $url);
    }

    public function request(string $method, string $url, array $headers = [], string $body = ''): Response
    {
        return $this->nextResponse($method, $url, $body);
    }

    /**
     * @return array{method: string, url: string, body: string}
     */
    public function getLastRequest(): array
    {
        $last = end($this->history);
        if ($last === false) {
            throw new \RuntimeException('No requests recorded');
        }

        return $last;
    }

    /**
     * @return array<int, array{method: string, url: string, body: string}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    private function nextResponse(string $method, string $url, string $body = ''): Response
    {
        $this->history[] = ['method' => $method, 'url' => $url, 'body' => $body];

        $response = array_shift($this->queue);
        if ($response === null) {
            throw new \RuntimeException('No more queued responses');
        }

        return $response;
    }
}
