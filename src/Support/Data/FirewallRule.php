<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

/**
 * A single firewall rule.
 */
final readonly class FirewallRule
{
    /**
     * @param string $direction   'in' or 'out'
     * @param string $protocol    'tcp' or 'udp'
     * @param string $port        Single port or range (e.g. '443' or '30000-32767')
     * @param string[] $sourceIps Allowed source CIDRs (e.g. ['0.0.0.0/0'] or ['10.0.1.0/24'])
     */
    public function __construct(
        public string $direction,
        public string $protocol,
        public string $port,
        public array $sourceIps = ['0.0.0.0/0', '::/0'],
    ) {
    }
}
