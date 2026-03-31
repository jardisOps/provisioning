<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Service\Cli;

use JardisOps\Provisioning\Service\Installer\ProjectInstaller;
use JardisOps\Provisioning\Support\Data\Cluster;
use JardisOps\Provisioning\Support\Data\DeploymentMode;
use JardisOps\Provisioning\Support\Data\NodeRole;
use JardisOps\Provisioning\Provisioner;
use JardisOps\Provisioning\Support\Factory\ProvisionerFactory;
use JardisSupport\DotEnv\DotEnv;
use JardisSupport\Secret\Handler\SecretHandler;
use JardisSupport\Secret\KeyProvider\FileKeyProvider;
use JardisSupport\Secret\Resolver\AesSecretResolver;
use JardisSupport\Secret\Resolver\SodiumSecretResolver;
use RuntimeException;

/**
 * CLI application — parses commands and delegates to Provisioner.
 */
final class Application
{
    private readonly Output $output;

    public function __construct()
    {
        $this->output = new Output();
    }

    /**
     * @param string[] $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        try {
            return match ($command) {
                'init' => $this->init($args),
                'provision' => $this->provision($args),
                'deprovision' => $this->deprovision($args),
                'status' => $this->status($args),
                'node:add' => $this->addNode($args),
                'node:remove' => $this->removeNode($args),
                'secret:generate-key' => $this->generateKey($args),
                'secret:encrypt' => $this->encrypt($args, 'aes'),
                'secret:encrypt-sodium' => $this->encrypt($args, 'sodium'),
                'help', '--help', '-h' => $this->help(),
                'version', '--version', '-v' => $this->version(),
                default => $this->unknown($command),
            };
        } catch (RuntimeException $e) {
            $this->output->error($e->getMessage());
            return 1;
        }
    }

    /**
     * @param string[] $args
     */
    private function init(array $args): int
    {
        $projectRoot = $this->getOption($args, '--project-root') ?? (string) getcwd();

        $installer = new ProjectInstaller($projectRoot);
        $installer->install();

        return 0;
    }

    /**
     * @param string[] $args
     */
    private function provision(array $args): int
    {
        $dryRun = in_array('--dry-run', $args, true);
        $config = $this->loadConfig($args);

        if ($dryRun) {
            $mode = $config['PROVISION_MODE'] ?? '';
            $this->output->info("Dry run: would provision in '{$mode}' mode");
            $this->output->info('Provider: ' . ($config['INFRA_PROVIDER'] ?? ''));
            $this->output->info('DNS: ' . ($config['DNS_PROVIDER'] ?? ''));
            return 0;
        }

        $provisioner = $this->createProvisioner($config, $args);
        $cluster = $provisioner->provision($config);

        $this->output->success('Provisioning complete');
        $this->output->cluster($cluster);

        return 0;
    }

    /**
     * @param string[] $args
     */
    private function deprovision(array $args): int
    {
        $force = in_array('--force', $args, true);
        $config = $this->loadConfig($args);

        if (!$force) {
            $this->output->warning('This will destroy ALL provisioned resources.');
            $this->output->info('Use --force to skip confirmation.');
            return 1;
        }

        $provisioner = $this->createProvisioner($config, $args);
        $provisioner->deprovision();

        $this->output->success('All resources destroyed');

        return 0;
    }

    /**
     * @param string[] $args
     */
    private function status(array $args): int
    {
        $config = $this->loadConfig($args);
        $json = in_array('--json', $args, true);
        $provisioner = $this->createProvisioner($config, $args);
        $cluster = $provisioner->status();

        if ($cluster === null) {
            $this->output->info('No provisioned resources found');
            return 0;
        }

        if ($json) {
            $this->output->json($cluster);
        } else {
            $this->output->cluster($cluster);
        }

        return 0;
    }

    /**
     * @param string[] $args
     */
    private function addNode(array $args): int
    {
        $config = $this->loadConfig($args);
        $name = $this->getOption($args, '--name');
        $role = $this->getOption($args, '--role');
        $type = $this->getOption($args, '--type') ?? 'cpx31';
        $volumeSize = (int) ($this->getOption($args, '--volume') ?? 0);

        if ($name === null || $role === null) {
            $this->output->error('Usage: node:add --name=<name> --role=<server|agent> [--type=<type>] [--volume=<GB>]');
            return 1;
        }

        $provisioner = $this->createProvisioner($config, $args);
        $cluster = $provisioner->addNode($config, $name, NodeRole::from($role), $type, $volumeSize);

        $this->output->success("Node '{$name}' added");
        $this->output->cluster($cluster);

        return 0;
    }

    /**
     * @param string[] $args
     */
    private function removeNode(array $args): int
    {
        $config = $this->loadConfig($args);
        $name = $this->getOption($args, '--name');
        $deleteVolume = in_array('--delete-volume', $args, true);

        if ($name === null) {
            $this->output->error('Usage: node:remove --name=<name> [--delete-volume]');
            return 1;
        }

        $provisioner = $this->createProvisioner($config, $args);
        $cluster = $provisioner->removeNode($name, $deleteVolume);

        $this->output->success("Node '{$name}' removed");
        $this->output->cluster($cluster);

        return 0;
    }

    /**
     * @param string[] $args
     */
    private function generateKey(array $args): int
    {
        $keyFile = $this->getOption($args, '--key-file') ?? 'support/secret.key';

        if (file_exists($keyFile)) {
            $this->output->error("Key file already exists: {$keyFile}");
            return 1;
        }

        $dir = dirname($keyFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $key = sodium_crypto_secretbox_keygen();
        file_put_contents($keyFile, base64_encode($key));
        chmod($keyFile, 0600);

        $this->output->success("Key generated: {$keyFile}");

        return 0;
    }

    /**
     * @param string[] $args
     */
    private function encrypt(array $args, string $type): int
    {
        $value = $this->getOption($args, '--value');
        $keyFile = $this->getOption($args, '--key-file') ?? 'support/secret.key';

        if ($value === null) {
            $this->output->error('Usage: secret:encrypt --value=<plaintext> [--key-file=<path>]');
            return 1;
        }

        if (!file_exists($keyFile)) {
            $this->output->error("Key file not found: {$keyFile} — run secret:generate-key first");
            return 1;
        }

        $key = (new FileKeyProvider($keyFile))();

        $encrypted = match ($type) {
            'sodium' => 'secret(sodium:' . SodiumSecretResolver::encrypt($value, $key) . ')',
            default => 'secret(aes:' . AesSecretResolver::encrypt($value, $key) . ')',
        };

        echo $encrypted . "\n";

        return 0;
    }

    private function help(): int
    {
        echo <<<'HELP'
        provision v0.1.0

        Usage: provision <command> [options]

        Commands:
          init               Set up project (env template, Makefile, .gitignore)
          provision          Provision infrastructure (single or cluster)
          deprovision        Destroy all provisioned resources
          status             Show current infrastructure status
          node:add           Add a node to the cluster
          node:remove        Remove a node from the cluster
          secret:generate-key  Generate encryption key
          secret:encrypt       Encrypt a value (AES-256-GCM)
          secret:encrypt-sodium  Encrypt a value (Sodium)

        Options:
          --env-path=<path>  Path to .env files (default: current directory)
          --dry-run          Show what would be done without executing
          --force            Skip confirmation prompts
          --json             Output as JSON
          --volume=<GB>      Attach volume with given size (node:add)
          --delete-volume    Delete attached volume (node:remove)
          --value=<text>     Value to encrypt (secret:encrypt)
          --key-file=<path>  Key file path (default: support/secret.key)
          --help, -h         Show this help
          --version, -v      Show version

        HELP;

        return 0;
    }

    private function version(): int
    {
        echo "provision v0.1.0\n";
        return 0;
    }

    private function unknown(string $command): int
    {
        $this->output->error("Unknown command: {$command}");
        $this->help();
        return 1;
    }

    /**
     * @param string[] $args
     * @return array<string, mixed>
     */
    private function loadConfig(array $args): array
    {
        $envPath = $this->getOption($args, '--env-path') ?? getcwd();
        if ($envPath === false) {
            throw new RuntimeException('Cannot determine working directory');
        }

        $dotEnv = new DotEnv();

        $keyFile = (string) $envPath . '/support/secret.key';
        if (file_exists($keyFile)) {
            $dotEnv->addHandler(new SecretHandler(new FileKeyProvider($keyFile)), prepend: true);
        }

        return $dotEnv->loadPrivate((string) $envPath);
    }

    /**
     * @param array<string, mixed> $config
     * @param string[] $args
     */
    private function createProvisioner(array $config, array $args): Provisioner
    {
        $envPath = $this->getOption($args, '--env-path') ?? getcwd();
        if ($envPath === false) {
            throw new RuntimeException('Cannot determine working directory');
        }

        return ProvisionerFactory::create($config, (string) $envPath);
    }

    /**
     * @param string[] $args
     */
    private function getOption(array $args, string $name): ?string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $name . '=')) {
                return substr($arg, strlen($name) + 1);
            }
        }

        return null;
    }
}
