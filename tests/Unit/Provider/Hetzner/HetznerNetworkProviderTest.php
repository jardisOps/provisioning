<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Hetzner;

use JardisOps\Provisioning\Support\Data\PrivateNetwork;
use JardisOps\Provisioning\Provider\Hetzner\HetznerNetworkProvider;
use PHPUnit\Framework\TestCase;

final class HetznerNetworkProviderTest extends TestCase
{
    private MockHttpClient $httpClient;
    private HetznerNetworkProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();
        $api = HetznerTestHelper::createApiClient($this->httpClient);
        $this->provider = new HetznerNetworkProvider($api);
    }

    public function testCreateNetwork(): void
    {
        // Response for POST /networks
        $this->httpClient->queueResponse(201, json_encode([
            'network' => ['id' => 67890, 'name' => 'test-net', 'ip_range' => '10.0.1.0/24'],
        ], JSON_THROW_ON_ERROR));

        // Response for POST /networks/{id}/actions/add_subnet
        $this->httpClient->queueResponse(201, json_encode(['action' => ['id' => 1]], JSON_THROW_ON_ERROR));

        $network = new PrivateNetwork('test-net', '10.0.1.0/24', 'eu-central');
        $result = $this->provider->createNetwork($network);

        self::assertSame('67890', $result->id);
        self::assertSame('test-net', $result->name);
        self::assertSame('10.0.1.0/24', $result->subnet);
    }

    public function testDeleteNetwork(): void
    {
        $this->httpClient->queueResponse(204, '');

        $this->provider->deleteNetwork('67890');

        $request = $this->httpClient->getLastRequest();
        self::assertSame('DELETE', $request['method']);
    }

    public function testAttachServer(): void
    {
        $this->httpClient->queueResponse(201, json_encode(['action' => ['id' => 1]], JSON_THROW_ON_ERROR));

        $this->provider->attachServer('67890', '12345', '10.0.1.1');

        self::assertCount(1, $this->httpClient->getHistory());
    }

    public function testGetNetwork(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'network' => [
                'id' => 67890,
                'name' => 'test-net',
                'ip_range' => '10.0.1.0/24',
                'subnets' => [['network_zone' => 'eu-central']],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->provider->getNetwork('67890');

        self::assertSame('67890', $result->id);
        self::assertSame('eu-central', $result->zone);
    }
}
