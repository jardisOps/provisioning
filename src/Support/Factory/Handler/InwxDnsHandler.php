<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Factory\Handler;

use JardisOps\Provisioning\Provider\Inwx\InwxApiClient;
use JardisOps\Provisioning\Provider\Inwx\InwxDnsProvider;
use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Support\Contract\DnsProviderInterface;
use JardisOps\Provisioning\Support\Contract\DnsProviderHandler;
use RuntimeException;

final class InwxDnsHandler implements DnsProviderHandler
{
    private readonly string $user;
    private readonly string $password;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $user = (string) ($config['INWX_USER'] ?? '');
        $password = (string) ($config['INWX_PASSWORD'] ?? '');

        if ($user === '' || $password === '' || $password === '<password>') {
            throw new RuntimeException('INWX credentials are not configured');
        }

        $this->user = $user;
        $this->password = $password;
    }

    public function dnsProvider(): DnsProviderInterface
    {
        return new InwxDnsProvider(new InwxApiClient(new HttpClient(), $this->user, $this->password));
    }
}
