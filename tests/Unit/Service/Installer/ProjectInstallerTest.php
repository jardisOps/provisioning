<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Service\Installer;

use JardisOps\Provisioning\Service\Installer\ProjectInstaller;
use PHPUnit\Framework\TestCase;

final class ProjectInstallerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/prov-project-installer-test-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testMakefileWithoutSecretTargetsGetsPlainInclude(): void
    {
        file_put_contents($this->tmpDir . '/Makefile', "build:\n\techo build\n");

        (new ProjectInstaller($this->tmpDir))->install();

        $content = (string) file_get_contents($this->tmpDir . '/Makefile');

        self::assertStringContainsString(
            'include vendor/jardisops/provisioning/support/makefile/provision.mk',
            $content
        );
        self::assertStringNotContainsString('PROVISION_SKIP_SECRET_TARGETS', $content);
    }

    public function testMakefileWithDirectSecretTargetGetsSkipVariableBeforeInclude(): void
    {
        file_put_contents($this->tmpDir . '/Makefile', "encrypt:\n\techo encrypt\n");

        (new ProjectInstaller($this->tmpDir))->install();

        $content = (string) file_get_contents($this->tmpDir . '/Makefile');

        self::assertStringContainsString('PROVISION_SKIP_SECRET_TARGETS := 1', $content);
        self::assertMatchesRegularExpression(
            '/PROVISION_SKIP_SECRET_TARGETS := 1\s*\ninclude vendor\/jardisops\/provisioning\/support\/makefile\/provision\.mk/',
            $content
        );
    }

    public function testMakefileWithIncludedSecretMkGetsSkipVariable(): void
    {
        mkdir($this->tmpDir . '/support/makefile', 0755, true);
        file_put_contents(
            $this->tmpDir . '/support/makefile/secret.mk',
            "encrypt:\n\techo encrypt\n"
        );
        file_put_contents(
            $this->tmpDir . '/Makefile',
            "include support/makefile/secret.mk\n"
        );

        (new ProjectInstaller($this->tmpDir))->install();

        $content = (string) file_get_contents($this->tmpDir . '/Makefile');

        self::assertStringContainsString('PROVISION_SKIP_SECRET_TARGETS := 1', $content);
    }

    public function testMakefileAlreadyIncludingProvisionMkIsLeftUnchanged(): void
    {
        $original = "include vendor/jardisops/provisioning/support/makefile/provision.mk\n";
        file_put_contents($this->tmpDir . '/Makefile', $original);

        (new ProjectInstaller($this->tmpDir))->install();

        $content = (string) file_get_contents($this->tmpDir . '/Makefile');

        self::assertSame($original, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
