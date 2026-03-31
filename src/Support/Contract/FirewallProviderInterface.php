<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Contract;

use JardisOps\Provisioning\Support\Data\Firewall;

/**
 * Creates and manages firewalls.
 */
interface FirewallProviderInterface
{
    public function createFirewall(Firewall $firewall): Firewall;

    public function deleteFirewall(string $firewallId): void;

    /** @param string[] $serverIds */
    public function applyToServers(string $firewallId, array $serverIds): void;

    /** @param string[] $serverIds */
    public function removeFromServers(string $firewallId, array $serverIds): void;

    public function getFirewall(string $firewallId): Firewall;
}
