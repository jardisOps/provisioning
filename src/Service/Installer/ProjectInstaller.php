<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Service\Installer;

use JardisOps\Provisioning\Service\Cli\Output;

/**
 * Sets up provisioning in a consumer project:
 * - .env.provision.example (Hetzner template)
 * - Makefile include
 * - .gitignore entries
 */
final class ProjectInstaller
{
    private const GITIGNORE_ENTRIES = [
        '.env.local',
        '.env.*.local',
        '.provision-state.json',
        'support/secret.key',
    ];

    private const MAKEFILE_INCLUDE = 'include vendor/jardisops/provisioning/support/makefile/provision.mk';

    private readonly Output $output;
    private readonly string $projectRoot;
    private readonly string $packageRoot;

    public function __construct(string $projectRoot)
    {
        $this->output = new Output();
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->packageRoot = dirname(__DIR__, 3);
    }

    public function install(): void
    {
        $this->installEnvExample();
        $this->installMakefile();
        $this->installGitignore();

        echo "\n";
        $this->output->info('Next: Edit .env.provision.example, rename to .env, add your API tokens.');
        $this->output->info('INWX DNS template: vendor/jardisops/provisioning/src/Provider/Inwx/.env.example');
    }

    private function installEnvExample(): void
    {
        $target = $this->projectRoot . '/.env.provision.example';
        $source = $this->packageRoot . '/src/Provider/Hetzner/.env.example';

        if (file_exists($target)) {
            $this->output->info('Skipped: .env.provision.example already exists');
            return;
        }

        if (!file_exists($source)) {
            $this->output->warning('Source not found: ' . $source);
            return;
        }

        copy($source, $target);
        $this->output->success('Created .env.provision.example (Hetzner template)');
    }

    private function installMakefile(): void
    {
        $target = $this->projectRoot . '/Makefile';

        if (file_exists($target)) {
            $content = (string) file_get_contents($target);
            if (str_contains($content, 'provision.mk')) {
                $this->output->info('Skipped: Makefile already includes provision.mk');
                return;
            }

            $content = rtrim($content) . "\n\n" . self::MAKEFILE_INCLUDE . "\n";
            file_put_contents($target, $content);
            $this->output->success('Updated Makefile — added provision.mk include');
            return;
        }

        file_put_contents($target, self::MAKEFILE_INCLUDE . "\n");
        $this->output->success('Created Makefile with provision.mk include');
    }

    private function installGitignore(): void
    {
        $target = $this->projectRoot . '/.gitignore';

        if (file_exists($target)) {
            $content = (string) file_get_contents($target);
            $lines = array_map('trim', explode("\n", $content));
            $missing = [];

            foreach (self::GITIGNORE_ENTRIES as $entry) {
                if (!in_array($entry, $lines, true)) {
                    $missing[] = $entry;
                }
            }

            if ($missing === []) {
                $this->output->info('Skipped: .gitignore already has all provisioning entries');
                return;
            }

            $content = rtrim($content) . "\n\n# Provisioning\n" . implode("\n", $missing) . "\n";
            file_put_contents($target, $content);
            $this->output->success('Updated .gitignore — added: ' . implode(', ', $missing));
            return;
        }

        $content = "# Provisioning\n" . implode("\n", self::GITIGNORE_ENTRIES) . "\n";
        file_put_contents($target, $content);
        $this->output->success('Created .gitignore with provisioning entries');
    }
}
