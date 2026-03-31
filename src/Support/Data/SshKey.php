<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

/**
 * SSH key for server access.
 */
final readonly class SshKey
{
    public function __construct(
        public string $name,
        public string $publicKeyPath,
        public string $providerId = '',
    ) {
    }
}
