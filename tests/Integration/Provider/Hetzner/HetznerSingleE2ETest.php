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
use JardisOps\Provisioning\Support\Data\DeploymentMode;
use JardisOps\Provisioning\Support\Data\NodeRole;
use PHPUnit\Framework\TestCase;

/**
 * Full E2E single-server provisioning test with volume.
 *
 * Creates: SSH key + Server + Firewall + Volume (10 GB)
 * Then tears everything down (volume detached, not deleted).
 *
 * Requires HETZNER_API_TOKEN in tests/fixtures/hetzner/.env.local
 */
final class HetznerSingleE2ETest extends TestCase
{
    use HetznerTestConfig;

    private ?Provisioner $provisioner = null;
    private string $statePath = '';

    protected function setUp(): void
    {
        $this->loadHetznerConfig();
        $token = $this->requireToken();

        $httpClient = new HttpClient();
        $api = new HetznerApiClient($httpClient, $token);

        $this->statePath = sys_get_temp_dir() . '/prov-e2e-single-' . uniqid();
        mkdir($this->statePath, 0755, true);

        $this->provisioner = new Provisioner(
            new HetznerServerProvider($api),
            new HetznerNetworkProvider($api),
            new HetznerFirewallProvider($api),
            new HetznerLoadBalancerProvider($api),
            null,
            new HetznerCertificateProvider($api),
            new HetznerVolumeProvider($api),
            new StateManager($this->statePath),
            'hetzner',
            '',
        );
    }

    protected function tearDown(): void
    {
        if ($this->statePath !== '' && is_dir($this->statePath)) {
            $files = glob($this->statePath . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->statePath);
        }
    }

    public function testSingleServerWithVolume(): void
    {
        $provisioner = $this->provisioner;
        self::assertNotNull($provisioner);

        $prefix = (string) ($this->config['TEST_RESOURCE_PREFIX'] ?? 'prov-test');
        $region = (string) ($this->config['HETZNER_REGION'] ?? 'nbg1');
        $image = (string) ($this->config['HETZNER_IMAGE'] ?? 'ubuntu-24.04');
        $serverType = (string) ($this->config['SERVER_TYPE'] ?? 'cpx22');

        $config = [
            'PROVISION_MODE' => 'single',
            'INFRA_PROVIDER' => 'hetzner',
            'HETZNER_REGION' => $region,
            'HETZNER_IMAGE' => $image,
            'SSH_KEY_PATH' => dirname(__DIR__, 3) . '/fixtures/hetzner/test_ssh_key.pub',
            'SERVER_NAME' => "{$prefix}-single",
            'SERVER_TYPE' => $serverType,
            'VOLUME_SIZE' => 10,
            'FIREWALL_EXTERNAL_PORTS_TCP' => '22,80,443',
            'SECURITY_DEPLOY_USER' => 'deploy',
            'SECURITY_SSH_PORT' => 22,
            'SECURITY_DISABLE_ROOT' => true,
            'SECURITY_DISABLE_PASSWORD_AUTH' => true,
            'SECURITY_AUTO_UPDATES' => true,
            'SECURITY_FAIL2BAN' => true,
        ];

        // =====================================================================
        // 1. PROVISION
        // =====================================================================
        echo "\n  === PROVISIONING SINGLE SERVER ===\n\n";

        $cluster = $provisioner->provision($config);

        self::assertSame(DeploymentMode::Single, $cluster->mode);

        // Node
        $nodes = $cluster->getNodes();
        self::assertCount(1, $nodes);
        $node = $nodes[0];
        self::assertNotEmpty($node->id);
        self::assertNotEmpty($node->publicIp);
        echo "  Server: {$node->name} — IP: {$node->publicIp} (ID: {$node->id})\n";

        // Firewall
        $firewalls = $cluster->getFirewalls();
        self::assertCount(1, $firewalls);
        echo "  Firewall: {$firewalls[0]->name} (ID: {$firewalls[0]->id})\n";

        // Volume
        $volumes = $cluster->getVolumes();
        self::assertCount(1, $volumes);
        self::assertSame(10, $volumes[0]->size);
        self::assertNotEmpty($volumes[0]->id);
        echo "  Volume: {$volumes[0]->name} ({$volumes[0]->size} GB, ID: {$volumes[0]->id})\n";

        // No DNS (not configured)
        self::assertEmpty($cluster->getDnsRecords());

        // =====================================================================
        // 2. WAIT
        // =====================================================================
        echo "\n  === WAITING 30s ===\n";
        sleep(30);

        // =====================================================================
        // 3. DEPROVISION
        // =====================================================================
        echo "\n  === DEPROVISIONING ===\n";

        $provisioner->deprovision(deleteVolumes: true);

        echo "  All resources destroyed (volume detached, not deleted)\n";

        $status = $provisioner->status();
        self::assertNull($status);

        echo "\n  === E2E SINGLE SERVER TEST COMPLETE ===\n";
    }
}
