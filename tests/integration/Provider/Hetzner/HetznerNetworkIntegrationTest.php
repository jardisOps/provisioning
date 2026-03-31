<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Integration\Provider\Hetzner;

use JardisOps\Provisioning\Provider\Hetzner\HetznerApiClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerNetworkProvider;
use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Support\Data\PrivateNetwork;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: Hetzner private network lifecycle.
 *
 * Creates a network with subnet, verifies, then deletes.
 * No server needed for basic create/delete.
 */
final class HetznerNetworkIntegrationTest extends TestCase
{
    use HetznerTestConfig;

    private ?HetznerNetworkProvider $provider = null;
    private string $networkId = '';

    protected function setUp(): void
    {
        $this->loadHetznerConfig();
        $token = $this->requireToken();

        $api = new HetznerApiClient(new HttpClient(), $token);
        $this->provider = new HetznerNetworkProvider($api);
    }

    protected function tearDown(): void
    {
        if ($this->networkId !== '' && $this->provider !== null) {
            try {
                $this->provider->deleteNetwork($this->networkId);
            } catch (\Throwable) {
                // Best effort cleanup
            }
        }
    }

    public function testNetworkLifecycle(): void
    {
        $provider = $this->provider;
        self::assertNotNull($provider);

        $prefix = (string) ($this->config['TEST_RESOURCE_PREFIX'] ?? 'prov-test');

        // 1. Create network
        echo "\n  === CREATE NETWORK ===\n";

        $network = $provider->createNetwork(new PrivateNetwork(
            "{$prefix}-net-test",
            '10.0.88.0/24',
            'eu-central',
        ));

        $this->networkId = $network->id;

        self::assertNotEmpty($network->id);
        self::assertSame("{$prefix}-net-test", $network->name);
        self::assertSame('10.0.88.0/24', $network->subnet);
        echo "  Network: {$network->name} (ID: {$network->id})\n";

        // 2. Get network
        $fetched = $provider->getNetwork($network->id);

        self::assertSame($network->id, $fetched->id);
        self::assertSame($network->name, $fetched->name);
        self::assertSame('10.0.88.0/24', $fetched->subnet);
        self::assertSame('eu-central', $fetched->zone);
        echo "  Verified: {$fetched->name} — {$fetched->subnet} ({$fetched->zone})\n";

        // 3. Delete network
        echo "\n  === DELETE NETWORK ===\n";

        $provider->deleteNetwork($network->id);
        $this->networkId = '';

        echo "  Deleted: {$network->name}\n";
        echo "\n  === NETWORK TEST COMPLETE ===\n";
    }
}
