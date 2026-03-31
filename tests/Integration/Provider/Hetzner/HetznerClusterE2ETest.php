<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Integration\Provider\Hetzner;

use JardisOps\Provisioning\Provider\Hetzner\HetznerApiClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerCertificateProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerDnsProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerFirewallProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerLoadBalancerProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerNetworkProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerServerProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerVolumeProvider;
use JardisOps\Provisioning\Provisioner;
use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Service\State\StateManager;
use JardisOps\Provisioning\Support\Data\Cluster;
use JardisOps\Provisioning\Support\Data\DeploymentMode;
use JardisOps\Provisioning\Support\Data\NodeRole;
use PHPUnit\Framework\TestCase;

/**
 * Full E2E cluster provisioning test against live Hetzner Cloud API.
 *
 * Creates: SSH key + Network + 2 Nodes + 2 Firewalls + Certificate + LB + DNS
 * Waits 10 minutes after certificate, then tears everything down.
 *
 * Requires HETZNER_API_TOKEN, HETZNER_DNS_TOKEN, DNS_ZONE in tests/fixtures/hetzner/.env.local
 */
final class HetznerClusterE2ETest extends TestCase
{
    use HetznerTestConfig;

    private ?Provisioner $provisioner = null;
    private string $statePath = '';

    protected function setUp(): void
    {
        $this->loadHetznerConfig();
        $infraToken = $this->requireToken();
        $dnsToken = $this->requireDnsToken();
        $zone = (string) ($this->config['DNS_ZONE'] ?? '');

        if ($zone === '') {
            self::markTestSkipped('DNS_ZONE not set — add it to tests/fixtures/hetzner/.env.local');
        }

        $httpClient = new HttpClient();
        $infraApi = new HetznerApiClient($httpClient, $infraToken);
        $dnsApi = new HetznerApiClient($httpClient, $dnsToken);

        $this->statePath = sys_get_temp_dir() . '/prov-e2e-cluster-' . uniqid();
        mkdir($this->statePath, 0755, true);

        $this->provisioner = new Provisioner(
            new HetznerServerProvider($infraApi),
            new HetznerNetworkProvider($infraApi),
            new HetznerFirewallProvider($infraApi),
            new HetznerLoadBalancerProvider($infraApi),
            new HetznerDnsProvider($dnsApi),
            new HetznerCertificateProvider($infraApi),
            new HetznerVolumeProvider($infraApi),
            new StateManager($this->statePath),
            'hetzner',
            'hetzner',
        );
    }

    protected function tearDown(): void
    {
        // Cleanup state directory
        if ($this->statePath !== '' && is_dir($this->statePath)) {
            $files = glob($this->statePath . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->statePath);
        }
    }

    public function testFullClusterProvisionAndDeprovision(): void
    {
        $provisioner = $this->provisioner;
        self::assertNotNull($provisioner);

        $prefix = (string) ($this->config['TEST_RESOURCE_PREFIX'] ?? 'prov-test');
        $region = (string) ($this->config['HETZNER_REGION'] ?? 'nbg1');
        $image = (string) ($this->config['HETZNER_IMAGE'] ?? 'ubuntu-24.04');
        $serverType = (string) ($this->config['SERVER_TYPE'] ?? 'cpx22');
        $zone = (string) $this->config['DNS_ZONE'];

        $config = [
            'PROVISION_MODE' => 'cluster',
            'INFRA_PROVIDER' => 'hetzner',
            'DNS_PROVIDER' => 'hetzner',
            'HETZNER_REGION' => $region,
            'HETZNER_IMAGE' => $image,
            'SSH_KEY_PATH' => dirname(__DIR__, 3) . '/fixtures/hetzner/test_ssh_key.pub',
            'CLUSTER_NAME' => "{$prefix}-cluster",
            'CLUSTER_NODE_COUNT' => 2,
            'CLUSTER_NODE_1_ROLE' => 'server',
            'CLUSTER_NODE_1_TYPE' => $serverType,
            'CLUSTER_NODE_1_NAME' => "{$prefix}-server-1",
            'CLUSTER_NODE_1_VOLUME_SIZE' => 10,
            'CLUSTER_NODE_2_ROLE' => 'agent',
            'CLUSTER_NODE_2_TYPE' => $serverType,
            'CLUSTER_NODE_2_NAME' => "{$prefix}-agent-1",
            'PRIVATE_NETWORK_NAME' => "{$prefix}-net",
            'PRIVATE_NETWORK_SUBNET' => (string) ($this->config['PRIVATE_NETWORK_SUBNET'] ?? '10.0.99.0/24'),
            'PRIVATE_NETWORK_ZONE' => (string) ($this->config['PRIVATE_NETWORK_ZONE'] ?? 'eu-central'),
            'LOADBALANCER_ENABLED' => true,
            'LOADBALANCER_NAME' => "{$prefix}-lb",
            'LOADBALANCER_TYPE' => (string) ($this->config['LOADBALANCER_TYPE'] ?? 'lb11'),
            'LOADBALANCER_ALGORITHM' => (string) ($this->config['LOADBALANCER_ALGORITHM'] ?? 'round_robin'),
            'LOADBALANCER_HEALTH_CHECK_PROTOCOL' => 'http',
            'LOADBALANCER_HEALTH_CHECK_PORT' => 80,
            'LOADBALANCER_HEALTH_CHECK_PATH' => '/health',
            'LOADBALANCER_HEALTH_CHECK_INTERVAL' => 15,
            'LOADBALANCER_HEALTH_CHECK_TIMEOUT' => 10,
            'LOADBALANCER_HEALTH_CHECK_RETRIES' => 3,
            'DNS_ZONE' => $zone,
            'DNS_RECORDS' => "{$prefix}:A",
            'DNS_TTL' => 300,
            'FIREWALL_EXTERNAL_PORTS_TCP' => '22,80,443',
            'SECURITY_DEPLOY_USER' => 'deploy',
            'SECURITY_SSH_PORT' => 22,
            'SECURITY_DISABLE_ROOT' => true,
            'SECURITY_DISABLE_PASSWORD_AUTH' => true,
            'SECURITY_AUTO_UPDATES' => true,
            'SECURITY_FAIL2BAN' => true,
        ];

        // =====================================================================
        // 1. PROVISION CLUSTER
        // =====================================================================
        echo "\n  === PROVISIONING CLUSTER ===\n\n";

        $cluster = $provisioner->provision($config);

        // Verify cluster structure
        self::assertSame(DeploymentMode::Cluster, $cluster->mode);
        self::assertSame("{$prefix}-cluster", $cluster->name);

        // Nodes
        $nodes = $cluster->getNodes();
        self::assertCount(2, $nodes, 'Cluster should have 2 nodes');

        $servers = $cluster->getNodesByRole(NodeRole::Server);
        $agents = $cluster->getNodesByRole(NodeRole::Agent);
        self::assertCount(1, $servers, 'Should have 1 server node');
        self::assertCount(1, $agents, 'Should have 1 agent node');

        foreach ($nodes as $node) {
            self::assertNotEmpty($node->id, "Node {$node->name} should have ID");
            self::assertNotEmpty($node->publicIp, "Node {$node->name} should have public IP");
            self::assertNotEmpty($node->privateIp, "Node {$node->name} should have private IP");
            echo "  Node: {$node->name} ({$node->role->value}) — public: {$node->publicIp}, private: {$node->privateIp}\n";
        }

        // Network
        self::assertNotNull($cluster->network, 'Cluster should have a network');
        self::assertNotEmpty($cluster->network->id);
        echo "  Network: {$cluster->network->name} (ID: {$cluster->network->id})\n";

        // Firewalls
        $firewalls = $cluster->getFirewalls();
        self::assertCount(2, $firewalls, 'Should have 2 firewalls (external + internal)');
        foreach ($firewalls as $fw) {
            self::assertNotEmpty($fw->id);
            echo "  Firewall: {$fw->name} (ID: {$fw->id})\n";
        }

        // Load Balancer
        self::assertNotNull($cluster->loadBalancer, 'Cluster should have a load balancer');
        self::assertNotEmpty($cluster->loadBalancer->id);
        self::assertNotEmpty($cluster->loadBalancer->publicIp);
        echo "  LB: {$cluster->loadBalancer->name} — IP: {$cluster->loadBalancer->publicIp} (ID: {$cluster->loadBalancer->id})\n";

        // Certificate
        if ($cluster->certificate !== null) {
            self::assertTrue($cluster->certificate->isReady(), 'Certificate should be ready');
            echo "  Certificate: {$cluster->certificate->name} — domains: " . implode(', ', $cluster->certificate->domainNames) . " (ID: {$cluster->certificate->id})\n";
        } else {
            echo "  Certificate: skipped (no existing cert, will be created on demand)\n";
        }

        // DNS
        $dnsRecords = $cluster->getDnsRecords();
        self::assertNotEmpty($dnsRecords, 'Should have DNS records');
        foreach ($dnsRecords as $record) {
            echo "  DNS: {$record->subdomain}.{$zone} -> {$record->target} (ID: {$record->id})\n";
        }

        // Volumes
        $volumes = $cluster->getVolumes();
        self::assertCount(1, $volumes, 'Should have 1 volume (server-node only)');
        self::assertSame(10, $volumes[0]->size);
        self::assertNotEmpty($volumes[0]->id);
        echo "  Volume: {$volumes[0]->name} ({$volumes[0]->size} GB, ID: {$volumes[0]->id})\n";

        // Status check
        $status = $provisioner->status();
        self::assertNotNull($status, 'Status should return the cluster');

        // =====================================================================
        // 2. ADD NODE
        // =====================================================================
        echo "\n  === ADDING NODE ===\n";

        $cluster = $provisioner->addNode(
            $config,
            "{$prefix}-agent-2",
            NodeRole::Agent,
            $serverType,
            10,
        );

        $nodes = $cluster->getNodes();
        self::assertCount(3, $nodes, 'Cluster should have 3 nodes after addNode');

        $newNode = $cluster->getNode("{$prefix}-agent-2");
        self::assertNotEmpty($newNode->id);
        self::assertNotEmpty($newNode->publicIp);
        self::assertNotEmpty($newNode->privateIp);
        self::assertSame(NodeRole::Agent, $newNode->role);
        echo "  Added: {$newNode->name} — public: {$newNode->publicIp}, private: {$newNode->privateIp}\n";

        // Verify volume was created
        $volumes = $cluster->getVolumes();
        self::assertCount(2, $volumes, 'Should have 2 volumes after addNode with volume');
        $newVolume = $cluster->getVolumeForNode("{$prefix}-agent-2");
        self::assertNotNull($newVolume);
        self::assertSame(10, $newVolume->size);
        echo "  Volume: {$newVolume->name} ({$newVolume->size} GB, ID: {$newVolume->id})\n";

        // Verify LB target was added (agent node)
        self::assertNotNull($cluster->loadBalancer);
        self::assertContains("{$prefix}-agent-2", $cluster->loadBalancer->targets);
        echo "  LB target added\n";

        // =====================================================================
        // 3. REMOVE NODE
        // =====================================================================
        echo "\n  === REMOVING NODE ===\n";

        $cluster = $provisioner->removeNode("{$prefix}-agent-2", deleteVolume: true);

        $nodes = $cluster->getNodes();
        self::assertCount(2, $nodes, 'Cluster should have 2 nodes after removeNode');
        self::assertCount(1, $cluster->getVolumes(), 'Should have 1 volume after removeNode');
        self::assertNotContains("{$prefix}-agent-2", $cluster->loadBalancer->targets);
        echo "  Removed: {$prefix}-agent-2 (with volume)\n";

        // =====================================================================
        // 4. WAIT (allow resources to settle before teardown)
        // =====================================================================
        $waitSeconds = 30;
        echo "\n  === WAITING {$waitSeconds}s ===\n";
        sleep($waitSeconds);

        // =====================================================================
        // 5. DEPROVISION (tear everything down — certificate stays)
        // =====================================================================
        echo "\n  === DEPROVISIONING ===\n";

        $provisioner->deprovision(deleteVolumes: true);

        echo "  All resources destroyed (certificate kept)\n";

        // Verify clean state
        $status = $provisioner->status();
        self::assertNull($status, 'No resources should remain after deprovision');

        echo "\n  === E2E CLUSTER TEST COMPLETE ===\n";
    }

}
