<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Provider\Inwx;

use JardisOps\Provisioning\Service\Http\HttpClient;
use RuntimeException;

/**
 * Low-level XML-RPC client for the INWX DomRobot API.
 */
class InwxApiClient
{
    private const API_URL = 'https://api.domrobot.com/xmlrpc/';

    private ?string $sessionCookie = null;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    public function login(): void
    {
        $response = $this->call('account.login', [
            'user' => $this->username,
            'pass' => $this->password,
        ]);

        if (($response['code'] ?? 0) !== 1000) {
            throw new RuntimeException('INWX login failed: ' . ($response['msg'] ?? 'unknown error'));
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = []): array
    {
        /** @var string $xml */
        $xml = xmlrpc_encode_request($method, $params, ['encoding' => 'UTF-8']);

        $headers = ['Content-Type' => 'text/xml'];
        if ($this->sessionCookie !== null) {
            $headers['Cookie'] = $this->sessionCookie;
        }

        $response = $this->httpClient->post(self::API_URL, $headers, $xml);

        $this->extractSessionCookie($response->headers);

        $decoded = xmlrpc_decode($response->body, 'UTF-8');
        if (!is_array($decoded)) {
            throw new RuntimeException('Failed to decode INWX XML-RPC response');
        }

        /** @var array<string, mixed> $resData */
        $resData = $decoded['resData'] ?? [];

        return [
            'code' => $decoded['code'] ?? 0,
            'msg' => $decoded['msg'] ?? '',
            'resData' => $resData,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function extractSessionCookie(array $headers): void
    {
        $cookie = $headers['Set-Cookie'] ?? $headers['set-cookie'] ?? null;
        if ($cookie !== null) {
            $this->sessionCookie = $cookie;
        }
    }
}
