<?php

declare(strict_types=1);

namespace JardisOps\Provisioning\Service\Http;

use RuntimeException;

/**
 * Lightweight HTTP client based on ext-curl.
 */
class HttpClient
{
    private int $timeout;

    public function __construct(int $timeout = 30)
    {
        $this->timeout = $timeout;
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): Response
    {
        return $this->request('GET', $url, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, array $headers = [], string $body = ''): Response
    {
        return $this->request('POST', $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    public function delete(string $url, array $headers = []): Response
    {
        return $this->request('DELETE', $url, $headers);
    }

    /**
     * @param non-empty-string $method
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): Response
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawResponse = curl_exec($ch);
        if (!is_string($rawResponse)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = $this->parseHeaders(substr($rawResponse, 0, $headerSize));
        $responseBody = substr($rawResponse, $headerSize);

        return new Response($statusCode, $responseBody, $responseHeaders);
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        foreach (explode("\r\n", $headerBlock) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        return $headers;
    }
}
