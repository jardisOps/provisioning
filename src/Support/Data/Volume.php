<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

/**
 * A block storage volume that can be attached to a server.
 */
final class Volume
{
    public function __construct(
        public readonly string $name,
        public readonly int $size,
        public string $serverId = '',
        public string $id = '',
    ) {
    }
}
