<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

enum NodeRole: string
{
    case Server = 'server';
    case Agent = 'agent';
}
