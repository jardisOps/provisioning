<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Factory\Handler;

use JardisOps\Provisioning\Provider\Hetzner\HetznerApiClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerDnsProvider;
use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Support\Contract\DnsProviderInterface;
use JardisOps\Provisioning\Support\Contract\DnsProviderHandler;
use RuntimeException;

final class HetznerDnsHandler implements DnsProviderHandler
{
    private readonly string $token;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $token = (string) ($config['HETZNER_DNS_TOKEN'] ?? $config['HETZNER_API_TOKEN'] ?? '');
        if ($token === '' || $token === '<token>') {
            throw new RuntimeException('HETZNER_DNS_TOKEN (or HETZNER_API_TOKEN) is not configured');
        }

        $this->token = $token;
    }

    public function dnsProvider(): DnsProviderInterface
    {
        return new HetznerDnsProvider(new HetznerApiClient(new HttpClient(), $this->token));
    }
}
