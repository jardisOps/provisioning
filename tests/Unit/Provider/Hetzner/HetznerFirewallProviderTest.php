<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Hetzner;

use JardisOps\Provisioning\Support\Data\Firewall;
use JardisOps\Provisioning\Support\Data\FirewallRule;
use JardisOps\Provisioning\Provider\Hetzner\HetznerFirewallProvider;
use PHPUnit\Framework\TestCase;

final class HetznerFirewallProviderTest extends TestCase
{
    private MockHttpClient $httpClient;
    private HetznerFirewallProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();
        $api = HetznerTestHelper::createApiClient($this->httpClient);
        $this->provider = new HetznerFirewallProvider($api);
    }

    public function testCreateFirewall(): void
    {
        $this->httpClient->queueResponse(201, json_encode([
            'firewall' => ['id' => 55555, 'name' => 'test-ext'],
        ], JSON_THROW_ON_ERROR));

        $firewall = new Firewall('test-ext', 'external', [
            new FirewallRule('in', 'tcp', '443'),
            new FirewallRule('in', 'tcp', '80'),
        ]);

        $result = $this->provider->createFirewall($firewall);

        self::assertSame('55555', $result->id);
        self::assertSame('test-ext', $result->name);
        self::assertCount(2, $result->rules);
    }

    public function testApplyToServers(): void
    {
        $this->httpClient->queueResponse(201, json_encode(
            ['actions' => [['id' => 1]]],
            JSON_THROW_ON_ERROR,
        ));

        $this->provider->applyToServers('55555', ['12345', '12346']);

        self::assertCount(1, $this->httpClient->getHistory());
    }

    public function testGetFirewall(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'firewall' => [
                'id' => 55555,
                'name' => 'test-ext',
                'rules' => [
                    ['direction' => 'in', 'protocol' => 'tcp', 'port' => '443', 'source_ips' => ['0.0.0.0/0']],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->provider->getFirewall('55555');

        self::assertSame('55555', $result->id);
        self::assertCount(1, $result->rules);
        self::assertSame('443', $result->rules[0]->port);
    }

    public function testDeleteFirewall(): void
    {
        $this->httpClient->queueResponse(204, '');

        $this->provider->deleteFirewall('55555');

        $request = $this->httpClient->getLastRequest();
        self::assertSame('DELETE', $request['method']);
    }
}
