<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Provider\Hetzner;

use JardisOps\Provisioning\Support\Contract\DnsProviderInterface;
use JardisOps\Provisioning\Support\Data\DnsRecord;
use RuntimeException;

/**
 * Hetzner Cloud DNS implementation using the Cloud API (rrsets).
 */
final class HetznerDnsProvider implements DnsProviderInterface
{
    /** @var array<string, int> zone name → zone ID cache */
    private array $zoneIds = [];

    public function __construct(
        private readonly HetznerApiClient $api,
    ) {
    }

    public function createRecord(string $zone, DnsRecord $record): DnsRecord
    {
        // If record already exists, delete first then recreate
        $existing = $this->findRecord($zone, $record->subdomain, $record->type);
        if ($existing !== null) {
            $this->deleteRecord($zone, $existing->id);
        }

        $zoneId = $this->resolveZoneId($zone);

        $response = $this->api->post("/zones/{$zoneId}/rrsets", [
            'name' => $record->subdomain,
            'type' => $record->type,
            'ttl' => $record->ttl,
            'records' => [
                ['value' => $record->target],
            ],
        ]);

        /** @var array<string, mixed> $rrset */
        $rrset = $response['rrset'];

        return new DnsRecord(
            $record->subdomain,
            $record->type,
            $record->target,
            $record->ttl,
            (string) $rrset['id'],
        );
    }

    public function updateRecord(string $zone, DnsRecord $record): DnsRecord
    {
        if ($record->id === '') {
            throw new RuntimeException('Cannot update DNS record without ID');
        }

        $zoneId = $this->resolveZoneId($zone);

        $response = $this->api->put("/zones/{$zoneId}/rrsets/{$record->id}", [
            'name' => $record->subdomain,
            'type' => $record->type,
            'ttl' => $record->ttl,
            'records' => [
                ['value' => $record->target],
            ],
        ]);

        /** @var array<string, mixed> $rrset */
        $rrset = $response['rrset'];
        /** @var array<int, array<string, mixed>> $records */
        $records = $rrset['records'] ?? [];

        return new DnsRecord(
            $record->subdomain,
            $record->type,
            $records !== [] ? (string) $records[0]['value'] : $record->target,
            (int) ($rrset['ttl'] ?? $record->ttl),
            (string) $rrset['id'],
        );
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        $zoneId = $this->resolveZoneId($zone);

        $this->api->delete("/zones/{$zoneId}/rrsets/{$recordId}");
    }

    /** @return DnsRecord[] */
    public function listRecords(string $zone): array
    {
        $zoneId = $this->resolveZoneId($zone);

        $response = $this->api->get("/zones/{$zoneId}/rrsets");

        /** @var array<int, array<string, mixed>> $rrsets */
        $rrsets = $response['rrsets'] ?? [];

        $result = [];
        foreach ($rrsets as $rrset) {
            /** @var array<int, array<string, mixed>> $records */
            $records = $rrset['records'] ?? [];
            foreach ($records as $record) {
                $result[] = new DnsRecord(
                    subdomain: (string) $rrset['name'],
                    type: (string) $rrset['type'],
                    target: (string) $record['value'],
                    ttl: (int) ($rrset['ttl'] ?? 300),
                    id: (string) $rrset['id'],
                );
            }
        }

        return $result;
    }

    private function resolveZoneId(string $zone): int
    {
        if (isset($this->zoneIds[$zone])) {
            return $this->zoneIds[$zone];
        }

        $response = $this->api->get('/zones', ['name' => $zone]);

        /** @var array<int, array<string, mixed>> $zones */
        $zones = $response['zones'] ?? [];

        if ($zones === []) {
            throw new RuntimeException("DNS zone not found: {$zone}");
        }

        $this->zoneIds[$zone] = (int) $zones[0]['id'];

        return $this->zoneIds[$zone];
    }

    private function findRecord(string $zone, string $subdomain, string $type): ?DnsRecord
    {
        $records = $this->listRecords($zone);

        foreach ($records as $record) {
            if ($record->subdomain === $subdomain && $record->type === $type) {
                return $record;
            }
        }

        return null;
    }
}
