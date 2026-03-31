<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Service\State;

use JardisOps\Provisioning\Support\Data\Cluster;
use JardisOps\Provisioning\Support\Data\DeploymentMode;
use JardisOps\Provisioning\Support\Data\DnsRecord;
use JardisOps\Provisioning\Support\Data\Firewall;
use JardisOps\Provisioning\Support\Data\LoadBalancer;
use JardisOps\Provisioning\Support\Data\Node;
use JardisOps\Provisioning\Support\Data\NodeRole;
use JardisOps\Provisioning\Support\Data\NodeStatus;
use JardisOps\Provisioning\Support\Data\PrivateNetwork;
use JardisOps\Provisioning\Support\Data\SshKey;
use JardisOps\Provisioning\Support\Data\Volume;
use RuntimeException;

/**
 * Reads and writes the provisioning state file.
 */
final class StateManager
{
    private const VERSION = 1;
    private const FILENAME = '.provision-state.json';

    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function exists(): bool
    {
        return file_exists($this->getFilePath());
    }

    public function save(Cluster $cluster, string $provider, string $dnsProvider): void
    {
        $state = [
            'version' => self::VERSION,
            'mode' => $cluster->mode->value,
            'cluster_name' => $cluster->name,
            'provider' => $provider,
            'dns_provider' => $dnsProvider,
            'region' => $cluster->region,
            'created_at' => $this->exists() ? $this->readRaw()['created_at'] : date('c'),
            'updated_at' => date('c'),
            'resources' => $this->serializeResources($cluster),
        ];

        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $written = file_put_contents($this->getFilePath(), $json . "\n");
        if ($written === false) {
            throw new RuntimeException('Failed to write state file: ' . $this->getFilePath());
        }
    }

    public function load(): Cluster
    {
        $state = $this->readRaw();

        /** @var array<string, mixed> $resources */
        $resources = $state['resources'] ?? [];

        /** @var array<string, mixed> $sshData */
        $sshData = $resources['ssh_key'] ?? [];
        $sshKey = new SshKey(
            (string) ($sshData['name'] ?? ''),
            (string) ($sshData['public_key_path'] ?? ''),
            (string) ($sshData['id'] ?? ''),
        );

        $network = null;
        /** @var array<string, mixed> $netData */
        $netData = $resources['network'] ?? [];
        if ($netData !== []) {
            $network = new PrivateNetwork(
                (string) $netData['name'],
                (string) $netData['subnet'],
                (string) $netData['zone'],
                (string) $netData['id'],
            );
        }

        $cluster = new Cluster(
            DeploymentMode::from((string) $state['mode']),
            (string) $state['cluster_name'],
            (string) $state['region'],
            $sshKey,
            $network,
        );

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = $resources['nodes'] ?? [];
        foreach ($nodes as $nodeData) {
            $cluster->addNode(new Node(
                name: (string) $nodeData['name'],
                role: NodeRole::from((string) $nodeData['role']),
                serverType: (string) $nodeData['type'],
                publicIp: (string) ($nodeData['public_ip'] ?? ''),
                privateIp: (string) ($nodeData['private_ip'] ?? ''),
                status: NodeStatus::from((string) ($nodeData['status'] ?? 'pending')),
                id: (string) $nodeData['id'],
            ));
        }

        /** @var array<int, array<string, mixed>> $firewalls */
        $firewalls = $resources['firewalls'] ?? [];
        foreach ($firewalls as $fwData) {
            $cluster->addFirewall(new Firewall(
                (string) $fwData['name'],
                (string) $fwData['type'],
                [],
                (string) $fwData['id'],
            ));
        }

        /** @var array<string, mixed> $lbData */
        $lbData = $resources['load_balancer'] ?? [];
        if ($lbData !== []) {
            /** @var string[] $targets */
            $targets = $lbData['targets'] ?? [];
            $cluster->loadBalancer = new LoadBalancer(
                (string) $lbData['name'],
                (string) ($lbData['type'] ?? ''),
                (string) ($lbData['ip'] ?? ''),
                $targets,
                id: (string) $lbData['id'],
            );
        }

        /** @var array<int, array<string, mixed>> $dnsRecords */
        $dnsRecords = $resources['dns_records'] ?? [];
        foreach ($dnsRecords as $recData) {
            $cluster->addDnsRecord(new DnsRecord(
                (string) $recData['subdomain'],
                (string) $recData['type'],
                (string) $recData['target'],
                (int) ($recData['ttl'] ?? 300),
                (string) $recData['id'],
            ));
        }

        /** @var array<int, array<string, mixed>> $volumes */
        $volumes = $resources['volumes'] ?? [];
        foreach ($volumes as $volData) {
            $cluster->addVolume(new Volume(
                name: (string) $volData['name'],
                size: (int) $volData['size'],
                serverId: (string) ($volData['server_id'] ?? ''),
                id: (string) $volData['id'],
            ));
        }

        $cluster->dnsZone = (string) ($resources['dns_zone'] ?? '');
        $cluster->nextIpSuffix = (int) ($resources['next_ip_suffix'] ?? 2);

        /** @var array<string, mixed> $pgData */
        $pgData = $resources['placement_group'] ?? [];
        if ($pgData !== []) {
            $cluster->placementGroupId = (string) $pgData['id'];
        }

        return $cluster;
    }

    public function delete(): void
    {
        $path = $this->getFilePath();
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function getFilePath(): string
    {
        return rtrim($this->basePath, '/') . '/' . self::FILENAME;
    }

    /**
     * @return array<string, mixed>
     */
    private function readRaw(): array
    {
        $path = $this->getFilePath();
        if (!file_exists($path)) {
            throw new RuntimeException('State file not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Failed to read state file: ' . $path);
        }

        /** @var array<string, mixed> $state */
        $state = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeResources(Cluster $cluster): array
    {
        $resources = [];

        $resources['ssh_key'] = [
            'id' => $cluster->sshKey->providerId,
            'name' => $cluster->sshKey->name,
            'public_key_path' => $cluster->sshKey->publicKeyPath,
        ];

        $resources['nodes'] = array_map(static fn(Node $n): array => [
            'id' => $n->id,
            'name' => $n->name,
            'role' => $n->role->value,
            'type' => $n->serverType,
            'public_ip' => $n->publicIp,
            'private_ip' => $n->privateIp,
            'status' => $n->status->value,
        ], $cluster->getNodes());

        $resources['firewalls'] = array_map(static fn(Firewall $f): array => [
            'id' => $f->id,
            'name' => $f->name,
            'type' => $f->type,
        ], $cluster->getFirewalls());

        if ($cluster->network !== null) {
            $resources['network'] = [
                'id' => $cluster->network->id,
                'name' => $cluster->network->name,
                'subnet' => $cluster->network->subnet,
                'zone' => $cluster->network->zone,
            ];
        }

        if ($cluster->loadBalancer !== null) {
            $resources['load_balancer'] = [
                'id' => $cluster->loadBalancer->id,
                'name' => $cluster->loadBalancer->name,
                'type' => $cluster->loadBalancer->type,
                'ip' => $cluster->loadBalancer->publicIp,
                'targets' => $cluster->loadBalancer->targets,
            ];
        }

        $resources['dns_records'] = array_map(static fn(DnsRecord $r): array => [
            'id' => $r->id,
            'subdomain' => $r->subdomain,
            'type' => $r->type,
            'target' => $r->target,
            'ttl' => $r->ttl,
        ], $cluster->getDnsRecords());

        $resources['dns_zone'] = $cluster->dnsZone;
        $resources['next_ip_suffix'] = $cluster->nextIpSuffix;

        $resources['volumes'] = array_map(static fn(Volume $v): array => [
            'id' => $v->id,
            'name' => $v->name,
            'size' => $v->size,
            'server_id' => $v->serverId,
        ], $cluster->getVolumes());

        if ($cluster->placementGroupId !== '') {
            $resources['placement_group'] = [
                'id' => $cluster->placementGroupId,
            ];
        }

        return $resources;
    }
}
