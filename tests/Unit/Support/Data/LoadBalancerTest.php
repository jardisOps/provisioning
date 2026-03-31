<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Support\Data;

use JardisOps\Provisioning\Support\Data\LoadBalancer;
use PHPUnit\Framework\TestCase;

final class LoadBalancerTest extends TestCase
{
    public function testAddTarget(): void
    {
        $lb = new LoadBalancer('lb', 'lb11');
        $lb->addTarget('agent-1');
        $lb->addTarget('agent-2');

        self::assertSame(['agent-1', 'agent-2'], $lb->targets);
    }

    public function testAddTargetNoDuplicates(): void
    {
        $lb = new LoadBalancer('lb', 'lb11');
        $lb->addTarget('agent-1');
        $lb->addTarget('agent-1');

        self::assertCount(1, $lb->targets);
    }

    public function testRemoveTarget(): void
    {
        $lb = new LoadBalancer('lb', 'lb11', targets: ['agent-1', 'agent-2', 'agent-3']);

        $lb->removeTarget('agent-2');

        self::assertSame(['agent-1', 'agent-3'], $lb->targets);
    }

    public function testRemoveNonexistentTarget(): void
    {
        $lb = new LoadBalancer('lb', 'lb11', targets: ['agent-1']);

        $lb->removeTarget('nonexistent');

        self::assertSame(['agent-1'], $lb->targets);
    }
}
