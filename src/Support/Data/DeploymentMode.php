<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Data;

enum DeploymentMode: string
{
    case Single = 'single';
    case Cluster = 'cluster';
}
