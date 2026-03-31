<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Service\State;

use JardisOps\Provisioning\Support\Data\Cluster;
use JardisOps\Provisioning\Support\Data\DeploymentMode;
use JardisOps\Provisioning\Support\Data\DnsRecord;
use JardisOps\Provisioning\Support\Data\Firewall;
use JardisOps\Provisioning\Support\Data\FirewallRule;
use JardisOps\Provisioning\Support\Data\LoadBalancer;
use JardisOps\Provisioning\Support\Data\Node;
use JardisOps\Provisioning\Support\Data\NodeRole;
use JardisOps\Provisioning\Support\Data\NodeStatus;
use JardisOps\Provisioning\Support\Data\PrivateNetwork;
use JardisOps\Provisioning\Support\Data\SshKey;
use JardisOps\Provisioning\Service\State\StateManager;
use PHPUnit\Framework\TestCase;

final class StateManagerTest extends TestCase
{
    private string $tmpDir;
    private StateManager $manager;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/prov-state-test-' . uniqid();
        mkdir($this->tmpDir);
        $this->manager = new StateManager($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $stateFile = $this->tmpDir . '/.provision-state.json';
        if (file_exists($stateFile)) {
            unlink($stateFile);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testExistsReturnsFalseInitially(): void
    {
        self::assertFalse($this->manager->exists());
    }

    public function testSaveAndLoad(): void
    {
        $cluster = $this->createTestCluster();
        $this->manager->save($cluster, 'hetzner', 'inwx');

        self::assertTrue($this->manager->exists());

        $loaded = $this->manager->load();

        self::assertSame(DeploymentMode::Cluster, $loaded->mode);
        self::assertSame('test-cluster', $loaded->name);
        self::assertSame('fsn1', $loaded->region);
        self::assertSame('999', $loaded->sshKey->providerId);
        self::assertCount(2, $loaded->getNodes());
        self::assertSame('22222', $loaded->getNode('server-1')->id);
        self::assertSame(NodeStatus::Running, $loaded->getNode('server-1')->status);
        self::assertNotNull($loaded->network);
        self::assertSame('67890', $loaded->network->id);
        self::assertCount(2, $loaded->getFirewalls());
        self::assertNotNull($loaded->loadBalancer);
        self::assertSame('77777', $loaded->loadBalancer->id);
        self::assertCount(1, $loaded->getDnsRecords());
    }

    public function testDelete(): void
    {
        $cluster = $this->createTestCluster();
        $this->manager->save($cluster, 'hetzner', 'inwx');

        self::assertTrue($this->manager->exists());

        $this->manager->delete();

        self::assertFalse($this->manager->exists());
    }

    public function testLoadThrowsWhenNoFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->manager->load();
    }

    public function testSavePreservesCreatedAt(): void
    {
        $cluster = $this->createTestCluster();
        $this->manager->save($cluster, 'hetzner', 'inwx');

        $firstContent = file_get_contents($this->tmpDir . '/.provision-state.json');
        self::assertNotFalse($firstContent);
        /** @var array<string, mixed> $first */
        $first = json_decode($firstContent, true);
        $createdAt = $first['created_at'];

        // Save again
        $this->manager->save($cluster, 'hetzner', 'inwx');

        $secondContent = file_get_contents($this->tmpDir . '/.provision-state.json');
        self::assertNotFalse($secondContent);
        /** @var array<string, mixed> $second */
        $second = json_decode($secondContent, true);

        self::assertSame($createdAt, $second['created_at']);
    }

    private function createTestCluster(): Cluster
    {
        $cluster = new Cluster(
            DeploymentMode::Cluster,
            'test-cluster',
            'fsn1',
            new SshKey('test-key', '/path/to/key.pub', '999'),
            new PrivateNetwork('test-net', '10.0.1.0/24', 'eu-central', '67890'),
        );

        $server = new Node('server-1', NodeRole::Server, 'cpx31', '49.12.1.1', '10.0.1.1', NodeStatus::Running, '22222');
        $agent = new Node('agent-1', NodeRole::Agent, 'cpx31', '49.12.1.2', '10.0.1.2', NodeStatus::Running, '33333');
        $cluster->addNode($server);
        $cluster->addNode($agent);

        $cluster->addFirewall(new Firewall('test-external', 'external', [
            new FirewallRule('in', 'tcp', '443'),
        ], '55555'));
        $cluster->addFirewall(new Firewall('test-internal', 'internal', [], '66666'));

        $cluster->loadBalancer = new LoadBalancer('test-lb', 'lb11', '49.12.2.2', ['agent-1'], id: '77777');

        $cluster->addDnsRecord(new DnsRecord('api', 'A', '49.12.2.2', 300, '88881'));

        return $cluster;
    }
}
