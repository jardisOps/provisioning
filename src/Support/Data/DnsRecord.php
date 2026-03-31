<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

/**
 * A single DNS record entry.
 */
final readonly class DnsRecord
{
    public function __construct(
        public string $subdomain,
        public string $type,
        public string $target,
        public int $ttl = 300,
        public string $id = '',
    ) {
    }
}
