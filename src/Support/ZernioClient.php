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
 * - Single-shot: every failure surfaces to the caller so the LLM can decide
 *   whether to retry. Earlier versions retried twice on 429/5xx — that was
 *   dangerous for this plugin: a social-media POST that retried on a transport
 *   timeout after the server had already committed the publish would double-post.
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
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @param  array<string, scalar|null> $query
     * @param  array<string, string>     $headers Extra headers to merge in (e.g. X-Request-Id for idempotency).
     * @return array<string, mixed>
     */
    public function get(string $path, array $query, ZernioConfig $config, array $headers = []): array
    {
        return $this->request('GET', $path, $query === [] ? [] : ['query' => $query], $config, $headers);
    }

    /**
     * @param  array<string, mixed>      $body
     * @param  array<string, string>     $headers Extra headers to merge in (e.g. X-Request-Id for idempotency).
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, ZernioConfig $config, array $headers = []): array
    {
        return $this->request('POST', $path, ['json' => $body], $config, $headers);
    }

    /**
     * POST a raw string body (e.g. text/csv for bulk-upload) with an explicit
     * Content-Type header. Use this instead of {@see self::post} when the
     * endpoint consumes a non-JSON body.
     *
     * @return array<string, mixed>
     */
    public function postRaw(string $path, string $body, string $contentType, ZernioConfig $config, array $headers = []): array
    {
        return $this->request(
            'POST',
            $path,
            ['body' => $body, 'headers' => ['Content-Type' => $contentType]],
            $config,
            $headers,
        );
    }

    /**
     * @param  array<string, mixed>      $body
     * @param  array<string, string>     $headers Extra headers to merge in.
     * @return array<string, mixed>
     */
    public function put(string $path, array $body, ZernioConfig $config, array $headers = []): array
    {
        return $this->request('PUT', $path, ['json' => $body], $config, $headers);
    }

    /**
     * @param  array<string, mixed>      $body
     * @param  array<string, string>     $headers Extra headers to merge in.
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body, ZernioConfig $config, array $headers = []): array
    {
        return $this->request('PATCH', $path, ['json' => $body], $config, $headers);
    }

    /**
     * @param  array<string, scalar|null> $query
     * @param  array<string, string>      $headers Extra headers to merge in.
     * @return array<string, mixed>
     */
    public function delete(string $path, array $query, ZernioConfig $config, array $headers = []): array
    {
        return $this->request('DELETE', $path, $query === [] ? [] : ['query' => $query], $config, $headers);
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string> $headers
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options, ZernioConfig $config, array $headers = []): array
    {
        $url = rtrim($config->baseUrl, '/') . '/' . ltrim($path, '/');
        $optionHeaders = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        unset($options['headers']);
        $requestOptions = $options + [
            'headers' => array_merge([
                'Authorization' => 'Bearer ' . $config->apiKey,
                'Accept'        => 'application/json',
            ], $optionHeaders, $headers),
            'timeout' => $config->timeout,
        ];

        $this->logRequest($method, $url, $config->timeout);

        try {
            $response = $this->httpClient->request($method, $url, $requestOptions);
            return $this->decode($response, $response->getStatusCode(), $url);
        } catch (TransportExceptionInterface $e) {
            throw $this->transportFailure($url, $e);
        }
    }

    private function logRequest(string $method, string $url, int $timeout): void
    {
        // API key intentionally omitted — never log credentials.
        $this->logger?->debug('ZernioClient: request', [
            'method'  => $method,
            'url'     => $url,
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

        if (!is_array($decoded)) {
            return ['data' => $decoded];
        }
        return $decoded;
    }

    private function transportFailure(string $url, TransportExceptionInterface $e): ZernioApiException
    {
        $this->logger?->error('ZernioClient: transport error', ['url' => $url, 'error' => $e->getMessage()]);
        return new ZernioApiException('Zernio API request failed: ' . $e->getMessage());
    }

    private function truncate(string $content, int $maxChars = 500): string
    {
        return mb_strlen($content) > $maxChars
            ? mb_substr($content, 0, $maxChars) . '…[truncated]'
            : $content;
    }
}
