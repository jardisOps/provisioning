<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

enum NodeStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Stopped = 'stopped';
    case Deleted = 'deleted';
}
