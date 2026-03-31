<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Inwx;

use JardisOps\Provisioning\Support\Data\DnsRecord;
use JardisOps\Provisioning\Provider\Inwx\InwxDnsProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InwxDnsProviderTest extends TestCase
{
    private MockInwxApiClient $api;
    private InwxDnsProvider $provider;

    protected function setUp(): void
    {
        $this->api = new MockInwxApiClient();
        $this->provider = new InwxDnsProvider($this->api);
    }

    public function testCreateRecord(): void
    {
        $this->api->queueResponse(1000, 'OK', ['id' => 88881]);

        $record = new DnsRecord('api', 'A', '49.12.1.1', 300);
        $result = $this->provider->createRecord('example.com', $record);

        self::assertSame('88881', $result->id);
        self::assertSame('api', $result->subdomain);
        self::assertSame('49.12.1.1', $result->target);

        $call = $this->api->getLastCall();
        self::assertSame('nameserver.createRecord', $call['method']);
        self::assertSame('api.example.com', $call['params']['name']);
        self::assertSame('example.com', $call['params']['domain']);
    }

    public function testUpdateRecord(): void
    {
        $this->api->queueResponse(1000, 'OK');

        $record = new DnsRecord('api', 'A', '49.12.2.2', 300, '88881');
        $result = $this->provider->updateRecord('example.com', $record);

        self::assertSame('88881', $result->id);
        self::assertSame('49.12.2.2', $result->target);

        $call = $this->api->getLastCall();
        self::assertSame('nameserver.updateRecord', $call['method']);
        self::assertSame(88881, $call['params']['id']);
    }

    public function testUpdateRecordWithoutIdThrows(): void
    {
        $record = new DnsRecord('api', 'A', '49.12.2.2', 300);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without ID');
        $this->provider->updateRecord('example.com', $record);
    }

    public function testDeleteRecord(): void
    {
        $this->api->queueResponse(1000, 'OK');

        $this->provider->deleteRecord('example.com', '88881');

        $call = $this->api->getLastCall();
        self::assertSame('nameserver.deleteRecord', $call['method']);
        self::assertSame(88881, $call['params']['id']);
    }

    public function testListRecords(): void
    {
        $this->api->queueResponse(1000, 'OK', [
            'record' => [
                ['id' => 88881, 'name' => 'api.example.com', 'type' => 'A', 'content' => '49.12.1.1', 'ttl' => 300],
                ['id' => 88882, 'name' => 'portal.example.com', 'type' => 'A', 'content' => '49.12.1.1', 'ttl' => 300],
            ],
        ]);

        $records = $this->provider->listRecords('example.com');

        self::assertCount(2, $records);
        self::assertSame('api', $records[0]->subdomain);
        self::assertSame('88881', $records[0]->id);
        self::assertSame('portal', $records[1]->subdomain);
    }

    public function testListRecordsEmpty(): void
    {
        $this->api->queueResponse(1000, 'OK', []);

        $records = $this->provider->listRecords('example.com');

        self::assertSame([], $records);
    }

    public function testApiErrorThrows(): void
    {
        $this->api->queueResponse(2302, 'Object does not exist');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2302');
        $this->provider->deleteRecord('example.com', '99999');
    }
}
