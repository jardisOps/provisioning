<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Service\Cli;

use JardisOps\Provisioning\Service\Cli\ResolveEnvPath;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResolveEnvPathTest extends TestCase
{
    private string $tmpDir;
    private ResolveEnvPath $resolveEnvPath;
    private ?string $originalAppEnv = null;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/prov-resolve-env-path-test-' . uniqid();
        mkdir($this->tmpDir);
        $this->resolveEnvPath = new ResolveEnvPath();
        $this->originalAppEnv = $this->readAppEnv();
        $this->resetAppEnv();
    }

    protected function tearDown(): void
    {
        if ($this->originalAppEnv === null) {
            $this->resetAppEnv();
        } else {
            $_ENV['APP_ENV'] = $this->originalAppEnv;
            putenv('APP_ENV=' . $this->originalAppEnv);
        }

        $this->removeDirectory($this->tmpDir);
    }

    public function testNullOptionReturnsCurrentWorkingDirectory(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        self::assertSame($cwd, ($this->resolveEnvPath)(null));
    }

    public function testDirectoryOptionIsReturnedUnchanged(): void
    {
        self::assertSame($this->tmpDir, ($this->resolveEnvPath)($this->tmpDir));
    }

    public function testDotEnvNamedFileResolvesToDirectoryAndSetsAppEnv(): void
    {
        $envFile = $this->tmpDir . '/.env.provision';
        file_put_contents($envFile, "FOO=bar\n");

        $result = ($this->resolveEnvPath)($envFile);

        self::assertSame(realpath($this->tmpDir), $result);
        self::assertSame('provision', $this->readAppEnv());
        self::assertSame('provision', getenv('APP_ENV'));
    }

    public function testPlainDotEnvFileResolvesToDirectoryWithoutChangingAppEnv(): void
    {
        $envFile = $this->tmpDir . '/.env';
        file_put_contents($envFile, "FOO=bar\n");

        $result = ($this->resolveEnvPath)($envFile);

        self::assertSame(realpath($this->tmpDir), $result);
        self::assertNull($this->readAppEnv());
        self::assertFalse(getenv('APP_ENV'));
    }

    public function testNonExistentPathThrows(): void
    {
        $this->expectException(RuntimeException::class);

        ($this->resolveEnvPath)($this->tmpDir . '/does-not-exist');
    }

    private function readAppEnv(): ?string
    {
        $env = $_ENV;

        return isset($env['APP_ENV']) ? (string) $env['APP_ENV'] : null;
    }

    private function resetAppEnv(): void
    {
        $env = $_ENV;
        unset($env['APP_ENV']);
        $_ENV = $env;
        putenv('APP_ENV');
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
