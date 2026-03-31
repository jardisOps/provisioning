<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Tests\Unit\Service\Http;

use JardisOps\Provisioning\Service\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testProperties(): void
    {
        $response = new Response(200, '{"ok":true}', ['Content-Type' => 'application/json']);

        self::assertSame(200, $response->statusCode);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame('application/json', $response->headers['Content-Type']);
    }

    public function testJsonDecode(): void
    {
        $response = new Response(200, '{"name":"test","count":3}');

        $data = $response->json();

        self::assertSame('test', $data['name']);
        self::assertSame(3, $data['count']);
    }

    public function testJsonEmptyBody(): void
    {
        $response = new Response(204, '');

        self::assertSame([], $response->json());
    }

    public function testJsonNestedObject(): void
    {
        $response = new Response(200, '{"server":{"id":123,"name":"test"}}');

        $data = $response->json();

        self::assertIsArray($data['server']);
        self::assertSame(123, $data['server']['id']);
    }

    public function testJsonInvalidThrows(): void
    {
        $response = new Response(200, '{invalid}');

        $this->expectException(\JsonException::class);
        $response->json();
    }

    public function testDefaultEmptyHeaders(): void
    {
        $response = new Response(200, 'body');

        self::assertSame([], $response->headers);
    }
}
