<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Provider\Inwx;

use JardisOps\Provisioning\Support\Contract\DnsProviderInterface;
use JardisOps\Provisioning\Support\Data\DnsRecord;
use RuntimeException;

/**
 * INWX implementation for DNS record management.
 */
final class InwxDnsProvider implements DnsProviderInterface
{
    private bool $loggedIn = false;

    public function __construct(
        private readonly InwxApiClient $api,
    ) {
    }

    public function createRecord(string $zone, DnsRecord $record): DnsRecord
    {
        $this->ensureLoggedIn();

        $response = $this->api->call('nameserver.createRecord', [
            'domain' => $zone,
            'name' => $record->subdomain . '.' . $zone,
            'type' => $record->type,
            'content' => $record->target,
            'ttl' => $record->ttl,
        ]);

        $this->assertSuccess($response, 'createRecord');

        /** @var array<string, mixed> $resData */
        $resData = $response['resData'];

        return new DnsRecord(
            $record->subdomain,
            $record->type,
            $record->target,
            $record->ttl,
            (string) ($resData['id'] ?? ''),
        );
    }

    public function updateRecord(string $zone, DnsRecord $record): DnsRecord
    {
        $this->ensureLoggedIn();

        if ($record->id === '') {
            throw new RuntimeException('Cannot update DNS record without ID');
        }

        $response = $this->api->call('nameserver.updateRecord', [
            'id' => (int) $record->id,
            'content' => $record->target,
            'ttl' => $record->ttl,
        ]);

        $this->assertSuccess($response, 'updateRecord');

        return $record;
    }

    public function deleteRecord(string $zone, string $recordId): void
    {
        $this->ensureLoggedIn();

        $response = $this->api->call('nameserver.deleteRecord', [
            'id' => (int) $recordId,
        ]);

        $this->assertSuccess($response, 'deleteRecord');
    }

    /** @return DnsRecord[] */
    public function listRecords(string $zone): array
    {
        $this->ensureLoggedIn();

        $response = $this->api->call('nameserver.info', [
            'domain' => $zone,
        ]);

        $this->assertSuccess($response, 'listRecords');

        /** @var array<string, mixed> $resData */
        $resData = $response['resData'];
        /** @var array<int, array<string, mixed>> $records */
        $records = $resData['record'] ?? [];

        return array_map(static function (array $r) use ($zone): DnsRecord {
            $name = (string) $r['name'];
            $subdomain = str_replace('.' . $zone, '', $name);

            return new DnsRecord(
                subdomain: $subdomain,
                type: (string) $r['type'],
                target: (string) $r['content'],
                ttl: (int) ($r['ttl'] ?? 300),
                id: (string) $r['id'],
            );
        }, $records);
    }

    private function ensureLoggedIn(): void
    {
        if (!$this->loggedIn) {
            $this->api->login();
            $this->loggedIn = true;
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    private function assertSuccess(array $response, string $operation): void
    {
        $code = (int) ($response['code'] ?? 0);
        if ($code < 1000 || $code >= 2000) {
            throw new RuntimeException(
                "INWX {$operation} failed: [{$code}] " . ($response['msg'] ?? 'unknown error')
            );
        }
    }
}
