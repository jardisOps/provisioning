<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Contract;

use JardisOps\Provisioning\Support\Data\PrivateNetwork;

/**
 * Manages private networks for cluster communication.
 */
interface NetworkProviderInterface
{
    public function createNetwork(PrivateNetwork $network): PrivateNetwork;

    public function deleteNetwork(string $networkId): void;

    public function attachServer(string $networkId, string $serverId, string $privateIp): void;

    public function detachServer(string $networkId, string $serverId): void;

    public function getNetwork(string $networkId): PrivateNetwork;
}
