<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Support\Contract;

use JardisOps\Provisioning\Support\Data\DnsRecord;

/**
 * Manages DNS records.
 */
interface DnsProviderInterface
{
    public function createRecord(string $zone, DnsRecord $record): DnsRecord;

    public function updateRecord(string $zone, DnsRecord $record): DnsRecord;

    public function deleteRecord(string $zone, string $recordId): void;

    /** @return DnsRecord[] */
    public function listRecords(string $zone): array;
}
