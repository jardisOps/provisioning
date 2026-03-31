<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Integration\Provider\Hetzner;

use JardisOps\Provisioning\Provider\Hetzner\HetznerApiClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerLoadBalancerProvider;
use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Support\Data\LoadBalancer;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: Hetzner load balancer lifecycle.
 *
 * Creates a load balancer (without network), verifies, then deletes.
 * Target operations require a running server — tested in E2E instead.
 */
final class HetznerLoadBalancerIntegrationTest extends TestCase
{
    use HetznerTestConfig;

    private ?HetznerLoadBalancerProvider $provider = null;
    private string $lbId = '';

    protected function setUp(): void
    {
        $this->loadHetznerConfig();
        $token = $this->requireToken();

        $api = new HetznerApiClient(new HttpClient(), $token);
        $this->provider = new HetznerLoadBalancerProvider($api);
    }

    protected function tearDown(): void
    {
        if ($this->lbId !== '' && $this->provider !== null) {
            try {
                $this->provider->deleteLoadBalancer($this->lbId);
            } catch (\Throwable) {
                // Best effort cleanup
            }
        }
    }

    public function testLoadBalancerLifecycle(): void
    {
        $provider = $this->provider;
        self::assertNotNull($provider);

        $prefix = (string) ($this->config['TEST_RESOURCE_PREFIX'] ?? 'prov-test');
        $region = (string) ($this->config['HETZNER_REGION'] ?? 'nbg1');
        $lbType = (string) ($this->config['LOADBALANCER_TYPE'] ?? 'lb11');

        // 1. Create load balancer (without network)
        echo "\n  === CREATE LOAD BALANCER ===\n";

        $lb = new LoadBalancer(
            name: "{$prefix}-lb-test",
            type: $lbType,
            algorithm: 'round_robin',
            healthCheckProtocol: 'http',
            healthCheckPort: 80,
            healthCheckPath: '/health',
            healthCheckInterval: 15,
            healthCheckTimeout: 10,
            healthCheckRetries: 3,
        );

        $created = $provider->createLoadBalancer($lb, $region, '');
        $this->lbId = $created->id;

        self::assertNotEmpty($created->id);
        self::assertSame("{$prefix}-lb-test", $created->name);
        self::assertNotEmpty($created->publicIp);
        echo "  LB: {$created->name} — IP: {$created->publicIp} (ID: {$created->id})\n";

        // 2. Get load balancer
        $fetched = $provider->getLoadBalancer($created->id);

        self::assertSame($created->id, $fetched->id);
        self::assertSame($created->name, $fetched->name);
        self::assertSame($created->publicIp, $fetched->publicIp);
        echo "  Verified: {$fetched->name} — {$fetched->publicIp}\n";

        // 3. Delete load balancer
        echo "\n  === DELETE LOAD BALANCER ===\n";

        $provider->deleteLoadBalancer($created->id);
        $this->lbId = '';

        echo "  Deleted: {$created->name}\n";
        echo "\n  === LOAD BALANCER TEST COMPLETE ===\n";
    }
}
