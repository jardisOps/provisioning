<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Hetzner;

use JardisOps\Provisioning\Provider\Hetzner\HetznerCertificateProvider;
use JardisOps\Provisioning\Support\Data\Certificate;
use PHPUnit\Framework\TestCase;

final class HetznerCertificateProviderTest extends TestCase
{
    private MockHttpClient $httpClient;
    private HetznerCertificateProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new MockHttpClient();
        $api = HetznerTestHelper::createApiClient($this->httpClient);
        $this->provider = new HetznerCertificateProvider($api);
    }

    public function testCreateManagedCertificateAlreadyReady(): void
    {
        $this->httpClient->queueResponse(201, json_encode([
            'certificate' => [
                'id' => 100,
                'name' => 'test-cert',
                'type' => 'managed',
                'domain_names' => ['api.example.com'],
                'status' => [
                    'issuance' => 'completed',
                    'renewal' => 'unavailable',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $cert = new Certificate('test-cert', ['api.example.com']);
        $result = $this->provider->createManagedCertificate($cert);

        self::assertSame('100', $result->id);
        self::assertSame('test-cert', $result->name);
        self::assertSame(['api.example.com'], $result->domainNames);
        self::assertTrue($result->isReady());
        self::assertSame('completed', $result->issuanceStatus);
    }

    public function testGetCertificate(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'certificate' => [
                'id' => 100,
                'name' => 'test-cert',
                'type' => 'managed',
                'domain_names' => ['api.example.com'],
                'status' => [
                    'issuance' => 'completed',
                    'renewal' => 'scheduled',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->provider->getCertificate('100');

        self::assertSame('100', $result->id);
        self::assertSame('scheduled', $result->renewalStatus);
    }

    public function testDeleteCertificate(): void
    {
        $this->httpClient->queueResponse(204, '');

        $this->provider->deleteCertificate('100');

        $lastRequest = $this->httpClient->getLastRequest();
        self::assertSame('DELETE', $lastRequest['method']);
        self::assertStringContainsString('/certificates/100', $lastRequest['url']);
    }

    public function testFindExistingCertificateMatchesSortedDomains(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'certificates' => [
                [
                    'id' => 50,
                    'name' => 'old-cert',
                    'type' => 'managed',
                    'domain_names' => ['portal.example.com', 'api.example.com'],
                    'status' => ['issuance' => 'completed', 'renewal' => 'unavailable'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        // Search with different order — should still match after sort
        $result = $this->provider->findExistingCertificate(['api.example.com', 'portal.example.com']);

        self::assertNotNull($result);
        self::assertSame('50', $result->id);
    }

    public function testFindExistingCertificateReturnsNullWhenNoMatch(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'certificates' => [
                [
                    'id' => 50,
                    'name' => 'other-cert',
                    'type' => 'managed',
                    'domain_names' => ['other.example.com'],
                    'status' => ['issuance' => 'completed', 'renewal' => 'unavailable'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->provider->findExistingCertificate(['api.example.com']);

        self::assertNull($result);
    }

    public function testFindExistingCertificateSkipsNonManaged(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'certificates' => [
                [
                    'id' => 50,
                    'name' => 'uploaded-cert',
                    'type' => 'uploaded',
                    'domain_names' => ['api.example.com'],
                    'status' => ['issuance' => 'completed', 'renewal' => 'unavailable'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->provider->findExistingCertificate(['api.example.com']);

        self::assertNull($result);
    }

    public function testFindExistingCertificateSkipsPending(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'certificates' => [
                [
                    'id' => 50,
                    'name' => 'pending-cert',
                    'type' => 'managed',
                    'domain_names' => ['api.example.com'],
                    'status' => ['issuance' => 'pending', 'renewal' => 'unavailable'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $result = $this->provider->findExistingCertificate(['api.example.com']);

        self::assertNull($result);
    }

    public function testFindExistingCertificateEmptyList(): void
    {
        $this->httpClient->queueResponse(200, json_encode([
            'certificates' => [],
        ], JSON_THROW_ON_ERROR));

        $result = $this->provider->findExistingCertificate(['api.example.com']);

        self::assertNull($result);
    }
}
