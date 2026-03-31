<?php

declare(strict_types=1);

namespace JardisOps\Provisioning;

use JardisOps\Provisioning\Support\Contract\CertificateProviderInterface;
use JardisOps\Provisioning\Support\Contract\DnsProviderInterface;
use JardisOps\Provisioning\Support\Contract\FirewallProviderInterface;
use JardisOps\Provisioning\Support\Contract\LoadBalancerProviderInterface;
use JardisOps\Provisioning\Support\Contract\NetworkProviderInterface;
use JardisOps\Provisioning\Support\Contract\ServerProviderInterface;
use JardisOps\Provisioning\Support\Data\Certificate;
use JardisOps\Provisioning\Support\Data\CloudInitScript;
use JardisOps\Provisioning\Support\Data\Cluster;
use JardisOps\Provisioning\Support\Data\DeploymentMode;
use JardisOps\Provisioning\Support\Data\DnsRecord;
use JardisOps\Provisioning\Support\Data\Firewall;
use JardisOps\Provisioning\Support\Data\FirewallRule;
use JardisOps\Provisioning\Support\Data\LoadBalancer;
use JardisOps\Provisioning\Support\Data\Node;
use JardisOps\Provisioning\Support\Data\NodeRole;
use JardisOps\Provisioning\Support\Data\PrivateNetwork;
use JardisOps\Provisioning\Support\Data\SshKey;
use JardisOps\Provisioning\Support\Data\Volume;
use JardisOps\Provisioning\Support\Contract\VolumeProviderInterface;
use JardisOps\Provisioning\Service\State\StateManager;

/**
 * Orchestrates the provider-independent provisioning workflow.
 */
final class Provisioner
{
    public function __construct(
        private readonly ServerProviderInterface $serverProvider,
        private readonly NetworkProviderInterface $networkProvider,
        private readonly FirewallProviderInterface $firewallProvider,
        private readonly LoadBalancerProviderInterface $lbProvider,
        private readonly ?DnsProviderInterface $dnsProvider,
        private readonly ?CertificateProviderInterface $certificateProvider,
        private readonly VolumeProviderInterface $volumeProvider,
        private readonly StateManager $stateManager,
        private readonly string $infraProvider,
        private readonly string $dnsProviderName,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function provision(array $config): Cluster
    {
        $mode = DeploymentMode::from((string) $config['PROVISION_MODE']);

        return $mode === DeploymentMode::Single
            ? $this->provisionSingle($config)
            : $this->provisionCluster($config);
    }

    public function deprovision(bool $deleteVolumes = false): void
    {
        $cluster = $this->stateManager->load();

        // DNS records
        if ($this->dnsProvider !== null && $cluster->dnsZone !== '') {
            foreach ($cluster->getDnsRecords() as $record) {
                if ($record->id !== '') {
                    $this->dnsProvider->deleteRecord($cluster->dnsZone, $record->id);
                }
            }
        }

        // Load balancer
        if ($cluster->loadBalancer !== null && $cluster->loadBalancer->id !== '') {
            $this->lbProvider->deleteLoadBalancer($cluster->loadBalancer->id);
        }

        // Remove firewalls from servers first
        $nodeIds = array_map(static fn(Node $n): string => $n->id, $cluster->getNodes());
        $nodeIds = array_filter($nodeIds, static fn(string $id): bool => $id !== '');
        foreach ($cluster->getFirewalls() as $firewall) {
            if ($firewall->id !== '' && $nodeIds !== []) {
                $this->firewallProvider->removeFromServers($firewall->id, array_values($nodeIds));
            }
        }

        // Volumes — detach first, then optionally delete
        foreach ($cluster->getVolumes() as $volume) {
            if ($volume->id !== '') {
                $this->volumeProvider->detachVolume($volume->id);
                if ($deleteVolumes) {
                    $this->volumeProvider->deleteVolume($volume->id);
                }
            }
        }

        // Nodes (agents first, then servers)
        $agents = $cluster->getNodesByRole(NodeRole::Agent);
        $servers = $cluster->getNodesByRole(NodeRole::Server);
        foreach ([...$agents, ...$servers] as $node) {
            if ($node->id !== '') {
                $this->serverProvider->deleteServer($node->id);
            }
        }

        // Firewalls (no longer attached to anything)
        foreach ($cluster->getFirewalls() as $firewall) {
            if ($firewall->id !== '') {
                $this->firewallProvider->deleteFirewall($firewall->id);
            }
        }

        // Network
        if ($cluster->network !== null && $cluster->network->id !== '') {
            $this->networkProvider->deleteNetwork($cluster->network->id);
        }

        // SSH key
        if ($cluster->sshKey->providerId !== '') {
            $this->serverProvider->deleteSshKey($cluster->sshKey->providerId);
        }

        $this->stateManager->delete();
    }

    public function status(): ?Cluster
    {
        if (!$this->stateManager->exists()) {
            return null;
        }

        return $this->stateManager->load();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function addNode(
        array $config,
        string $name,
        NodeRole $role,
        string $serverType,
        int $volumeSize = 0,
    ): Cluster {
        $cluster = $this->stateManager->load();
        $sshPublicKey = $this->readSshPublicKey((string) $config['SSH_KEY_PATH']);

        $cloudInit = $this->buildCloudInit($config, $sshPublicKey);
        $node = new Node($name, $role, $serverType);

        $node = $this->serverProvider->createServer(
            $node,
            $cluster->region,
            (string) $config['HETZNER_IMAGE'],
            $cluster->sshKey,
            $cloudInit->render(),
        );
        $node = $this->waitForNode($node);

        if ($cluster->network !== null) {
            $privateIp = $this->nextPrivateIp($cluster);
            $this->networkProvider->attachServer($cluster->network->id, $node->id, $privateIp);
            $node->privateIp = $privateIp;
        }

        // Apply firewalls
        foreach ($cluster->getFirewalls() as $firewall) {
            $this->firewallProvider->applyToServers($firewall->id, [$node->id]);
        }

        // Add to LB if agent
        if ($role === NodeRole::Agent && $cluster->loadBalancer !== null) {
            $this->lbProvider->addTarget($cluster->loadBalancer->id, $node->id);
            $cluster->loadBalancer->addTarget($node->name);
        }

        $cluster->addNode($node);

        // Create volume if requested
        if ($volumeSize > 0) {
            $volumeName = $node->name . '-data';
            $volume = $this->volumeProvider->createVolume(
                new Volume($volumeName, $volumeSize, $node->id),
                $cluster->region,
            );
            $cluster->addVolume($volume);
        }

        $this->stateManager->save($cluster, $this->infraProvider, $this->dnsProviderName);

        return $cluster;
    }

    public function removeNode(string $name, bool $deleteVolume = false): Cluster
    {
        $cluster = $this->stateManager->load();
        $node = $cluster->getNode($name);

        // Remove from LB
        if ($node->role === NodeRole::Agent && $cluster->loadBalancer !== null) {
            $this->lbProvider->removeTarget($cluster->loadBalancer->id, $node->id);
            $cluster->loadBalancer->removeTarget($node->name);
        }

        // Detach and optionally delete volume
        $volume = $cluster->getVolumeForNode($name);
        if ($volume !== null && $volume->id !== '') {
            $this->volumeProvider->detachVolume($volume->id);
            if ($deleteVolume) {
                $this->volumeProvider->deleteVolume($volume->id);
            }
            $cluster->removeVolume($volume->name);
        }

        // Remove firewalls
        foreach ($cluster->getFirewalls() as $firewall) {
            $this->firewallProvider->removeFromServers($firewall->id, [$node->id]);
        }

        // Detach from network
        if ($cluster->network !== null) {
            $this->networkProvider->detachServer($cluster->network->id, $node->id);
        }

        // Delete server
        $this->serverProvider->deleteServer($node->id);

        $cluster->removeNode($name);
        $this->stateManager->save($cluster, $this->infraProvider, $this->dnsProviderName);

        return $cluster;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionSingle(array $config): Cluster
    {
        $sshPublicKey = $this->readSshPublicKey((string) $config['SSH_KEY_PATH']);

        // 1. SSH key
        $sshKey = $this->serverProvider->registerSshKey(
            new SshKey((string) $config['SERVER_NAME'], (string) $config['SSH_KEY_PATH'])
        );

        // 2. Cloud-init
        $cloudInit = $this->buildCloudInit($config, $sshPublicKey);

        $cluster = new Cluster(
            DeploymentMode::Single,
            (string) $config['SERVER_NAME'],
            (string) $config['HETZNER_REGION'],
            $sshKey,
        );

        // 3. Server
        $node = $this->serverProvider->createServer(
            new Node((string) $config['SERVER_NAME'], NodeRole::Server, (string) $config['SERVER_TYPE']),
            $cluster->region,
            (string) $config['HETZNER_IMAGE'],
            $sshKey,
            $cloudInit->render(),
        );
        $node = $this->waitForNode($node);
        $cluster->addNode($node);

        // 4. External firewall
        $firewall = $this->createExternalFirewall($config, $cluster->name . '-external');
        $this->firewallProvider->applyToServers($firewall->id, [$node->id]);
        $cluster->addFirewall($firewall);

        // 5. Volume (optional)
        $this->createNodeVolume($config, $node, $cluster, '');

        // 6. DNS → server IP
        $this->createDnsRecords($config, $node->publicIp, $cluster);

        // 7. Save state
        $this->stateManager->save($cluster, $this->infraProvider, $this->dnsProviderName);

        return $cluster;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provisionCluster(array $config): Cluster
    {
        $sshPublicKey = $this->readSshPublicKey((string) $config['SSH_KEY_PATH']);
        $clusterName = (string) $config['CLUSTER_NAME'];

        // 1. SSH key
        $sshKey = $this->serverProvider->registerSshKey(
            new SshKey($clusterName, (string) $config['SSH_KEY_PATH'])
        );

        // 2. Cloud-init
        $cloudInit = $this->buildCloudInit($config, $sshPublicKey);

        // 3. Private network
        $network = $this->networkProvider->createNetwork(new PrivateNetwork(
            (string) $config['PRIVATE_NETWORK_NAME'],
            (string) $config['PRIVATE_NETWORK_SUBNET'],
            (string) $config['PRIVATE_NETWORK_ZONE'],
        ));

        $cluster = new Cluster(
            DeploymentMode::Cluster,
            $clusterName,
            (string) $config['HETZNER_REGION'],
            $sshKey,
            $network,
        );

        // 4. Create nodes
        $nodeCount = (int) $config['CLUSTER_NODE_COUNT'];

        for ($i = 1; $i <= $nodeCount; $i++) {
            $role = NodeRole::from((string) $config["CLUSTER_NODE_{$i}_ROLE"]);
            $type = (string) $config["CLUSTER_NODE_{$i}_TYPE"];
            $name = (string) $config["CLUSTER_NODE_{$i}_NAME"];

            $node = $this->serverProvider->createServer(
                new Node($name, $role, $type),
                $cluster->region,
                (string) $config['HETZNER_IMAGE'],
                $sshKey,
                $cloudInit->render(),
            );
            $node = $this->waitForNode($node);

            // Attach to network
            $privateIp = $this->nextPrivateIp($cluster);
            $this->networkProvider->attachServer($network->id, $node->id, $privateIp);
            $node->privateIp = $privateIp;

            $cluster->addNode($node);

            // Volume (optional, per node)
            $this->createNodeVolume($config, $node, $cluster, (string) $i);
        }

        // 5. External firewall
        $extFirewall = $this->createExternalFirewall($config, $clusterName . '-external');
        $nodeIds = array_map(static fn(Node $n): string => $n->id, $cluster->getNodes());
        $this->firewallProvider->applyToServers($extFirewall->id, $nodeIds);
        $cluster->addFirewall($extFirewall);

        // 6. Internal firewall
        $intFirewall = $this->createInternalFirewall($network->subnet, $clusterName . '-internal');
        $this->firewallProvider->applyToServers($intFirewall->id, $nodeIds);
        $cluster->addFirewall($intFirewall);

        // 7. Certificate (reuse existing or create new)
        $certificate = $this->resolveOrCreateCertificate($config, $clusterName);

        // 8. Load balancer
        if (($config['LOADBALANCER_ENABLED'] ?? false)) {
            $certificateId = $certificate !== null ? $certificate->id : '';

            $lb = $this->lbProvider->createLoadBalancer(
                new LoadBalancer(
                    (string) $config['LOADBALANCER_NAME'],
                    (string) $config['LOADBALANCER_TYPE'],
                    algorithm: (string) ($config['LOADBALANCER_ALGORITHM'] ?? 'round_robin'),
                    healthCheckProtocol: (string) ($config['LOADBALANCER_HEALTH_CHECK_PROTOCOL'] ?? 'http'),
                    healthCheckPort: (int) ($config['LOADBALANCER_HEALTH_CHECK_PORT'] ?? 80),
                    healthCheckPath: (string) ($config['LOADBALANCER_HEALTH_CHECK_PATH'] ?? '/health'),
                    healthCheckInterval: (int) ($config['LOADBALANCER_HEALTH_CHECK_INTERVAL'] ?? 15),
                    healthCheckTimeout: (int) ($config['LOADBALANCER_HEALTH_CHECK_TIMEOUT'] ?? 10),
                    healthCheckRetries: (int) ($config['LOADBALANCER_HEALTH_CHECK_RETRIES'] ?? 3),
                ),
                $cluster->region,
                $network->id,
                $certificateId,
            );

            // Add agent nodes as targets
            foreach ($cluster->getNodesByRole(NodeRole::Agent) as $agent) {
                $this->lbProvider->addTarget($lb->id, $agent->id);
                $lb->addTarget($agent->name);
            }

            $cluster->loadBalancer = $lb;
            $cluster->certificate = $certificate;

            // LB auto-assigns a private IP from the network — reserve that slot
            $cluster->nextIpSuffix++;

            // 9. DNS → LB IP
            $this->createDnsRecords($config, $lb->publicIp, $cluster);
        }

        // 9. Save state
        $this->stateManager->save($cluster, $this->infraProvider, $this->dnsProviderName);

        return $cluster;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildCloudInit(array $config, string $sshPublicKey): CloudInitScript
    {
        return new CloudInitScript(
            deployUser: (string) ($config['SECURITY_DEPLOY_USER'] ?? 'deploy'),
            sshPublicKey: $sshPublicKey,
            sshPort: (int) ($config['SECURITY_SSH_PORT'] ?? 22),
            disableRoot: (bool) ($config['SECURITY_DISABLE_ROOT'] ?? true),
            disablePasswordAuth: (bool) ($config['SECURITY_DISABLE_PASSWORD_AUTH'] ?? true),
            autoUpdates: (bool) ($config['SECURITY_AUTO_UPDATES'] ?? true),
            fail2ban: (bool) ($config['SECURITY_FAIL2BAN'] ?? true),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createExternalFirewall(array $config, string $name): Firewall
    {
        $ports = explode(',', (string) ($config['FIREWALL_EXTERNAL_PORTS_TCP'] ?? '22,80,443'));

        $rules = array_map(
            static fn(string $port): FirewallRule => new FirewallRule('in', 'tcp', trim($port)),
            $ports,
        );

        $firewall = new Firewall($name, 'external', $rules);

        return $this->firewallProvider->createFirewall($firewall);
    }

    private function createInternalFirewall(string $subnet, string $name): Firewall
    {
        $sourceIps = [$subnet];

        $firewall = new Firewall($name, 'internal', [
            new FirewallRule('in', 'tcp', '6443', $sourceIps),
            new FirewallRule('in', 'udp', '8472', $sourceIps),
            new FirewallRule('in', 'tcp', '10250', $sourceIps),
            new FirewallRule('in', 'tcp', '2379', $sourceIps),
            new FirewallRule('in', 'tcp', '2380', $sourceIps),
        ]);

        return $this->firewallProvider->createFirewall($firewall);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createDnsRecords(array $config, string $targetIp, Cluster $cluster): void
    {
        if ($this->dnsProvider === null) {
            return;
        }

        $zone = (string) ($config['DNS_ZONE'] ?? '');
        $ttl = (int) ($config['DNS_TTL'] ?? 300);
        $recordDefs = explode(',', (string) ($config['DNS_RECORDS'] ?? ''));

        $cluster->dnsZone = $zone;

        foreach ($recordDefs as $def) {
            $parts = explode(':', trim($def));
            if (count($parts) !== 2) {
                continue;
            }

            $record = $this->dnsProvider->createRecord(
                $zone,
                new DnsRecord($parts[0], $parts[1], $targetIp, $ttl),
            );

            $cluster->addDnsRecord($record);
        }
    }

    private function waitForNode(Node $original): Node
    {
        $ready = $this->serverProvider->waitUntilRunning($original->id);

        return new Node(
            name: $ready->name,
            role: $original->role,
            serverType: $ready->serverType,
            publicIp: $ready->publicIp,
            privateIp: $ready->privateIp,
            status: $ready->status,
            id: $ready->id,
        );
    }

    private function readSshPublicKey(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read SSH public key: {$path}");
        }

        return trim($content);
    }

    private function nextPrivateIp(Cluster $cluster): string
    {
        if ($cluster->network === null) {
            throw new \RuntimeException('Cannot assign private IP without network');
        }

        $base = $this->subnetBaseIp($cluster->network->subnet);
        $suffix = $cluster->nextIpSuffix;
        $cluster->nextIpSuffix = $suffix + 1;

        return $base . $suffix;
    }

    private function subnetBaseIp(string $subnet): string
    {
        $ip = explode('/', $subnet)[0];
        $parts = explode('.', $ip);
        $parts[3] = '';

        return implode('.', $parts);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveOrCreateCertificate(array $config, string $clusterName): ?Certificate
    {
        if ($this->certificateProvider === null) {
            return null;
        }

        $domainNames = $this->parseCertificateDomains($config);
        if ($domainNames === []) {
            return null;
        }

        $existing = $this->certificateProvider->findExistingCertificate($domainNames);
        if ($existing !== null) {
            return $existing;
        }

        return $this->certificateProvider->createManagedCertificate(
            new Certificate($clusterName . '-cert', $domainNames),
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return string[]
     */
    private function parseCertificateDomains(array $config): array
    {
        $zone = (string) ($config['DNS_ZONE'] ?? '');
        $recordDefs = (string) ($config['DNS_RECORDS'] ?? '');

        if ($zone === '' || $recordDefs === '') {
            return [];
        }

        $domains = [];
        foreach (explode(',', $recordDefs) as $def) {
            $parts = explode(':', trim($def));
            if (count($parts) === 2) {
                $domains[] = $parts[0] . '.' . $zone;
            }
        }

        return $domains;
    }

    /**
     * @param array<string, mixed> $config
     * @param string $nodeIndex Empty for single-server, "1"/"2"/... for cluster nodes
     */
    private function createNodeVolume(array $config, Node $node, Cluster $cluster, string $nodeIndex): void
    {
        $sizeKey = $nodeIndex === ''
            ? 'VOLUME_SIZE'
            : "CLUSTER_NODE_{$nodeIndex}_VOLUME_SIZE";

        $size = (int) ($config[$sizeKey] ?? 0);
        if ($size <= 0) {
            return;
        }

        $volumeName = $node->name . '-data';

        $volume = $this->volumeProvider->createVolume(
            new Volume($volumeName, $size, $node->id),
            $cluster->region,
        );

        $cluster->addVolume($volume);
    }
}
