<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Provider\Hetzner;

use JardisOps\Provisioning\Support\Contract\NetworkProviderInterface;
use JardisOps\Provisioning\Support\Data\PrivateNetwork;

/**
 * Hetzner Cloud implementation for private network management.
 */
final class HetznerNetworkProvider implements NetworkProviderInterface
{
    public function __construct(
        private readonly HetznerApiClient $api,
    ) {
    }

    public function createNetwork(PrivateNetwork $network): PrivateNetwork
    {
        $response = $this->api->post('/networks', [
            'name' => $network->name,
            'ip_range' => $network->subnet,
        ]);

        /** @var array<string, mixed> $net */
        $net = $response['network'];
        $networkId = (string) $net['id'];

        $this->api->post("/networks/{$networkId}/actions/add_subnet", [
            'type' => 'cloud',
            'network_zone' => $network->zone,
            'ip_range' => $network->subnet,
        ]);

        return new PrivateNetwork(
            $network->name,
            $network->subnet,
            $network->zone,
            $networkId,
        );
    }

    public function deleteNetwork(string $networkId): void
    {
        $this->api->delete("/networks/{$networkId}");
    }

    public function attachServer(string $networkId, string $serverId, string $privateIp): void
    {
        $this->api->post("/servers/{$serverId}/actions/attach_to_network", [
            'network' => (int) $networkId,
            'ip' => $privateIp,
        ]);
    }

    public function detachServer(string $networkId, string $serverId): void
    {
        $this->api->post("/servers/{$serverId}/actions/detach_from_network", [
            'network' => (int) $networkId,
        ]);
    }

    public function getNetwork(string $networkId): PrivateNetwork
    {
        $response = $this->api->get("/networks/{$networkId}");

        /** @var array<string, mixed> $net */
        $net = $response['network'];

        /** @var array<int, array<string, mixed>> $subnets */
        $subnets = $net['subnets'] ?? [];
        $zone = $subnets !== [] ? (string) ($subnets[0]['network_zone'] ?? '') : '';

        return new PrivateNetwork(
            name: (string) $net['name'],
            subnet: (string) $net['ip_range'],
            zone: $zone,
            id: (string) $net['id'],
        );
    }
}
