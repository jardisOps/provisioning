<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Service\Installer;

use JardisOps\Provisioning\Service\Installer\DetectMakeTargets;
use PHPUnit\Framework\TestCase;

final class DetectMakeTargetsTest extends TestCase
{
    private string $tmpDir;
    private DetectMakeTargets $detectMakeTargets;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/prov-detect-make-targets-test-' . uniqid();
        mkdir($this->tmpDir);
        $this->detectMakeTargets = new DetectMakeTargets();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testReturnsFalseWhenNoTargetDefined(): void
    {
        $makefile = $this->tmpDir . '/Makefile';
        file_put_contents($makefile, "build:\n\techo build\n");

        self::assertFalse(($this->detectMakeTargets)($makefile, ['encrypt', 'encrypt-sodium']));
    }

    public function testReturnsTrueWhenTargetDefinedDirectly(): void
    {
        $makefile = $this->tmpDir . '/Makefile';
        file_put_contents($makefile, "encrypt:\n\techo encrypt\n");

        self::assertTrue(($this->detectMakeTargets)($makefile, ['generate-key-file', 'encrypt', 'encrypt-sodium']));
    }

    public function testReturnsTrueWhenTargetDefinedInIncludedFile(): void
    {
        mkdir($this->tmpDir . '/support/makefile', 0755, true);
        $secretMk = $this->tmpDir . '/support/makefile/secret.mk';
        file_put_contents($secretMk, "encrypt:\n\techo encrypt\n");

        $makefile = $this->tmpDir . '/Makefile';
        file_put_contents($makefile, "include support/makefile/secret.mk\n");

        self::assertTrue(($this->detectMakeTargets)($makefile, ['generate-key-file', 'encrypt', 'encrypt-sodium']));
    }

    public function testReturnsFalseWhenIncludedFileDoesNotExist(): void
    {
        $makefile = $this->tmpDir . '/Makefile';
        file_put_contents($makefile, "include support/makefile/does-not-exist.mk\n");

        self::assertFalse(($this->detectMakeTargets)($makefile, ['encrypt']));
    }

    public function testReturnsFalseWhenMakefileDoesNotExist(): void
    {
        self::assertFalse(($this->detectMakeTargets)($this->tmpDir . '/Makefile', ['encrypt']));
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
