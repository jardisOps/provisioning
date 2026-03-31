<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

use InvalidArgumentException;

/**
 * Aggregate root — represents the complete provisioned infrastructure.
 */
final class Cluster
{
    /** @var Node[] */
    private array $nodes = [];

    /** @var Firewall[] */
    private array $firewalls = [];

    /** @var DnsRecord[] */
    private array $dnsRecords = [];

    /** @var Volume[] */
    private array $volumes = [];

    public function __construct(
        public readonly DeploymentMode $mode,
        public readonly string $name,
        public readonly string $region,
        public readonly SshKey $sshKey,
        public ?PrivateNetwork $network = null,
        public ?LoadBalancer $loadBalancer = null,
        public ?Certificate $certificate = null,
        public string $dnsZone = '',
        public string $placementGroupId = '',
        public int $nextIpSuffix = 2,
    ) {
    }

    public function addNode(Node $node): void
    {
        $this->nodes[$node->name] = $node;
    }

    public function removeNode(string $name): void
    {
        unset($this->nodes[$name]);
    }

    public function getNode(string $name): Node
    {
        if (!isset($this->nodes[$name])) {
            throw new InvalidArgumentException("Node '{$name}' not found");
        }

        return $this->nodes[$name];
    }

    /** @return Node[] */
    public function getNodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return Node[] */
    public function getNodesByRole(NodeRole $role): array
    {
        return array_values(
            array_filter($this->nodes, static fn(Node $n): bool => $n->role === $role)
        );
    }

    public function addFirewall(Firewall $firewall): void
    {
        $this->firewalls[$firewall->name] = $firewall;
    }

    /** @return Firewall[] */
    public function getFirewalls(): array
    {
        return array_values($this->firewalls);
    }

    public function addDnsRecord(DnsRecord $record): void
    {
        $this->dnsRecords[] = $record;
    }

    /** @return DnsRecord[] */
    public function getDnsRecords(): array
    {
        return $this->dnsRecords;
    }

    public function addVolume(Volume $volume): void
    {
        $this->volumes[] = $volume;
    }

    public function getVolumeForNode(string $nodeName): ?Volume
    {
        foreach ($this->volumes as $volume) {
            if ($volume->name === $nodeName . '-data') {
                return $volume;
            }
        }

        return null;
    }

    public function removeVolume(string $volumeName): void
    {
        $this->volumes = array_values(
            array_filter($this->volumes, static fn(Volume $v): bool => $v->name !== $volumeName)
        );
    }

    /** @return Volume[] */
    public function getVolumes(): array
    {
        return $this->volumes;
    }

    public function validate(): void
    {
        if ($this->name === '') {
            throw new InvalidArgumentException('Cluster name must not be empty');
        }

        if ($this->region === '') {
            throw new InvalidArgumentException('Region must not be empty');
        }

        if ($this->mode === DeploymentMode::Cluster) {
            $serverNodes = $this->getNodesByRole(NodeRole::Server);
            if ($serverNodes === []) {
                throw new InvalidArgumentException('Cluster mode requires at least one server node');
            }

            if ($this->network === null) {
                throw new InvalidArgumentException('Cluster mode requires a private network');
            }
        }
    }
}
