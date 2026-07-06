<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Support;

use Psr\Log\LoggerInterface;
use Spora\Plugins\Zernio\Support\Exceptions\ZernioApiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

/**
 * Thin, authenticated wrapper over Symfony's HttpClient for the Zernio REST API.
 *
 * - Adds `Authorization: Bearer <api_key>` and JSON headers to every request.
 * - Joins the configured base URL with the endpoint path.
 * - Retries twice on HTTP 429 / 5xx with a short backoff.
 * - Redacts the API key from all log entries.
 * - Decodes JSON responses to an array (empty body — e.g. a 204 from DELETE —
 *   decodes to []), and raises {@see ZernioApiException} on transport failure,
 *   HTTP >= 400, or a non-JSON body.
 *
 * Credentials/host/timeout are passed per call via {@see ZernioConfig} so the
 * client itself is stateless and shared across every Zernio tool via DI.
 */
final class ZernioClient
{
    private const RETRYABLE_HTTP_CODES = [429, 500, 502, 503, 504];
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query, ZernioConfig $config): array
    {
        return $this->request('GET', $path, $query === [] ? [] : ['query' => $query], $config);
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, ZernioConfig $config): array
    {
        return $this->request('POST', $path, ['json' => $body], $config);
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body, ZernioConfig $config): array
    {
        return $this->request('PUT', $path, ['json' => $body], $config);
    }

    /**
     * @param  array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function delete(string $path, array $query, ZernioConfig $config): array
    {
        return $this->request('DELETE', $path, $query === [] ? [] : ['query' => $query], $config);
    }

    /**
     * @param  array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options, ZernioConfig $config): array
    {
        $url = rtrim($config->baseUrl, '/') . '/' . ltrim($path, '/');
        $requestOptions = array_merge($options, [
            'headers' => [
                'Authorization' => 'Bearer ' . $config->apiKey,
                'Accept'        => 'application/json',
            ],
            'timeout' => $config->timeout,
        ]);

        $attempt = 0;
        while (true) {
            $attempt++;
            $this->logRequest($method, $url, $attempt, $config->timeout);

            try {
                $response = $this->httpClient->request($method, $url, $requestOptions);
                $status   = $response->getStatusCode();

                if ($this->isRetryable($status, $attempt)) {
                    usleep($this->backoffMicroseconds($attempt));
                    continue;
                }

                return $this->decode($response, $status, $url);
            } catch (TransportExceptionInterface $e) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep($this->backoffMicroseconds($attempt));
                    continue;
                }
                throw $this->transportFailure($url, $e);
            }
        }
    }

    private function logRequest(string $method, string $url, int $attempt, int $timeout): void
    {
        // API key intentionally omitted — never log credentials.
        $this->logger?->debug('ZernioClient: request', [
            'method'  => $method,
            'url'     => $url,
            'attempt' => $attempt,
            'timeout' => $timeout,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, int $status, string $url): array
    {
        $content = $response->getContent(false);

        if ($status >= 400) {
            $this->logger?->error('ZernioClient: HTTP error', [
                'url'    => $url,
                'status' => $status,
                'body'   => $this->truncate($content),
            ]);
            throw new ZernioApiException("Zernio API request failed with HTTP {$status}: " . $this->truncate($content), $status);
        }

        if (trim($content) === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new ZernioApiException('Zernio API returned a non-JSON response: ' . $e->getMessage(), $status);
        }

        return is_array($decoded) ? $decoded : ['data' => $decoded];
    }

    private function transportFailure(string $url, TransportExceptionInterface $e): ZernioApiException
    {
        $this->logger?->error('ZernioClient: transport error', ['url' => $url, 'error' => $e->getMessage()]);
        return new ZernioApiException('Zernio API request failed: ' . $e->getMessage());
    }

    private function isRetryable(int $status, int $attempt): bool
    {
        return $attempt < self::MAX_ATTEMPTS && in_array($status, self::RETRYABLE_HTTP_CODES, true);
    }

    private function backoffMicroseconds(int $attempt): int
    {
        return match ($attempt) {
            1 => 250_000,
            2 => 750_000,
            default => 0,
        };
    }

    private function truncate(string $content, int $maxChars = 500): string
    {
        return mb_strlen($content) > $maxChars
            ? mb_substr($content, 0, $maxChars) . '…[truncated]'
            : $content;
    }
}
