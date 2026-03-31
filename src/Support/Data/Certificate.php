<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

/**
 * A managed TLS certificate (e.g. Let's Encrypt via Hetzner).
 */
final class Certificate
{
    /**
     * @param string[] $domainNames
     */
    public function __construct(
        public readonly string $name,
        public readonly array $domainNames,
        public string $issuanceStatus = 'pending',
        public string $renewalStatus = 'unavailable',
        public string $id = '',
    ) {
    }

    public function isReady(): bool
    {
        return $this->issuanceStatus === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->issuanceStatus === 'failed';
    }
}
