<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

/**
 * A single server node in the infrastructure.
 */
final class Node
{
    public function __construct(
        public readonly string $name,
        public readonly NodeRole $role,
        public readonly string $serverType,
        public string $publicIp = '',
        public string $privateIp = '',
        public NodeStatus $status = NodeStatus::Pending,
        public string $id = '',
    ) {
    }
}
