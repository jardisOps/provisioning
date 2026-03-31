<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Service\Http;

/**
 * HTTP response value object.
 */
final readonly class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
