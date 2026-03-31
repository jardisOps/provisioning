<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Integration\Provider\Hetzner;

use JardisOps\Provisioning\Support\Data\Node;
use JardisOps\Provisioning\Support\Data\NodeRole;
use JardisOps\Provisioning\Support\Data\NodeStatus;
use JardisOps\Provisioning\Support\Data\SshKey;
use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerApiClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerServerProvider;
use PHPUnit\Framework\TestCase;

/**
 * Integration test: 4-node cluster at Hetzner.
 *
 * Creates 1 server-node + 3 agent-nodes, waits until running, deletes all.
 * Requires HETZNER_API_TOKEN in tests/fixtures/hetzner/.env.test.local
 */
final class HetznerClusterIntegrationTest extends TestCase
{
    use HetznerTestConfig;

    private ?HetznerServerProvider $provider = null;

    /** @var string[] */
    private array $createdServerIds = [];

    protected function setUp(): void
    {
        $this->loadHetznerConfig();
        $token = $this->requireToken();

        $api = new HetznerApiClient(new HttpClient(), $token);
        $this->provider = new HetznerServerProvider($api);
    }

    protected function tearDown(): void
    {
        if ($this->provider === null) {
            return;
        }

        foreach ($this->createdServerIds as $id) {
            try {
                $this->provider->deleteServer($id);
            } catch (\Throwable) {
                // Best effort cleanup
            }
        }
    }

    public function testFourNodeCluster(): void
    {
        $provider = $this->provider;
        self::assertNotNull($provider);

        $prefix = (string) ($this->config['TEST_RESOURCE_PREFIX'] ?? 'prov-test');
        $region = (string) ($this->config['HETZNER_REGION'] ?? 'nbg1');
        $image = (string) ($this->config['HETZNER_IMAGE'] ?? 'ubuntu-24.04');
        $serverType = (string) ($this->config['SERVER_TYPE'] ?? 'cpx22');

        $nodes = [
            new Node("{$prefix}-server-1", NodeRole::Server, $serverType),
            new Node("{$prefix}-agent-1", NodeRole::Agent, $serverType),
            new Node("{$prefix}-agent-2", NodeRole::Agent, $serverType),
            new Node("{$prefix}-agent-3", NodeRole::Agent, $serverType),
        ];

        $created = [];

        // 1. Alle Server erstellen
        foreach ($nodes as $node) {
            $result = $provider->createServer(
                $node,
                $region,
                $image,
                new SshKey('', '', ''),
            );

            $this->createdServerIds[] = $result->id;
            $created[] = $result;

            echo "  Created {$result->name} (ID: {$result->id})\n";
        }

        self::assertCount(4, $created);

        // 2. Warten bis alle laufen
        echo "\n  Waiting for all nodes to reach 'running'...\n";

        foreach ($created as $i => $node) {
            $ready = $provider->waitUntilRunning($node->id);
            $created[$i] = new Node(
                $ready->name,
                $nodes[$i]->role,
                $ready->serverType,
                $ready->publicIp,
                $ready->privateIp,
                $ready->status,
                $ready->id,
            );

            echo "  {$ready->name}  {$ready->status->value}  {$ready->publicIp}\n";
        }

        // 3. Alle muessen running sein
        foreach ($created as $node) {
            self::assertSame(NodeStatus::Running, $node->status);
            self::assertNotEmpty($node->publicIp);
        }

        // 4. Rollen pruefen
        $servers = array_filter($created, static fn(Node $n) => $n->role === NodeRole::Server);
        $agents = array_filter($created, static fn(Node $n) => $n->role === NodeRole::Agent);
        self::assertCount(1, $servers);
        self::assertCount(3, $agents);

        // 5. Alle loeschen
        echo "\n  Deleting all nodes...\n";

        foreach ($this->createdServerIds as $id) {
            $provider->deleteServer($id);
            echo "  Deleted {$id}\n";
        }

        $this->createdServerIds = [];
    }
}
