<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

/**
 * A load balancer distributing traffic to nodes.
 */
final class LoadBalancer
{
    /**
     * @param string[] $targets Node names registered as targets
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public string $publicIp = '',
        public array $targets = [],
        public string $algorithm = 'round_robin',
        public string $healthCheckProtocol = 'http',
        public int $healthCheckPort = 80,
        public string $healthCheckPath = '/health',
        public int $healthCheckInterval = 15,
        public int $healthCheckTimeout = 10,
        public int $healthCheckRetries = 3,
        public string $id = '',
    ) {
    }

    public function addTarget(string $nodeName): void
    {
        if (!in_array($nodeName, $this->targets, true)) {
            $this->targets[] = $nodeName;
        }
    }

    public function removeTarget(string $nodeName): void
    {
        $this->targets = array_values(
            array_filter($this->targets, static fn(string $t): bool => $t !== $nodeName)
        );
    }
}
