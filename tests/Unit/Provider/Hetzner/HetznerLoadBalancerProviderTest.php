<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Hetzner;

use JardisOps\Provisioning\Support\Data\LoadBalancer;
use JardisOps\Provisioning\Provider\Hetzner\HetznerLoadBalancerProvider;
use PHPUnit\Framework\TestCase;

final class HetznerLoadBalancerProviderTest extends TestCase
{
    private MockHttpClient $httpClient;
    private HetznerLoadBalancerProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();
        $api = HetznerTestHelper::createApiClient($this->httpClient);
        $this->provider = new HetznerLoadBalancerProvider($api);
    }

    public function testCreateLoadBalancer(): void
    {
        // 1. POST /load_balancers
        $this->httpClient->queueResponse(201, json_encode([
            'load_balancer' => [
                'id' => 77777,
                'name' => 'test-lb',
                'public_net' => ['ipv4' => ['ip' => '49.12.2.2']],
            ],
        ], JSON_THROW_ON_ERROR));

        // 2. POST /load_balancers/77777/actions/attach_to_network
        $this->httpClient->queueResponse(201, json_encode(
            ['action' => ['id' => 1]],
            JSON_THROW_ON_ERROR,
        ));

        // 3. GET /load_balancers/77777/actions/1 (poll action status)
        $this->httpClient->queueResponse(200, json_encode(
            ['action' => ['id' => 1, 'status' => 'success']],
            JSON_THROW_ON_ERROR,
        ));

        $lb = new LoadBalancer('test-lb', 'lb11');
        $result = $this->provider->createLoadBalancer($lb, 'fsn1', '67890');

        self::assertSame('77777', $result->id);
        self::assertSame('49.12.2.2', $result->publicIp);
        self::assertSame('test-lb', $result->name);
    }

    public function testAddTarget(): void
    {
        $this->httpClient->queueResponse(201, json_encode(
            ['action' => ['id' => 1]],
            JSON_THROW_ON_ERROR,
        ));

        $this->provider->addTarget('77777', '12345');

        self::assertCount(1, $this->httpClient->getHistory());
    }

    public function testRemoveTarget(): void
    {
        $this->httpClient->queueResponse(201, json_encode(
            ['action' => ['id' => 1]],
            JSON_THROW_ON_ERROR,
        ));

        $this->provider->removeTarget('77777', '12345');

        self::assertCount(1, $this->httpClient->getHistory());
    }

    public function testGetLoadBalancer(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'load_balancer' => [
                'id' => 77777,
                'name' => 'test-lb',
                'load_balancer_type' => ['name' => 'lb11'],
                'public_net' => ['ipv4' => ['ip' => '49.12.2.2']],
                'targets' => [
                    ['server' => ['id' => 12345]],
                    ['server' => ['id' => 12346]],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->provider->getLoadBalancer('77777');

        self::assertSame('77777', $result->id);
        self::assertCount(2, $result->targets);
    }

    public function testDeleteLoadBalancer(): void
    {
        $this->httpClient->queueResponse(204, '');

        $this->provider->deleteLoadBalancer('77777');

        $request = $this->httpClient->getLastRequest();
        self::assertSame('DELETE', $request['method']);
    }
}
