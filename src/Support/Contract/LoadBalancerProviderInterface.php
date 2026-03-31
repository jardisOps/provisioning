<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Contract;

use JardisOps\Provisioning\Support\Data\LoadBalancer;

/**
 * Creates and manages load balancers.
 */
interface LoadBalancerProviderInterface
{
    public function createLoadBalancer(
        LoadBalancer $loadBalancer,
        string $region,
        string $networkId,
        string $certificateId = '',
    ): LoadBalancer;

    public function deleteLoadBalancer(string $loadBalancerId): void;

    public function addTarget(string $loadBalancerId, string $serverId): void;

    public function removeTarget(string $loadBalancerId, string $serverId): void;

    public function getLoadBalancer(string $loadBalancerId): LoadBalancer;
}
