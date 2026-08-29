<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Service\Cli;

use RuntimeException;

/**
 * Resolves --env-path into a directory DotEnv can load from.
 *
 * Accepts a directory (unchanged), a `.env.<name>` file (directory = its parent,
 * APP_ENV=<name> is set so the DotEnv cascade picks up the file in stage 2), a plain
 * `.env` file (only its parent directory is used), or null (current working directory).
 */
final class ResolveEnvPath
{
    public function __invoke(?string $option): string
    {
        if ($option === null) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new RuntimeException('Cannot determine working directory');
            }

            return $cwd;
        }

        if (is_dir($option)) {
            return $option;
        }

        if (is_file($option)) {
            return $this->resolveFile($option);
        }

        throw new RuntimeException("--env-path does not exist: {$option}");
    }

    private function resolveFile(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new RuntimeException("--env-path does not exist: {$path}");
        }

        $directory = dirname($realPath);
        $basename = basename($realPath);

        if ($basename !== '.env' && preg_match('/^\.env\.(.+)$/', $basename, $matches) === 1) {
            $_ENV['APP_ENV'] = $matches[1];
            putenv('APP_ENV=' . $matches[1]);
        }

        return $directory;
    }
}
