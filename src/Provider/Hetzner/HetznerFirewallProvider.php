<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Provider\Hetzner;

use JardisOps\Provisioning\Support\Contract\FirewallProviderInterface;
use JardisOps\Provisioning\Support\Data\Firewall;
use JardisOps\Provisioning\Support\Data\FirewallRule;

/**
 * Hetzner Cloud implementation for firewall management.
 */
final class HetznerFirewallProvider implements FirewallProviderInterface
{
    public function __construct(
        private readonly HetznerApiClient $api,
    ) {
    }

    public function createFirewall(Firewall $firewall): Firewall
    {
        $rules = array_map(static fn(FirewallRule $r): array => [
            'direction' => $r->direction,
            'protocol' => $r->protocol,
            'port' => $r->port,
            'source_ips' => $r->sourceIps,
        ], $firewall->rules);

        $response = $this->api->post('/firewalls', [
            'name' => $firewall->name,
            'rules' => $rules,
        ]);

        /** @var array<string, mixed> $fw */
        $fw = $response['firewall'];

        return new Firewall(
            $firewall->name,
            $firewall->type,
            $firewall->rules,
            (string) $fw['id'],
        );
    }

    public function deleteFirewall(string $firewallId): void
    {
        $this->api->delete("/firewalls/{$firewallId}");
    }

    public function applyToServers(string $firewallId, array $serverIds): void
    {
        $resources = array_map(static fn(string $id): array => [
            'type' => 'server',
            'server' => ['id' => (int) $id],
        ], $serverIds);

        $this->api->post("/firewalls/{$firewallId}/actions/apply_to_resources", [
            'apply_to' => $resources,
        ]);
    }

    public function removeFromServers(string $firewallId, array $serverIds): void
    {
        $resources = array_map(static fn(string $id): array => [
            'type' => 'server',
            'server' => ['id' => (int) $id],
        ], $serverIds);

        $this->api->post("/firewalls/{$firewallId}/actions/remove_from_resources", [
            'remove_from' => $resources,
        ]);
    }

    public function getFirewall(string $firewallId): Firewall
    {
        $response = $this->api->get("/firewalls/{$firewallId}");

        /** @var array<string, mixed> $fw */
        $fw = $response['firewall'];

        /** @var array<int, array<string, mixed>> $apiRules */
        $apiRules = $fw['rules'] ?? [];

        $rules = array_map(static fn(array $r): FirewallRule => new FirewallRule(
            direction: (string) $r['direction'],
            protocol: (string) $r['protocol'],
            port: (string) $r['port'],
            sourceIps: array_map('strval', (array) ($r['source_ips'] ?? [])),
        ), $apiRules);

        return new Firewall(
            name: (string) $fw['name'],
            type: '',
            rules: $rules,
            id: (string) $fw['id'],
        );
    }
}
