<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Support\Data;

use JardisOps\Provisioning\Support\Data\CloudInitScript;
use PHPUnit\Framework\TestCase;

final class CloudInitScriptTest extends TestCase
{
    public function testRenderContainsCloudConfig(): void
    {
        $script = new CloudInitScript('deploy', 'ssh-ed25519 AAAA...');

        $output = $script->render();

        self::assertStringStartsWith('#cloud-config', $output);
    }

    public function testRenderContainsDeployUser(): void
    {
        $script = new CloudInitScript('myuser', 'ssh-ed25519 AAAA...');

        $output = $script->render();

        self::assertStringContainsString('name: myuser', $output);
    }

    public function testRenderContainsSshKey(): void
    {
        $key = 'ssh-ed25519 AAAA...';
        $script = new CloudInitScript('deploy', $key);

        $output = $script->render();

        self::assertStringContainsString($key, $output);
    }

    public function testRenderCustomSshPort(): void
    {
        $script = new CloudInitScript('deploy', 'ssh-ed25519 AAAA...', sshPort: 2222);

        $output = $script->render();

        self::assertStringContainsString('Port 2222', $output);
    }

    public function testRenderDisablesRootByDefault(): void
    {
        $script = new CloudInitScript('deploy', 'ssh-ed25519 AAAA...');

        $output = $script->render();

        self::assertStringContainsString('PermitRootLogin no', $output);
        self::assertStringContainsString('disable_root: true', $output);
    }

    public function testRenderWithFail2ban(): void
    {
        $script = new CloudInitScript('deploy', 'ssh-ed25519 AAAA...', fail2ban: true);

        $output = $script->render();

        self::assertStringContainsString('- fail2ban', $output);
        self::assertStringContainsString('systemctl enable fail2ban', $output);
    }

    public function testRenderWithoutFail2ban(): void
    {
        $script = new CloudInitScript('deploy', 'ssh-ed25519 AAAA...', fail2ban: false);

        $output = $script->render();

        self::assertStringNotContainsString('fail2ban', $output);
    }

    public function testRenderWithAutoUpdates(): void
    {
        $script = new CloudInitScript('deploy', 'ssh-ed25519 AAAA...', autoUpdates: true);

        $output = $script->render();

        self::assertStringContainsString('unattended-upgrades', $output);
    }
}
