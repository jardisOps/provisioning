<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

/**
 * A private network connecting cluster nodes.
 */
final class PrivateNetwork
{
    public function __construct(
        public readonly string $name,
        public readonly string $subnet,
        public readonly string $zone,
        public string $id = '',
    ) {
    }
}
