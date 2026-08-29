<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Service\Installer;

/**
 * Checks whether a Makefile (or any file it `include`s) already defines one of the
 * given targets, so an installer can avoid appending a colliding include.
 */
final class DetectMakeTargets
{
    /**
     * @param string[] $targets Target names to look for, e.g. ['encrypt', 'encrypt-sodium']
     */
    public function __invoke(string $makefilePath, array $targets): bool
    {
        return $this->fileDefinesAnyTarget($makefilePath, $targets, []);
    }

    /**
     * @param string[] $targets
     * @param string[] $visited Already-visited real paths, to avoid include cycles
     */
    private function fileDefinesAnyTarget(string $path, array $targets, array $visited): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $realPath = realpath($path);
        if ($realPath !== false) {
            if (in_array($realPath, $visited, true)) {
                return false;
            }
            $visited[] = $realPath;
        }

        $content = (string) file_get_contents($path);

        if ($this->definesAnyTarget($content, $targets)) {
            return true;
        }

        $baseDir = dirname($path);
        foreach ($this->includedPaths($content) as $includedPath) {
            $resolvedIncludePath = $this->resolveIncludePath($includedPath, $baseDir);
            if ($this->fileDefinesAnyTarget($resolvedIncludePath, $targets, $visited)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $targets
     */
    private function definesAnyTarget(string $content, array $targets): bool
    {
        $escapedTargets = array_map(
            static fn (string $target): string => preg_quote($target, '/'),
            $targets
        );
        $pattern = '/^(' . implode('|', $escapedTargets) . '):/m';

        return preg_match($pattern, $content) === 1;
    }

    /**
     * @return string[]
     */
    private function includedPaths(string $content): array
    {
        if (preg_match_all('/^include\s+(.+)$/m', $content, $matches) === false) {
            return [];
        }

        $paths = [];
        foreach ($matches[1] as $match) {
            foreach (preg_split('/\s+/', trim($match)) ?: [] as $candidate) {
                if ($candidate !== '') {
                    $paths[] = $candidate;
                }
            }
        }

        return $paths;
    }

    private function resolveIncludePath(string $includedPath, string $baseDir): string
    {
        if (str_starts_with($includedPath, '/')) {
            return $includedPath;
        }

        return $baseDir . '/' . $includedPath;
    }
}
