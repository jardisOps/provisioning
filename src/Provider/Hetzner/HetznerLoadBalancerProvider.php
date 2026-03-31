<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Provider\Hetzner;

use JardisOps\Provisioning\Support\Contract\LoadBalancerProviderInterface;
use JardisOps\Provisioning\Support\Data\LoadBalancer;

/**
 * Hetzner Cloud implementation for load balancer management.
 */
final class HetznerLoadBalancerProvider implements LoadBalancerProviderInterface
{
    public function __construct(
        private readonly HetznerApiClient $api,
    ) {
    }

    public function createLoadBalancer(
        LoadBalancer $loadBalancer,
        string $region,
        string $networkId,
        string $certificateId = '',
    ): LoadBalancer {
        $services = $this->buildServices($loadBalancer, $certificateId);

        $response = $this->api->post('/load_balancers', [
            'name' => $loadBalancer->name,
            'load_balancer_type' => $loadBalancer->type,
            'location' => $region,
            'algorithm' => ['type' => $loadBalancer->algorithm],
            'services' => $services,
        ]);

        /** @var array<string, mixed> $lb */
        $lb = $response['load_balancer'];
        $lbId = (string) $lb['id'];

        // Attach to private network (async action — wait for completion)
        if ($networkId !== '') {
            $attachResponse = $this->api->post("/load_balancers/{$lbId}/actions/attach_to_network", [
                'network' => (int) $networkId,
            ]);

            /** @var array<string, mixed> $action */
            $action = $attachResponse['action'] ?? [];
            $actionId = (string) ($action['id'] ?? '');

            if ($actionId !== '') {
                $this->waitForAction($lbId, $actionId);
            }
        }

        /** @var array<string, mixed> $publicNet */
        $publicNet = $lb['public_net'] ?? [];
        /** @var array<string, mixed> $ipv4 */
        $ipv4 = $publicNet['ipv4'] ?? [];

        return new LoadBalancer(
            name: $loadBalancer->name,
            type: $loadBalancer->type,
            publicIp: (string) ($ipv4['ip'] ?? ''),
            targets: $loadBalancer->targets,
            algorithm: $loadBalancer->algorithm,
            healthCheckProtocol: $loadBalancer->healthCheckProtocol,
            healthCheckPort: $loadBalancer->healthCheckPort,
            healthCheckPath: $loadBalancer->healthCheckPath,
            healthCheckInterval: $loadBalancer->healthCheckInterval,
            healthCheckTimeout: $loadBalancer->healthCheckTimeout,
            healthCheckRetries: $loadBalancer->healthCheckRetries,
            id: (string) $lb['id'],
        );
    }

    public function deleteLoadBalancer(string $loadBalancerId): void
    {
        $this->api->delete("/load_balancers/{$loadBalancerId}");
    }

    public function addTarget(string $loadBalancerId, string $serverId): void
    {
        $this->api->post("/load_balancers/{$loadBalancerId}/actions/add_target", [
            'type' => 'server',
            'server' => ['id' => (int) $serverId],
            'use_private_ip' => true,
        ]);
    }

    public function removeTarget(string $loadBalancerId, string $serverId): void
    {
        $this->api->post("/load_balancers/{$loadBalancerId}/actions/remove_target", [
            'type' => 'server',
            'server' => ['id' => (int) $serverId],
        ]);
    }

    public function getLoadBalancer(string $loadBalancerId): LoadBalancer
    {
        $response = $this->api->get("/load_balancers/{$loadBalancerId}");

        /** @var array<string, mixed> $lb */
        $lb = $response['load_balancer'];

        /** @var array<string, mixed> $publicNet */
        $publicNet = $lb['public_net'] ?? [];
        /** @var array<string, mixed> $ipv4 */
        $ipv4 = $publicNet['ipv4'] ?? [];

        /** @var array<int, array<string, mixed>> $apiTargets */
        $apiTargets = $lb['targets'] ?? [];
        $targets = array_map(static function (array $t): string {
            /** @var array<string, mixed> $server */
            $server = $t['server'] ?? [];
            return (string) ($server['id'] ?? '');
        }, $apiTargets);

        return new LoadBalancer(
            name: (string) $lb['name'],
            type: (string) ($lb['load_balancer_type']['name'] ?? ''),
            publicIp: (string) ($ipv4['ip'] ?? ''),
            targets: $targets,
            id: (string) $lb['id'],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildServices(LoadBalancer $lb, string $certificateId): array
    {
        $healthCheck = [
            'protocol' => $lb->healthCheckProtocol,
            'port' => $lb->healthCheckPort,
            'interval' => $lb->healthCheckInterval,
            'timeout' => $lb->healthCheckTimeout,
            'retries' => $lb->healthCheckRetries,
            'http' => [
                'path' => $lb->healthCheckPath,
            ],
        ];

        $httpService = [
            'protocol' => 'http',
            'listen_port' => 80,
            'destination_port' => 80,
            'health_check' => $healthCheck,
        ];

        if ($certificateId === '') {
            return [$httpService];
        }

        $httpsService = [
            'protocol' => 'https',
            'listen_port' => 443,
            'destination_port' => 80,
            'http' => [
                'certificates' => [(int) $certificateId],
                'redirect_http' => true,
            ],
            'health_check' => $healthCheck,
        ];

        // redirect_http handles port 80 → no separate HTTP service needed
        return [$httpsService];
    }

    private function waitForAction(string $lbId, string $actionId): void
    {
        for ($i = 0; $i < 30; $i++) {
            sleep(2);

            $response = $this->api->get("/actions/{$actionId}");

            /** @var array<string, mixed> $action */
            $action = $response['action'] ?? [];
            $status = (string) ($action['status'] ?? '');

            if ($status === 'success') {
                return;
            }

            if ($status === 'error') {
                throw new \RuntimeException("Load balancer action {$actionId} failed");
            }
        }

        throw new \RuntimeException("Load balancer action {$actionId} timed out");
    }
}
