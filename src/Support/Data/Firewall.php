<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

/**
 * A firewall with a set of rules.
 */
final class Firewall
{
    /**
     * @param FirewallRule[] $rules
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public array $rules = [],
        public string $id = '',
    ) {
    }

    public function addRule(FirewallRule $rule): void
    {
        $this->rules[] = $rule;
    }
}
