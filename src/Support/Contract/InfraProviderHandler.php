<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Contract;

interface InfraProviderHandler
{
    public function serverProvider(): ServerProviderInterface;

    public function networkProvider(): NetworkProviderInterface;

    public function firewallProvider(): FirewallProviderInterface;

    public function loadBalancerProvider(): LoadBalancerProviderInterface;

    public function certificateProvider(): CertificateProviderInterface;

    public function volumeProvider(): VolumeProviderInterface;
}
