<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Integration\Provider\Hetzner;

use JardisOps\Provisioning\Provider\Hetzner\HetznerApiClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerFirewallProvider;
use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Support\Data\Firewall;
use JardisOps\Provisioning\Support\Data\FirewallRule;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: Hetzner firewall lifecycle.
 *
 * Creates a firewall with rules, verifies, then deletes.
 * No server needed for basic create/delete.
 */
final class HetznerFirewallIntegrationTest extends TestCase
{
    use HetznerTestConfig;

    private ?HetznerFirewallProvider $provider = null;
    private string $firewallId = '';

    protected function setUp(): void
    {
        $this->loadHetznerConfig();
        $token = $this->requireToken();

        $api = new HetznerApiClient(new HttpClient(), $token);
        $this->provider = new HetznerFirewallProvider($api);
    }

    protected function tearDown(): void
    {
        if ($this->firewallId !== '' && $this->provider !== null) {
            try {
                $this->provider->deleteFirewall($this->firewallId);
            } catch (\Throwable) {
                // Best effort cleanup
            }
        }
    }

    public function testFirewallLifecycle(): void
    {
        $provider = $this->provider;
        self::assertNotNull($provider);

        $prefix = (string) ($this->config['TEST_RESOURCE_PREFIX'] ?? 'prov-test');

        // 1. Create firewall
        echo "\n  === CREATE FIREWALL ===\n";

        $rules = [
            new FirewallRule('in', 'tcp', '22', ['0.0.0.0/0', '::/0']),
            new FirewallRule('in', 'tcp', '80', ['0.0.0.0/0', '::/0']),
            new FirewallRule('in', 'tcp', '443', ['0.0.0.0/0', '::/0']),
        ];

        $firewall = $provider->createFirewall(new Firewall(
            "{$prefix}-fw-test",
            'external',
            $rules,
        ));

        $this->firewallId = $firewall->id;

        self::assertNotEmpty($firewall->id);
        self::assertSame("{$prefix}-fw-test", $firewall->name);
        self::assertCount(3, $firewall->rules);
        echo "  Firewall: {$firewall->name} (ID: {$firewall->id})\n";

        // 2. Get firewall
        $fetched = $provider->getFirewall($firewall->id);

        self::assertSame($firewall->id, $fetched->id);
        self::assertSame($firewall->name, $fetched->name);
        self::assertCount(3, $fetched->rules);
        self::assertSame('22', $fetched->rules[0]->port);
        self::assertSame('80', $fetched->rules[1]->port);
        self::assertSame('443', $fetched->rules[2]->port);
        echo "  Verified: {$fetched->name} — " . count($fetched->rules) . " rules\n";

        // 3. Delete firewall
        echo "\n  === DELETE FIREWALL ===\n";

        $provider->deleteFirewall($firewall->id);
        $this->firewallId = '';

        echo "  Deleted: {$firewall->name}\n";
        echo "\n  === FIREWALL TEST COMPLETE ===\n";
    }
}
