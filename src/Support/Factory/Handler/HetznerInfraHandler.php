<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Factory\Handler;

use JardisOps\Provisioning\Provider\Hetzner\HetznerApiClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerCertificateProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerVolumeProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerFirewallProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerLoadBalancerProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerNetworkProvider;
use JardisOps\Provisioning\Provider\Hetzner\HetznerServerProvider;
use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Support\Contract\CertificateProviderInterface;
use JardisOps\Provisioning\Support\Contract\VolumeProviderInterface;
use JardisOps\Provisioning\Support\Contract\FirewallProviderInterface;
use JardisOps\Provisioning\Support\Contract\InfraProviderHandler;
use JardisOps\Provisioning\Support\Contract\LoadBalancerProviderInterface;
use JardisOps\Provisioning\Support\Contract\NetworkProviderInterface;
use JardisOps\Provisioning\Support\Contract\ServerProviderInterface;
use RuntimeException;

final class HetznerInfraHandler implements InfraProviderHandler
{
    private readonly HetznerApiClient $api;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $token = (string) ($config['HETZNER_API_TOKEN'] ?? '');
        if ($token === '' || $token === '<token>') {
            throw new RuntimeException('HETZNER_API_TOKEN is not configured');
        }

        $this->api = new HetznerApiClient(new HttpClient(), $token);
    }

    public function serverProvider(): ServerProviderInterface
    {
        return new HetznerServerProvider($this->api);
    }

    public function networkProvider(): NetworkProviderInterface
    {
        return new HetznerNetworkProvider($this->api);
    }

    public function firewallProvider(): FirewallProviderInterface
    {
        return new HetznerFirewallProvider($this->api);
    }

    public function loadBalancerProvider(): LoadBalancerProviderInterface
    {
        return new HetznerLoadBalancerProvider($this->api);
    }

    public function certificateProvider(): CertificateProviderInterface
    {
        return new HetznerCertificateProvider($this->api);
    }

    public function volumeProvider(): VolumeProviderInterface
    {
        return new HetznerVolumeProvider($this->api);
    }
}
