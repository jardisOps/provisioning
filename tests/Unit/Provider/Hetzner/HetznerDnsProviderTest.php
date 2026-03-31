<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Hetzner;

use JardisOps\Provisioning\Provider\Hetzner\HetznerDnsProvider;
use JardisOps\Provisioning\Support\Data\DnsRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HetznerDnsProviderTest extends TestCase
{
    private MockHttpClient $httpClient;
    private HetznerDnsProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();
        $api = HetznerTestHelper::createApiClient($this->httpClient);
        $this->provider = new HetznerDnsProvider($api);
    }

    public function testCreateRecordWithoutExisting(): void
    {
        // findRecord: resolveZoneId + listRecords
        $this->queueZoneResponse();
        $this->queueEmptyRrsets();

        // createRecord: resolveZoneId cached, POST
        $this->httpClient->queueResponse(201, json_encode([
            'rrset' => [
                'id' => 'api/A',
                'name' => 'api',
                'type' => 'A',
                'ttl' => 300,
                'records' => [['value' => '1.2.3.4']],
            ],
        ], JSON_THROW_ON_ERROR));

        $record = new DnsRecord('api', 'A', '1.2.3.4', 300);
        $result = $this->provider->createRecord('example.com', $record);

        self::assertSame('api/A', $result->id);
        self::assertSame('api', $result->subdomain);
        self::assertSame('A', $result->type);
        self::assertSame('1.2.3.4', $result->target);
        self::assertSame(300, $result->ttl);
    }

    public function testCreateRecordDeletesExistingFirst(): void
    {
        // findRecord: resolveZoneId + listRecords (with existing)
        $this->queueZoneResponse();
        $this->httpClient->queueResponse(200, json_encode([
            'rrsets' => [
                [
                    'id' => 'api/A',
                    'name' => 'api',
                    'type' => 'A',
                    'ttl' => 300,
                    'records' => [['value' => '9.9.9.9']],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        // deleteRecord (zone cached)
        $this->httpClient->queueResponse(204, '');

        // createRecord POST
        $this->httpClient->queueResponse(201, json_encode([
            'rrset' => [
                'id' => 'api/A',
                'name' => 'api',
                'type' => 'A',
                'ttl' => 300,
                'records' => [['value' => '1.2.3.4']],
            ],
        ], JSON_THROW_ON_ERROR));

        $record = new DnsRecord('api', 'A', '1.2.3.4', 300);
        $result = $this->provider->createRecord('example.com', $record);

        self::assertSame('1.2.3.4', $result->target);

        // Verify DELETE was called
        $history = $this->httpClient->getHistory();
        $methods = array_column($history, 'method');
        self::assertContains('DELETE', $methods);
    }

    public function testUpdateRecord(): void
    {
        $this->queueZoneResponse();

        $this->httpClient->queueResponse(200, json_encode([
            'rrset' => [
                'id' => 'api/A',
                'name' => 'api',
                'type' => 'A',
                'ttl' => 600,
                'records' => [['value' => '5.6.7.8']],
            ],
        ], JSON_THROW_ON_ERROR));

        $record = new DnsRecord('api', 'A', '5.6.7.8', 600, 'api/A');
        $result = $this->provider->updateRecord('example.com', $record);

        self::assertSame('api/A', $result->id);
        self::assertSame('5.6.7.8', $result->target);
        self::assertSame(600, $result->ttl);

        $lastRequest = $this->httpClient->getLastRequest();
        self::assertSame('PUT', $lastRequest['method']);
    }

    public function testUpdateRecordWithoutIdThrows(): void
    {
        $record = new DnsRecord('api', 'A', '1.2.3.4', 300, '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot update DNS record without ID');

        $this->provider->updateRecord('example.com', $record);
    }

    public function testDeleteRecord(): void
    {
        $this->queueZoneResponse();
        $this->httpClient->queueResponse(204, '');

        $this->provider->deleteRecord('example.com', 'api/A');

        $lastRequest = $this->httpClient->getLastRequest();
        self::assertSame('DELETE', $lastRequest['method']);
        self::assertStringContainsString('rrsets/api/A', $lastRequest['url']);
    }

    public function testListRecords(): void
    {
        $this->queueZoneResponse();

        $this->httpClient->queueResponse(200, json_encode([
            'rrsets' => [
                [
                    'id' => 'api/A',
                    'name' => 'api',
                    'type' => 'A',
                    'ttl' => 300,
                    'records' => [['value' => '1.2.3.4']],
                ],
                [
                    'id' => 'portal/A',
                    'name' => 'portal',
                    'type' => 'A',
                    'ttl' => 300,
                    'records' => [['value' => '1.2.3.4']],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $records = $this->provider->listRecords('example.com');

        self::assertCount(2, $records);
        self::assertSame('api', $records[0]->subdomain);
        self::assertSame('portal', $records[1]->subdomain);
    }

    public function testResolveZoneIdCachesResult(): void
    {
        // First call resolves zone
        $this->queueZoneResponse();
        $this->httpClient->queueResponse(200, json_encode(['rrsets' => []], JSON_THROW_ON_ERROR));

        $this->provider->listRecords('example.com');

        // Second call should NOT need another zone response
        $this->httpClient->queueResponse(200, json_encode(['rrsets' => []], JSON_THROW_ON_ERROR));

        $records = $this->provider->listRecords('example.com');

        self::assertSame([], $records);
        // 3 requests total: GET zones, GET rrsets, GET rrsets (no second zone lookup)
        self::assertCount(3, $this->httpClient->getHistory());
    }

    public function testResolveZoneIdThrowsWhenNotFound(): void
    {
        $this->httpClient->queueResponse(200, json_encode(['zones' => []], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DNS zone not found: unknown.com');

        $this->provider->listRecords('unknown.com');
    }

    private function queueZoneResponse(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'zones' => [
                ['id' => 42, 'name' => 'example.com'],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function queueEmptyRrsets(): void
    {
        $this->httpClient->queueResponse(200, json_encode(['rrsets' => []], JSON_THROW_ON_ERROR));
    }
}
