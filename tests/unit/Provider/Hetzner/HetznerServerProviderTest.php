<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Hetzner;

use JardisOps\Provisioning\Support\Data\Node;
use JardisOps\Provisioning\Support\Data\NodeRole;
use JardisOps\Provisioning\Support\Data\NodeStatus;
use JardisOps\Provisioning\Support\Data\SshKey;
use JardisOps\Provisioning\Provider\Hetzner\HetznerServerProvider;
use PHPUnit\Framework\TestCase;

final class HetznerServerProviderTest extends TestCase
{
    private MockHttpClient $httpClient;
    private HetznerServerProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();
        $api = HetznerTestHelper::createApiClient($this->httpClient);
        $this->provider = new HetznerServerProvider($api);
    }

    public function testCreateServer(): void
    {
        $this->httpClient->queueResponse(201, json_encode([
            'server' => [
                'id' => 12345,
                'name' => 'test-server',
                'status' => 'running',
                'server_type' => ['name' => 'cpx31'],
                'public_net' => ['ipv4' => ['ip' => '49.12.1.1']],
                'private_net' => [],
            ],
        ], JSON_THROW_ON_ERROR));

        $node = new Node('test-server', NodeRole::Server, 'cpx31');
        $sshKey = new SshKey('key', '/path', '999');

        $result = $this->provider->createServer($node, 'fsn1', 'ubuntu-24.04', $sshKey);

        self::assertSame('12345', $result->id);
        self::assertSame('test-server', $result->name);
        self::assertSame('49.12.1.1', $result->publicIp);
        self::assertSame(NodeStatus::Running, $result->status);
        self::assertSame(NodeRole::Server, $result->role);
    }

    public function testGetServer(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'server' => [
                'id' => 12345,
                'name' => 'test-server',
                'status' => 'running',
                'server_type' => ['name' => 'cpx31'],
                'public_net' => ['ipv4' => ['ip' => '49.12.1.1']],
                'private_net' => [['ip' => '10.0.1.1']],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->provider->getServer('12345');

        self::assertSame('12345', $result->id);
        self::assertSame('10.0.1.1', $result->privateIp);
    }

    public function testDeleteServer(): void
    {
        $this->httpClient->queueResponse(204, '');

        $this->provider->deleteServer('12345');

        $request = $this->httpClient->getLastRequest();
        self::assertSame('DELETE', $request['method']);
    }

    public function testRegisterSshKey(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ssh');
        self::assertNotFalse($tmpFile);
        file_put_contents($tmpFile, 'ssh-ed25519 AAAA... test@host');

        $this->httpClient->queueResponse(201, json_encode([
            'ssh_key' => [
                'id' => 999,
                'name' => 'test-key',
                'public_key' => 'ssh-ed25519 AAAA... test@host',
            ],
        ], JSON_THROW_ON_ERROR));

        $sshKey = new SshKey('test-key', $tmpFile);
        $result = $this->provider->registerSshKey($sshKey);

        self::assertSame('999', $result->providerId);
        self::assertSame('test-key', $result->name);

        unlink($tmpFile);
    }

    public function testDeleteSshKey(): void
    {
        $this->httpClient->queueResponse(204, '');

        $this->provider->deleteSshKey('999');

        $request = $this->httpClient->getLastRequest();
        self::assertSame('DELETE', $request['method']);
    }
}
