<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Integration\Provider\Hetzner;

use JardisSupport\DotEnv\DotEnv;

/**
 * Loads Hetzner test fixtures via DotEnv.
 *
 * Reads tests/fixtures/hetzner/.env + .env.local
 */
trait HetznerTestConfig
{
    /** @var array<string, mixed> */
    private array $config = [];

    private function loadHetznerConfig(): void
    {
        $fixturesPath = dirname(__DIR__, 3) . '/fixtures/hetzner';
        $this->config = (new DotEnv())->loadPrivate($fixturesPath);
    }

    private function requireToken(): string
    {
        $token = (string) ($this->config['HETZNER_API_TOKEN'] ?? '');
        if ($token === '' || $token === '<token>') {
            self::markTestSkipped('HETZNER_API_TOKEN not set — add it to tests/fixtures/hetzner/.env.local');
        }

        return $token;
    }

    private function requireDnsToken(): string
    {
        $token = (string) ($this->config['HETZNER_DNS_TOKEN'] ?? $this->config['HETZNER_API_TOKEN'] ?? '');
        if ($token === '' || $token === '<token>') {
            self::markTestSkipped('HETZNER_DNS_TOKEN not set — add it to tests/fixtures/hetzner/.env.local');
        }

        return $token;
    }
}
