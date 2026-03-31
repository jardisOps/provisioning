<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

enum DnsProvider: string
{
    case Hetzner = 'hetzner';
    case Inwx = 'inwx';
}
