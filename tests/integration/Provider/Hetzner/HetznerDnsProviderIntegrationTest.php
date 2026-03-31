<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Integration\Provider\Hetzner;

use JardisOps\Provisioning\Provider\Hetzner\HetznerApiClient;
use JardisOps\Provisioning\Provider\Hetzner\HetznerDnsProvider;
use JardisOps\Provisioning\Service\Http\HttpClient;
use JardisOps\Provisioning\Support\Data\DnsRecord;
use PHPUnit\Framework\TestCase;

/**
 * Integration test against live Hetzner Cloud DNS API.
 *
 * Requires HETZNER_DNS_TOKEN and DNS_ZONE in tests/fixtures/hetzner/.env.local
 */
final class HetznerDnsProviderIntegrationTest extends TestCase
{
    use HetznerTestConfig;

    private ?HetznerDnsProvider $provider = null;
    private string $zone = '';
    private ?string $createdRecordId = null;

    protected function setUp(): void
    {
        $this->loadHetznerConfig();
        $token = $this->requireDnsToken();
        $this->zone = (string) ($this->config['DNS_ZONE'] ?? '');

        if ($this->zone === '') {
            self::markTestSkipped('DNS_ZONE not set — add it to tests/fixtures/hetzner/.env.local');
        }

        $api = new HetznerApiClient(new HttpClient(), $token);
        $this->provider = new HetznerDnsProvider($api);
    }

    protected function tearDown(): void
    {
        if ($this->createdRecordId !== null && $this->provider !== null) {
            try {
                $this->provider->deleteRecord($this->zone, $this->createdRecordId);
            } catch (\Throwable) {
                // Best effort cleanup
            }
        }
    }

    public function testCreateListAndDeleteRecord(): void
    {
        $provider = $this->provider;
        self::assertNotNull($provider);

        $prefix = (string) ($this->config['TEST_RESOURCE_PREFIX'] ?? 'prov-test');

        // 1. Record anlegen
        $record = new DnsRecord("{$prefix}-dns", 'A', '1.2.3.4', 300);
        $created = $provider->createRecord($this->zone, $record);

        $this->createdRecordId = $created->id;

        self::assertNotEmpty($created->id);
        self::assertSame("{$prefix}-dns", $created->subdomain);
        self::assertSame('A', $created->type);
        self::assertSame('1.2.3.4', $created->target);

        echo "  Created DNS record: {$created->subdomain}.{$this->zone} -> {$created->target} (ID: {$created->id})\n";

        // 2. Records auflisten und unseren finden
        $records = $provider->listRecords($this->zone);
        $found = array_filter($records, static fn(DnsRecord $r) => $r->subdomain === "{$prefix}-dns");
        self::assertNotEmpty($found, 'Created record should appear in list');

        echo "  Listed records: found " . count($records) . " total, our record present\n";

        // 3. Warten (mind. 30s damit Record propagiert)
        echo "  Waiting 30s before delete...\n";
        sleep(30);

        // 4. Record loeschen
        $provider->deleteRecord($this->zone, $created->id);
        $this->createdRecordId = null;

        echo "  Deleted DNS record: {$created->id}\n";

        // 5. Verifizieren dass er weg ist
        $records = $provider->listRecords($this->zone);
        $found = array_filter($records, static fn(DnsRecord $r) => $r->subdomain === "{$prefix}-dns");
        self::assertEmpty($found, 'Deleted record should no longer appear in list');
    }
}
