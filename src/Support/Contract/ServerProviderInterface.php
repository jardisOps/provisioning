<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Contract;

use JardisOps\Provisioning\Support\Data\Node;
use JardisOps\Provisioning\Support\Data\SshKey;

/**
 * Creates, queries, and destroys servers.
 */
interface ServerProviderInterface
{
    public function createServer(
        Node $node,
        string $region,
        string $image,
        SshKey $sshKey,
        string $userData = '',
    ): Node;

    public function getServer(string $serverId): Node;

    /**
     * Polls until the server reaches 'running' status.
     *
     * @throws \RuntimeException on timeout
     */
    public function waitUntilRunning(string $serverId, int $timeoutSeconds = 120, int $intervalSeconds = 3): Node;

    public function deleteServer(string $serverId): void;

    public function registerSshKey(SshKey $sshKey): SshKey;

    public function deleteSshKey(string $keyId): void;
}
