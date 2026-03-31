<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Hetzner;

use JardisOps\Provisioning\Provider\Hetzner\HetznerVolumeProvider;
use JardisOps\Provisioning\Support\Data\Volume;
use PHPUnit\Framework\TestCase;

final class HetznerVolumeProviderTest extends TestCase
{
    private MockHttpClient $httpClient;
    private HetznerVolumeProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();
        $api = HetznerTestHelper::createApiClient($this->httpClient);
        $this->provider = new HetznerVolumeProvider($api);
    }

    public function testCreateVolumeWithoutServer(): void
    {
        $this->httpClient->queueResponse(201, json_encode([
            'volume' => [
                'id' => 500,
                'name' => 'data-vol',
                'size' => 50,
                'server' => null,
            ],
        ], JSON_THROW_ON_ERROR));

        $volume = new Volume('data-vol', 50);
        $result = $this->provider->createVolume($volume, 'fsn1');

        self::assertSame('500', $result->id);
        self::assertSame('data-vol', $result->name);
        self::assertSame(50, $result->size);

        $lastRequest = $this->httpClient->getLastRequest();
        self::assertSame('POST', $lastRequest['method']);

        $body = json_decode($lastRequest['body'], true);
        self::assertSame('fsn1', $body['location']);
        self::assertSame('ext4', $body['format']);
        self::assertArrayNotHasKey('server', $body);
    }

    public function testCreateVolumeWithServerWaitsForAction(): void
    {
        // POST /volumes
        $this->httpClient->queueResponse(201, json_encode([
            'volume' => [
                'id' => 500,
                'name' => 'data-vol',
                'size' => 10,
                'server' => 123,
            ],
            'action' => [
                'id' => 999,
                'status' => 'running',
            ],
        ], JSON_THROW_ON_ERROR));

        // GET /actions/999 — success
        $this->httpClient->queueResponse(200, json_encode([
            'action' => [
                'id' => 999,
                'status' => 'success',
            ],
        ], JSON_THROW_ON_ERROR));

        $volume = new Volume('data-vol', 10, '123');
        $result = $this->provider->createVolume($volume, 'fsn1');

        self::assertSame('500', $result->id);
        self::assertSame('123', $result->serverId);

        $body = json_decode($this->httpClient->getHistory()[0]['body'], true);
        self::assertSame(123, $body['server']);
    }

    public function testAttachVolume(): void
    {
        // POST /volumes/{id}/actions/attach
        $this->httpClient->queueResponse(200, json_encode([
            'action' => ['id' => 888, 'status' => 'running'],
        ], JSON_THROW_ON_ERROR));

        // GET /actions/888
        $this->httpClient->queueResponse(200, json_encode([
            'action' => ['id' => 888, 'status' => 'success'],
        ], JSON_THROW_ON_ERROR));

        $this->provider->attachVolume('500', '123');

        $history = $this->httpClient->getHistory();
        self::assertStringContainsString('/volumes/500/actions/attach', $history[0]['url']);

        $body = json_decode($history[0]['body'], true);
        self::assertSame(123, $body['server']);
    }

    public function testDetachVolume(): void
    {
        // POST /volumes/{id}/actions/detach
        $this->httpClient->queueResponse(200, json_encode([
            'action' => ['id' => 777, 'status' => 'running'],
        ], JSON_THROW_ON_ERROR));

        // GET /actions/777
        $this->httpClient->queueResponse(200, json_encode([
            'action' => ['id' => 777, 'status' => 'success'],
        ], JSON_THROW_ON_ERROR));

        $this->provider->detachVolume('500');

        $history = $this->httpClient->getHistory();
        self::assertStringContainsString('/volumes/500/actions/detach', $history[0]['url']);
    }

    public function testDeleteVolume(): void
    {
        $this->httpClient->queueResponse(204, '');

        $this->provider->deleteVolume('500');

        $lastRequest = $this->httpClient->getLastRequest();
        self::assertSame('DELETE', $lastRequest['method']);
        self::assertStringContainsString('/volumes/500', $lastRequest['url']);
    }

    public function testGetVolume(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'volume' => [
                'id' => 500,
                'name' => 'data-vol',
                'size' => 50,
                'server' => 123,
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->provider->getVolume('500');

        self::assertSame('500', $result->id);
        self::assertSame('data-vol', $result->name);
        self::assertSame(50, $result->size);
        self::assertSame('123', $result->serverId);
    }
}
