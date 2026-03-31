<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Contract;

use JardisOps\Provisioning\Support\Contract\DnsProviderInterface;

interface DnsProviderHandler
{
    public function dnsProvider(): DnsProviderInterface;
}
