<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Factory;

use JardisOps\Provisioning\Provisioner;
use JardisOps\Provisioning\Service\State\StateManager;
use JardisOps\Provisioning\Support\Data\DnsProvider;
use JardisOps\Provisioning\Support\Data\InfraProvider;
use JardisOps\Provisioning\Support\Factory\Handler\HetznerDnsHandler;
use JardisOps\Provisioning\Support\Factory\Handler\HetznerInfraHandler;
use JardisOps\Provisioning\Support\Factory\Handler\InwxDnsHandler;
use RuntimeException;

/**
 * Creates a fully wired Provisioner from config.
 */
final class ProvisionerFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config, string $basePath): Provisioner
    {
        $infraProviderName = (string) ($config['INFRA_PROVIDER'] ?? '');
        if ($infraProviderName === '') {
            throw new RuntimeException('INFRA_PROVIDER is not configured');
        }

        $infraEnum = InfraProvider::tryFrom($infraProviderName)
            ?? throw new RuntimeException("Unknown infra provider: {$infraProviderName}");

        $infra = match ($infraEnum) {
            InfraProvider::Hetzner => new HetznerInfraHandler($config),
        };

        $dnsProviderName = (string) ($config['DNS_PROVIDER'] ?? '');
        $dnsProvider = null;

        if ($dnsProviderName !== '') {
            $dnsEnum = DnsProvider::tryFrom($dnsProviderName)
                ?? throw new RuntimeException("Unknown DNS provider: {$dnsProviderName}");

            $dnsHandler = match ($dnsEnum) {
                DnsProvider::Hetzner => new HetznerDnsHandler($config),
                DnsProvider::Inwx => new InwxDnsHandler($config),
            };

            $dnsProvider = $dnsHandler->dnsProvider();
        }

        return new Provisioner(
            $infra->serverProvider(),
            $infra->networkProvider(),
            $infra->firewallProvider(),
            $infra->loadBalancerProvider(),
            $dnsProvider,
            $infra->certificateProvider(),
            $infra->volumeProvider(),
            new StateManager($basePath),
            $infraProviderName,
            $dnsProviderName,
        );
    }
}
