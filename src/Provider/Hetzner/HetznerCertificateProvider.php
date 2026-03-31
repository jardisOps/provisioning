<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Provider\Hetzner;

use JardisOps\Provisioning\Support\Contract\CertificateProviderInterface;
use JardisOps\Provisioning\Support\Data\Certificate;
use RuntimeException;

/**
 * Hetzner Cloud implementation for managed TLS certificates (Let's Encrypt).
 *
 * Uses DNS-01 challenge — requires the domain to be in a Hetzner DNS zone.
 * Certificates are automatically renewed by Hetzner as long as they are
 * assigned to a load balancer.
 */
final class HetznerCertificateProvider implements CertificateProviderInterface
{
    private const POLL_INTERVAL_SECONDS = 5;
    private const POLL_MAX_ATTEMPTS = 60;

    public function __construct(
        private readonly HetznerApiClient $api,
    ) {
    }

    public function createManagedCertificate(Certificate $certificate): Certificate
    {
        $response = $this->api->post('/certificates', [
            'name' => $certificate->name,
            'type' => 'managed',
            'domain_names' => $certificate->domainNames,
        ]);

        /** @var array<string, mixed> $cert */
        $cert = $response['certificate'];

        $created = $this->mapCertificate($cert);

        if ($created->isReady()) {
            return $created;
        }

        return $this->waitForIssuance($created->id);
    }

    public function getCertificate(string $certificateId): Certificate
    {
        $response = $this->api->get("/certificates/{$certificateId}");

        /** @var array<string, mixed> $cert */
        $cert = $response['certificate'];

        return $this->mapCertificate($cert);
    }

    public function deleteCertificate(string $certificateId): void
    {
        $this->api->delete("/certificates/{$certificateId}");
    }

    public function findExistingCertificate(array $domainNames): ?Certificate
    {
        $response = $this->api->get('/certificates');

        /** @var array<int, array<string, mixed>> $certificates */
        $certificates = $response['certificates'] ?? [];

        sort($domainNames);

        foreach ($certificates as $cert) {
            if (($cert['type'] ?? '') !== 'managed') {
                continue;
            }

            /** @var string[] $certDomains */
            $certDomains = $cert['domain_names'] ?? [];
            sort($certDomains);

            if ($certDomains !== $domainNames) {
                continue;
            }

            $mapped = $this->mapCertificate($cert);

            if ($mapped->isReady()) {
                return $mapped;
            }
        }

        return null;
    }

    private function waitForIssuance(string $certificateId): Certificate
    {
        for ($i = 0; $i < self::POLL_MAX_ATTEMPTS; $i++) {
            sleep(self::POLL_INTERVAL_SECONDS);

            $cert = $this->getCertificate($certificateId);

            if ($cert->isReady()) {
                return $cert;
            }

            if ($cert->isFailed()) {
                throw new RuntimeException(
                    "Certificate issuance failed for: " . implode(', ', $cert->domainNames)
                );
            }
        }

        throw new RuntimeException(
            "Certificate issuance timed out after "
            . (self::POLL_INTERVAL_SECONDS * self::POLL_MAX_ATTEMPTS)
            . " seconds"
        );
    }

    /**
     * @param array<string, mixed> $cert
     */
    private function mapCertificate(array $cert): Certificate
    {
        /** @var array<string, mixed> $status */
        $status = $cert['status'] ?? [];

        return new Certificate(
            name: (string) $cert['name'],
            domainNames: (array) ($cert['domain_names'] ?? []),
            issuanceStatus: (string) ($status['issuance'] ?? 'pending'),
            renewalStatus: (string) ($status['renewal'] ?? 'unavailable'),
            id: (string) $cert['id'],
        );
    }
}
