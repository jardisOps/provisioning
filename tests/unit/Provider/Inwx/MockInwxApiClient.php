<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Inwx;

use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Provider\Inwx\InwxApiClient;

/**
 * Mock INWX API client that returns pre-configured responses.
 */
final class MockInwxApiClient extends InwxApiClient
{
    /** @var array<int, array<string, mixed>> */
    private array $queue = [];

    /** @var array<int, array{method: string, params: array<string, mixed>}> */
    private array $history = [];

    public function __construct()
    {
        parent::__construct(new HttpClient(), 'test', 'test');
    }

    /**
     * @param array<string, mixed> $resData
     */
    public function queueResponse(int $code = 1000, string $msg = 'OK', array $resData = []): void
    {
        $this->queue[] = [
            'code' => $code,
            'msg' => $msg,
            'resData' => $resData,
        ];
    }

    public function login(): void
    {
        // No-op in mock
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $this->history[] = ['method' => $method, 'params' => $params];

        $response = array_shift($this->queue);
        if ($response === null) {
            throw new \RuntimeException('No more queued INWX responses');
        }

        return $response;
    }

    /**
     * @return array{method: string, params: array<string, mixed>}
     */
    public function getLastCall(): array
    {
        $last = end($this->history);
        if ($last === false) {
            throw new \RuntimeException('No calls recorded');
        }

        return $last;
    }

    /**
     * @return array<int, array{method: string, params: array<string, mixed>}>
     */
    public function getHistory(): array
    {
        return $this->history;
    }
}
