<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Support\Data;

use InvalidArgumentException;
use JardisOps\Provisioning\Support\Data\Cluster;
use JardisOps\Provisioning\Support\Data\DeploymentMode;
use JardisOps\Provisioning\Support\Data\Node;
use JardisOps\Provisioning\Support\Data\NodeRole;
use JardisOps\Provisioning\Support\Data\NodeStatus;
use JardisOps\Provisioning\Support\Data\PrivateNetwork;
use JardisOps\Provisioning\Support\Data\SshKey;
use JardisOps\Provisioning\Support\Data\DnsRecord;
use JardisOps\Provisioning\Support\Data\Firewall;
use PHPUnit\Framework\TestCase;

final class ClusterTest extends TestCase
{
    private function createSshKey(): SshKey
    {
        return new SshKey('test-key', '/path/to/key.pub');
    }

    public function testSingleModeCreation(): void
    {
        $cluster = new Cluster(
            DeploymentMode::Single,
            'test-server',
            'fsn1',
            $this->createSshKey(),
        );

        self::assertSame(DeploymentMode::Single, $cluster->mode);
        self::assertSame('test-server', $cluster->name);
        self::assertSame('fsn1', $cluster->region);
        self::assertSame([], $cluster->getNodes());
    }

    public function testClusterModeCreation(): void
    {
        $cluster = new Cluster(
            DeploymentMode::Cluster,
            'test-cluster',
            'fsn1',
            $this->createSshKey(),
            new PrivateNetwork('net', '10.0.1.0/24', 'eu-central'),
        );

        self::assertSame(DeploymentMode::Cluster, $cluster->mode);
        self::assertNotNull($cluster->network);
    }

    public function testAddAndGetNodes(): void
    {
        $cluster = new Cluster(DeploymentMode::Single, 'test', 'fsn1', $this->createSshKey());

        $node = new Node('server-1', NodeRole::Server, 'cpx31');
        $cluster->addNode($node);

        self::assertCount(1, $cluster->getNodes());
        self::assertSame($node, $cluster->getNode('server-1'));
    }

    public function testRemoveNode(): void
    {
        $cluster = new Cluster(DeploymentMode::Single, 'test', 'fsn1', $this->createSshKey());
        $cluster->addNode(new Node('server-1', NodeRole::Server, 'cpx31'));
        $cluster->addNode(new Node('agent-1', NodeRole::Agent, 'cpx31'));

        $cluster->removeNode('agent-1');

        self::assertCount(1, $cluster->getNodes());
    }

    public function testGetNodeThrowsOnUnknown(): void
    {
        $cluster = new Cluster(DeploymentMode::Single, 'test', 'fsn1', $this->createSshKey());

        $this->expectException(InvalidArgumentException::class);
        $cluster->getNode('nonexistent');
    }

    public function testGetNodesByRole(): void
    {
        $cluster = new Cluster(DeploymentMode::Single, 'test', 'fsn1', $this->createSshKey());
        $cluster->addNode(new Node('server-1', NodeRole::Server, 'cpx31'));
        $cluster->addNode(new Node('agent-1', NodeRole::Agent, 'cpx31'));
        $cluster->addNode(new Node('agent-2', NodeRole::Agent, 'cpx31'));

        $agents = $cluster->getNodesByRole(NodeRole::Agent);

        self::assertCount(2, $agents);
    }

    public function testFirewalls(): void
    {
        $cluster = new Cluster(DeploymentMode::Single, 'test', 'fsn1', $this->createSshKey());
        $cluster->addFirewall(new Firewall('ext', 'external'));
        $cluster->addFirewall(new Firewall('int', 'internal'));

        self::assertCount(2, $cluster->getFirewalls());
    }

    public function testDnsRecords(): void
    {
        $cluster = new Cluster(DeploymentMode::Single, 'test', 'fsn1', $this->createSshKey());
        $cluster->addDnsRecord(new DnsRecord('api', 'A', '1.2.3.4'));
        $cluster->addDnsRecord(new DnsRecord('portal', 'A', '1.2.3.4'));

        self::assertCount(2, $cluster->getDnsRecords());
    }

    public function testValidateEmptyNameFails(): void
    {
        $cluster = new Cluster(DeploymentMode::Single, '', 'fsn1', $this->createSshKey());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name');
        $cluster->validate();
    }

    public function testValidateEmptyRegionFails(): void
    {
        $cluster = new Cluster(DeploymentMode::Single, 'test', '', $this->createSshKey());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Region');
        $cluster->validate();
    }

    public function testValidateClusterWithoutServerNodeFails(): void
    {
        $cluster = new Cluster(
            DeploymentMode::Cluster,
            'test',
            'fsn1',
            $this->createSshKey(),
            new PrivateNetwork('net', '10.0.1.0/24', 'eu-central'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('server node');
        $cluster->validate();
    }

    public function testValidateClusterWithoutNetworkFails(): void
    {
        $cluster = new Cluster(DeploymentMode::Cluster, 'test', 'fsn1', $this->createSshKey());
        $cluster->addNode(new Node('server-1', NodeRole::Server, 'cpx31'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('private network');
        $cluster->validate();
    }

    public function testValidateClusterPasses(): void
    {
        $cluster = new Cluster(
            DeploymentMode::Cluster,
            'test',
            'fsn1',
            $this->createSshKey(),
            new PrivateNetwork('net', '10.0.1.0/24', 'eu-central'),
        );
        $cluster->addNode(new Node('server-1', NodeRole::Server, 'cpx31'));

        $cluster->validate();
        $this->addToAssertionCount(1);
    }
}
