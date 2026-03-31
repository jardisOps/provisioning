<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Contract;

use JardisOps\Provisioning\Support\Data\Certificate;

/**
 * Manages TLS certificates (e.g. Let's Encrypt managed certificates).
 */
interface CertificateProviderInterface
{
    public function createManagedCertificate(Certificate $certificate): Certificate;

    public function getCertificate(string $certificateId): Certificate;

    public function deleteCertificate(string $certificateId): void;

    /**
     * Find an existing valid certificate for the given domain names.
     *
     * @param string[] $domainNames
     */
    public function findExistingCertificate(array $domainNames): ?Certificate;
}
