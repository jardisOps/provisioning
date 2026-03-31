<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Provider\Hetzner;

use JardisOps\Provisioning\Support\Contract\ServerProviderInterface;
use JardisOps\Provisioning\Support\Data\Node;
use JardisOps\Provisioning\Support\Data\NodeRole;
use JardisOps\Provisioning\Support\Data\NodeStatus;
use JardisOps\Provisioning\Support\Data\SshKey;

/**
 * Hetzner Cloud implementation for server and SSH key management.
 */
final class HetznerServerProvider implements ServerProviderInterface
{
    public function __construct(
        private readonly HetznerApiClient $api,
    ) {
    }

    public function createServer(
        Node $node,
        string $region,
        string $image,
        SshKey $sshKey,
        string $userData = '',
    ): Node {
        $payload = [
            'name' => $node->name,
            'server_type' => $node->serverType,
            'location' => $region,
            'image' => $image,
            'start_after_create' => true,
        ];

        if ($sshKey->providerId !== '') {
            $payload['ssh_keys'] = [$sshKey->providerId];
        }

        if ($userData !== '') {
            $payload['user_data'] = $userData;
        }

        $response = $this->api->post('/servers', $payload);

        /** @var array<string, mixed> $server */
        $server = $response['server'];

        return $this->mapToNode($server, $node->role);
    }

    public function getServer(string $serverId): Node
    {
        $response = $this->api->get("/servers/{$serverId}");

        /** @var array<string, mixed> $server */
        $server = $response['server'];

        return $this->mapToNode($server);
    }

    public function waitUntilRunning(string $serverId, int $timeoutSeconds = 120, int $intervalSeconds = 3): Node
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $node = $this->getServer($serverId);
            if ($node->status === NodeStatus::Running) {
                return $node;
            }
            sleep($intervalSeconds);
        }

        throw new \RuntimeException("Server {$serverId} did not reach 'running' within {$timeoutSeconds}s");
    }

    public function deleteServer(string $serverId): void
    {
        $this->api->delete("/servers/{$serverId}");
    }

    public function registerSshKey(SshKey $sshKey): SshKey
    {
        $publicKey = file_get_contents($sshKey->publicKeyPath);
        if ($publicKey === false) {
            throw new \RuntimeException("Cannot read SSH key: {$sshKey->publicKeyPath}");
        }

        $response = $this->api->post('/ssh_keys', [
            'name' => $sshKey->name,
            'public_key' => trim($publicKey),
        ]);

        /** @var array<string, mixed> $key */
        $key = $response['ssh_key'];

        return new SshKey(
            $sshKey->name,
            $sshKey->publicKeyPath,
            (string) $key['id'],
        );
    }

    public function deleteSshKey(string $keyId): void
    {
        $this->api->delete("/ssh_keys/{$keyId}");
    }

    /**
     * @param array<string, mixed> $server
     */
    private function mapToNode(array $server, ?NodeRole $role = null): Node
    {
        /** @var array<string, mixed> $publicNet */
        $publicNet = $server['public_net'] ?? [];
        /** @var array<string, mixed> $ipv4 */
        $ipv4 = $publicNet['ipv4'] ?? [];

        $privateIp = '';
        /** @var array<int, array<string, mixed>> $privateNets */
        $privateNets = $server['private_net'] ?? [];
        if ($privateNets !== []) {
            $privateIp = (string) ($privateNets[0]['ip'] ?? '');
        }

        $status = match ((string) ($server['status'] ?? '')) {
            'running' => NodeStatus::Running,
            'off' => NodeStatus::Stopped,
            default => NodeStatus::Pending,
        };

        return new Node(
            name: (string) $server['name'],
            role: $role ?? NodeRole::Agent,
            serverType: (string) ($server['server_type']['name'] ?? $server['server_type'] ?? ''),
            publicIp: (string) ($ipv4['ip'] ?? ''),
            privateIp: $privateIp,
            status: $status,
            id: (string) $server['id'],
        );
    }
}
