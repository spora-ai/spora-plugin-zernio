<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Tools;

use Psr\Log\LoggerInterface;
use Spora\Plugins\Zernio\Support\Exceptions\ZernioApiException;
use Spora\Plugins\Zernio\Support\ZernioClient;
use Spora\Plugins\Zernio\Support\ZernioConfig;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Shared base for every Zernio tool. Centralises the cross-cutting concerns so
 * the concrete tools only describe their operations and format responses:
 *
 *   - constructor wiring (config service, HTTP client, logger);
 *   - credential/host/timeout resolution into a {@see ZernioConfig};
 *   - the standard exception → {@see ToolResult} guard so a tool never throws
 *     into the agent loop.
 *
 * Credentials resolve from the tool's `api_key` setting, falling back to the
 * `ZERNIO_API_KEY` environment variable — so a self-hosted operator can set a
 * single key for all four Zernio tools instead of configuring each one.
 */
abstract class AbstractZernioTool extends AbstractTool
{
    protected const DEFAULT_BASE_URL = 'https://zernio.com/api/v1';

    public function __construct(
        protected readonly ToolConfigService $configService,
        protected readonly ZernioClient $client,
        protected readonly ?LoggerInterface $logger = null,
    ) {}

    protected function resolveConfig(int $agentId, ?int $userId): ?ZernioConfig
    {
        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);

        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        if ($apiKey === '') {
            $envKey = getenv('ZERNIO_API_KEY');
            $apiKey = is_string($envKey) ? trim($envKey) : '';
        }
        if ($apiKey === '') {
            return null;
        }

        $baseUrl = trim((string) ($settings['base_url'] ?? ''));
        if ($baseUrl === '' || !$this->isValidBaseUrl($baseUrl)) {
            if ($baseUrl !== '') {
                // Never send the bearer token to a malformed/unexpected host.
                $this->logger?->warning('Zernio: ignoring invalid base_url, using default', ['base_url' => $baseUrl]);
            }
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        return new ZernioConfig($apiKey, $baseUrl, $this->effectiveTimeout($settings));
    }

    /**
     * A base URL is only accepted if it is a well-formed http(s) URL with a
     * host. Anything else falls back to the default so a misconfigured setting
     * can't redirect the API key to an unintended destination.
     */
    private function isValidBaseUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = (string) parse_url($url, PHP_URL_HOST);

        return $host !== '' && in_array($scheme, ['http', 'https'], true);
    }

    protected function missingCredentialResult(): ToolResult
    {
        return new ToolResult(
            false,
            'Zernio API key is not configured. Set it in the tool settings or the ZERNIO_API_KEY environment variable.',
        );
    }

    /**
     * Run the operation callable with the standard exception handling so a
     * failed API call becomes a ToolResult rather than an uncaught throw.
     *
     * @param callable(): ToolResult $operation
     */
    protected function guard(callable $operation): ToolResult
    {
        try {
            return $operation();
        } catch (ZernioApiException $e) {
            $this->logger?->error('Zernio tool error', ['exception' => $e]);
            return new ToolResult(false, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger?->error('Zernio tool unexpected error', ['exception' => $e]);
            return new ToolResult(false, 'Zernio tool error: ' . $e->getMessage());
        }
    }

    /**
     * Per-tool `http_timeout` setting → `SPORA_TOOL_HTTP_TIMEOUT` env → 30s,
     * mirroring the cascade used by spora-core's ReadUrlTool.
     *
     * @param array<string, mixed> $settings
     */
    private function effectiveTimeout(array $settings): int
    {
        if (isset($settings['http_timeout']) && (int) $settings['http_timeout'] > 0) {
            return (int) $settings['http_timeout'];
        }
        $envTimeout = (int) ($_ENV['SPORA_TOOL_HTTP_TIMEOUT'] ?? getenv('SPORA_TOOL_HTTP_TIMEOUT') ?: 0);
        return $envTimeout > 0 ? $envTimeout : 30;
    }

    /**
     * Pull the list out of a Zernio response envelope, trying each candidate
     * key in order (e.g. "accounts" / "data"). Falls back to treating the
     * whole response as the list so a flat array is still rendered.
     *
     * @param  array<string, mixed> $response
     * @return list<mixed>
     */
    protected function listKey(array $response, string ...$keys): array
    {
        foreach ($keys as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return array_values($response[$key]);
            }
        }
        return array_values($response);
    }
}
