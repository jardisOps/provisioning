<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Provider\Hetzner;

use JardisOps\Provisioning\Provider\Hetzner\HetznerApiClient;

/**
 * Creates a HetznerApiClient wired to a MockHttpClient.
 */
final class HetznerTestHelper
{
    public static function createApiClient(MockHttpClient $httpClient): HetznerApiClient
    {
        return new HetznerApiClient($httpClient, 'test-token');
    }
}
