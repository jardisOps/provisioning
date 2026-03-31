<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Integration\Provider\Hetzner;

use JardisOps\Provisioning\Support\Data\Node;
use JardisOps\Provisioning\Support\Data\NodeRole;
use JardisOps\Provisioning\Support\Data\SshKey;
use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerApiClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerServerProvider;
use PHPUnit\Framework\TestCase;

/**
 * Integration test against live Hetzner Cloud API.
 *
 * Requires HETZNER_API_TOKEN in tests/fixtures/hetzner/.env.test.local
 */
final class HetznerServerProviderIntegrationTest extends TestCase
{
    use HetznerTestConfig;

    private ?HetznerServerProvider $provider = null;
    private ?string $createdServerId = null;

    protected function setUp(): void
    {
        $this->loadHetznerConfig();
        $token = $this->requireToken();

        $api = new HetznerApiClient(new HttpClient(), $token);
        $this->provider = new HetznerServerProvider($api);
    }

    protected function tearDown(): void
    {
        if ($this->createdServerId !== null && $this->provider !== null) {
            try {
                $this->provider->deleteServer($this->createdServerId);
            } catch (\Throwable) {
                // Best effort cleanup
            }
        }
    }

    public function testCreateGetAndDeleteServer(): void
    {
        $provider = $this->provider;
        self::assertNotNull($provider);

        $prefix = (string) ($this->config['TEST_RESOURCE_PREFIX'] ?? 'prov-test');
        $region = (string) ($this->config['HETZNER_REGION'] ?? 'nbg1');
        $image = (string) ($this->config['HETZNER_IMAGE'] ?? 'ubuntu-24.04');
        $serverType = (string) ($this->config['SERVER_TYPE'] ?? 'cpx22');

        // 1. Server erstellen
        $node = new Node("{$prefix}-server", NodeRole::Server, $serverType);

        $created = $provider->createServer(
            $node,
            $region,
            $image,
            new SshKey('', '', ''),
        );

        $this->createdServerId = $created->id;

        self::assertNotEmpty($created->id, 'Server ID should not be empty');
        self::assertSame("{$prefix}-server", $created->name);
        self::assertNotEmpty($created->publicIp, 'Server should have a public IP');

        // 2. Server abfragen
        $fetched = $provider->getServer($created->id);

        self::assertSame($created->id, $fetched->id);
        self::assertSame("{$prefix}-server", $fetched->name);

        // 3. Server loeschen
        $provider->deleteServer($created->id);
        $this->createdServerId = null;

        $this->addToAssertionCount(1);
    }
}
